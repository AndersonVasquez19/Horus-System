<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Usuarios y roles por la API del panel (`/api/panel/v1/compartido/usuarios|roles`). La lógica es la de
 * `GestionUsuarios` y `GestionRoles` (la misma del Blade), así que aquí se comprueba el contrato del panel y que las
 * garantías de seguridad llegan intactas: escalada de privilegios, último administrador, sesiones y auditoría.
 */
final class UsuariosRolesPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)->json($metodo, '/api/panel/v1/compartido/'.$ruta, $datos, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function admin(string $email = 'admin@example.test'): User
    {
        return $this->usuarioConRol('admin', $email);
    }

    /**
     * Gestor sin rol: solo los permisos indicados (más la entrada a un panel).
     *
     * @param  list<string>  $permisos
     */
    private function gestor(array $permisos, string $email = 'gestor@example.test'): User
    {
        $this->admin('base-admin@example.test');
        $user = User::factory()->create(['email' => $email]);
        $user->givePermissionTo(['panel.academico.acceder', ...$permisos]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function datosUsuario(string $email = 'nuevo@example.test', array $extra = []): array
    {
        return [
            'name' => 'Persona Nueva',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            ...$extra,
        ];
    }

    // ---------------------------------------------------------------- acceso

    public function test_exige_sesion_y_los_permisos_de_cada_accion(): void
    {
        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson('/api/panel/v1/compartido/usuarios')->assertUnauthorized();
        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson('/api/panel/v1/compartido/roles')->assertUnauthorized();

        $this->admin();
        $sinPermisos = $this->gestor([]);
        $objetivo = User::factory()->create();
        $rol = Role::findByName('coordinador');

        $this->como($sinPermisos, 'GET', 'usuarios')->assertForbidden();
        $this->como($sinPermisos, 'GET', "usuarios/{$objetivo->id}")->assertForbidden();
        $this->como($sinPermisos, 'POST', 'usuarios', $this->datosUsuario())->assertForbidden();
        $this->como($sinPermisos, 'PUT', "usuarios/{$objetivo->id}", ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
        $this->como($sinPermisos, 'DELETE', "usuarios/{$objetivo->id}")->assertForbidden();
        $this->como($sinPermisos, 'GET', 'usuarios/roles-asignables')->assertForbidden();
        $this->como($sinPermisos, 'GET', 'roles')->assertForbidden();
        $this->como($sinPermisos, 'POST', 'roles', ['name' => 'nuevo'])->assertForbidden();
        $this->como($sinPermisos, 'PUT', "roles/{$rol->id}", ['name' => 'coordinador'])->assertForbidden();
        $this->como($sinPermisos, 'DELETE', "roles/{$rol->id}")->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@example.test']);
    }

    // ---------------------------------------------------------------- usuarios: lectura

    public function test_lista_busca_ordena_y_no_expone_secretos(): void
    {
        $admin = $this->admin();
        // Nombre fijo: uno aleatorio puede contener «ana» y colarse en la búsqueda.
        $admin->forceFill(['name' => 'Responsable del sistema'])->save();
        User::factory()->create(['name' => 'Ana Torres', 'email' => 'ana@example.test'])->assignRole('secretaria');
        User::factory()->create(['name' => 'Beto Ruiz', 'email' => 'beto@example.test']);

        $respuesta = $this->como($admin, 'GET', 'usuarios?busqueda=ana')->assertOk();
        $this->assertSame(['ana@example.test'], $respuesta->json('data.*.email'));
        $this->assertSame(['secretaria'], $respuesta->json('data.0.roles'));
        $respuesta->assertJsonPath('meta.filtros.busqueda', 'ana');

        $todos = $this->como($admin, 'GET', 'usuarios?sort=-email')->assertOk();
        $emails = $todos->json('data.*.email');
        $ordenados = $emails;
        rsort($ordenados);
        $this->assertSame($ordenados, $emails);

        $crudo = $todos->getContent();
        foreach (['password', 'remember_token', 'jwt_version'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $crudo);
        }
        $this->assertSame([
            'bloqueado_hasta', 'creado_en', 'debe_cambiar_clave', 'eliminado',
            'email', 'id', 'motivo_bloqueo', 'nombre', 'roles', 'suspendido', 'two_factor_habilitado', 'verificado',
        ], collect($todos->json('data.0'))->keys()->sort()->values()->all());
    }

    public function test_el_comodin_de_busqueda_no_amplia_resultados(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Carlos', 'email' => 'carlos@example.test']);

        $this->assertSame([], $this->como($admin, 'GET', 'usuarios?busqueda=%25')->assertOk()->json('data'));
    }

    public function test_muestra_un_usuario_y_responde_404_si_no_existe(): void
    {
        $admin = $this->admin();
        $otro = User::factory()->create(['email' => 'otro@example.test']);

        $this->como($admin, 'GET', "usuarios/{$otro->id}")->assertOk()->assertJsonPath('data.email', 'otro@example.test');
        $this->como($admin, 'GET', 'usuarios/999999')->assertNotFound();
    }

    public function test_roles_asignables_solo_requiere_gestionar_usuarios_no_ver_roles(): void
    {
        $this->admin();
        $creador = $this->gestor(['usuarios.crear']);

        $this->como($creador, 'GET', 'usuarios/roles-asignables')->assertOk()->assertExactJson(['data' => ['admin', 'coordinador', 'secretaria']]);
        $this->como($creador, 'GET', 'roles')->assertForbidden();
    }

    // ---------------------------------------------------------------- usuarios: altas

    public function test_el_administrador_crea_un_usuario_con_roles_y_queda_auditado_sin_la_clave(): void
    {
        $admin = $this->admin();

        $respuesta = $this->como($admin, 'POST', 'usuarios', $this->datosUsuario('nueva@example.test', ['roles' => ['secretaria']]))
            ->assertCreated()
            ->assertJsonPath('data.email', 'nueva@example.test')
            ->assertJsonPath('data.roles', ['secretaria'])
            ->assertJsonPath('data.verificado', true);

        $usuario = User::query()->findOrFail($respuesta->json('data.id'));
        $this->assertTrue(Hash::check(self::PASSWORD, $usuario->password));
        $this->assertTrue($usuario->hasRole('secretaria'));

        $fila = AuditLog::query()->where('action', 'user_created')->sole();
        $this->assertSame($admin->id, $fila->user_id);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($fila->changes, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('password', json_encode($fila->changes));
    }

    public function test_valida_correo_duplicado_clave_debil_sin_confirmar_y_rol_inexistente(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'repetido@example.test']);

        $this->como($admin, 'POST', 'usuarios', $this->datosUsuario('REPETIDO@example.test'))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->como($admin, 'POST', 'usuarios', $this->datosUsuario('a@example.test', ['password' => '123', 'password_confirmation' => '123']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->como($admin, 'POST', 'usuarios', $this->datosUsuario('b@example.test', ['password_confirmation' => 'otra']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->como($admin, 'POST', 'usuarios', $this->datosUsuario('c@example.test', ['roles' => ['inexistente']]))
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');

        $this->assertSame(0, User::query()->whereIn('email', ['a@example.test', 'b@example.test', 'c@example.test'])->count());
    }

    public function test_quien_no_es_administrador_no_puede_crear_un_administrador_ni_asignar_roles_sin_permiso(): void
    {
        $this->admin();
        $creador = $this->gestor(['usuarios.crear']);

        // Sin `usuarios.asignar-roles`: ni siquiera puede pedir roles.
        $this->como($creador, 'POST', 'usuarios', $this->datosUsuario('sinrol@example.test', ['roles' => ['secretaria']]))->assertForbidden();

        // Con `usuarios.asignar-roles`: tampoco puede conceder el rol `admin` (más privilegios que los propios).
        $creador->givePermissionTo('usuarios.asignar-roles');
        $this->como($creador, 'POST', 'usuarios', $this->datosUsuario('escalada@example.test', ['roles' => ['admin']]))->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sinrol@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'escalada@example.test']);
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('action', 'acceso_denegado')->count());
    }

    public function test_sin_roles_un_gestor_puede_crear_una_cuenta_sin_privilegios(): void
    {
        $this->admin();
        $creador = $this->gestor(['usuarios.crear']);

        $this->como($creador, 'POST', 'usuarios', $this->datosUsuario('simple@example.test'))->assertCreated()->assertJsonPath('data.roles', []);
    }

    // ---------------------------------------------------------------- usuarios: cambios

    public function test_actualiza_datos_y_cambiar_la_clave_revoca_tokens_y_audita_sin_ella(): void
    {
        $admin = $this->admin();
        $objetivo = User::factory()->create(['email' => 'cambio@example.test']);
        $antes = (int) $objetivo->jwt_version;

        $this->como($admin, 'PUT', "usuarios/{$objetivo->id}", [
            'name' => 'Nombre Nuevo', 'email' => 'cambio2@example.test',
            'password' => 'Otra-Clave-Segura-2027!', 'password_confirmation' => 'Otra-Clave-Segura-2027!',
        ])->assertOk()->assertJsonPath('data.nombre', 'Nombre Nuevo');

        $objetivo->refresh();
        $this->assertSame('cambio2@example.test', $objetivo->email);
        $this->assertTrue(Hash::check('Otra-Clave-Segura-2027!', $objetivo->password));
        $this->assertGreaterThan($antes, (int) $objetivo->jwt_version);

        $fila = AuditLog::query()->where('action', 'user_updated')->sole();
        $this->assertStringNotContainsString('Otra-Clave', json_encode($fila->changes));
    }

    public function test_sin_el_campo_roles_un_usuario_que_se_edita_a_si_mismo_no_pierde_los_suyos(): void
    {
        $this->admin();
        $coordinador = $this->usuarioConRol('coordinador', 'coord@example.test');
        $coordinador->givePermissionTo(['usuarios.editar', 'usuarios.asignar-roles']);

        $this->como($coordinador, 'PUT', "usuarios/{$coordinador->id}", ['name' => 'Coord Editado', 'email' => 'coord@example.test'])->assertOk();

        $this->assertTrue($coordinador->refresh()->hasRole('coordinador'));
        $this->assertSame('Coord Editado', $coordinador->name);
    }

    public function test_quien_no_es_administrador_no_puede_modificar_a_un_administrador(): void
    {
        $admin = $this->admin();
        $editor = $this->gestor(['usuarios.editar']);

        $this->como($editor, 'PUT', "usuarios/{$admin->id}", ['name' => 'Tomado', 'email' => 'tomado@example.test'])->assertForbidden();

        $this->assertSame('admin@example.test', $admin->refresh()->email);
        $fila = AuditLog::query()->where('action', 'acceso_denegado')->latest('id')->first();
        $this->assertNotNull($fila);
        $this->assertSame($editor->id, $fila->user_id);
    }

    public function test_el_ultimo_administrador_no_puede_quedarse_sin_el_rol(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'PUT', "usuarios/{$admin->id}", ['name' => 'Admin', 'email' => 'admin@example.test', 'roles' => []])->assertUnprocessable();

        $this->assertTrue($admin->refresh()->hasRole('admin'));
    }

    public function test_un_gestor_no_puede_darse_a_si_mismo_el_rol_admin(): void
    {
        $this->admin();
        $gestor = $this->gestor(['usuarios.editar', 'usuarios.asignar-roles']);

        $this->como($gestor, 'PUT', "usuarios/{$gestor->id}", ['name' => 'Gestor', 'email' => $gestor->email, 'roles' => ['admin']])->assertForbidden();

        $this->assertFalse($gestor->refresh()->hasRole('admin'));
    }

    // ---------------------------------------------------------------- usuarios: bajas

    public function test_elimina_un_usuario_y_lo_audita(): void
    {
        $admin = $this->admin();
        $objetivo = User::factory()->create(['email' => 'baja@example.test']);

        $this->como($admin, 'DELETE', "usuarios/{$objetivo->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $objetivo->id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'user_deleted')->count());
    }

    public function test_nadie_se_elimina_a_si_mismo_ni_se_borra_al_ultimo_administrador(): void
    {
        $admin = $this->admin();
        $this->como($admin, 'DELETE', "usuarios/{$admin->id}")->assertUnprocessable();

        $gestor = $this->gestor(['usuarios.eliminar']);
        $this->como($gestor, 'DELETE', "usuarios/{$admin->id}")->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    // ---------------------------------------------------------------- roles

    public function test_lista_roles_con_tipo_y_permisos_sin_los_historicos(): void
    {
        $admin = $this->admin();
        Role::findByName('coordinador')->givePermissionTo(Permission::findOrCreate('seguimientos.ver', 'web'));

        $respuesta = $this->como($admin, 'GET', 'roles')->assertOk();

        $porNombre = collect($respuesta->json('data'))->keyBy('nombre');
        $this->assertSame('principal', $porNombre['admin']['tipo']);
        $this->assertSame('base', $porNombre['coordinador']['tipo']);
        $this->assertNotContains('seguimientos.ver', $porNombre['coordinador']['permisos']);
        $this->assertNotContains('seguimientos.ver', $respuesta->json('meta.permisos'));
        $this->assertContains('usuarios.ver', $respuesta->json('meta.permisos'));
        $this->assertGreaterThanOrEqual(1, $porNombre['admin']['usuarios']);
    }

    public function test_crea_edita_y_elimina_un_rol_personalizado_con_auditoria(): void
    {
        $admin = $this->admin();

        $id = $this->como($admin, 'POST', 'roles', ['name' => 'apoyo', 'permissions' => ['cursos.ver']])
            ->assertCreated()->assertJsonPath('data.tipo', 'personalizado')->assertJsonPath('data.permisos', ['cursos.ver'])->json('data.id');

        $this->como($admin, 'PUT', "roles/{$id}", ['name' => 'apoyo2', 'permissions' => ['cursos.ver', 'estudiantes.ver']])
            ->assertOk()->assertJsonPath('data.nombre', 'apoyo2');

        $this->como($admin, 'DELETE', "roles/{$id}")->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $id]);
        foreach (['role_created', 'role_updated', 'role_deleted'] as $accion) {
            $this->assertSame(1, AuditLog::query()->where('action', $accion)->count(), $accion);
        }
    }

    public function test_valida_nombre_repetido_y_permiso_inexistente(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'POST', 'roles', ['name' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->como($admin, 'POST', 'roles', ['name' => 'otro', 'permissions' => ['no.existe']])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->assertDatabaseMissing('roles', ['name' => 'otro']);
    }

    public function test_el_rol_admin_y_los_roles_base_no_se_renombran_ni_se_eliminan(): void
    {
        $admin = $this->admin();
        $rolAdmin = Role::findByName('admin');
        $coordinador = Role::findByName('coordinador');
        $permisosAdmin = $rolAdmin->permissions()->pluck('name')->all();

        $this->como($admin, 'PUT', "roles/{$rolAdmin->id}", ['name' => 'jefes', 'permissions' => $permisosAdmin])->assertUnprocessable();
        $this->como($admin, 'PUT', "roles/{$coordinador->id}", ['name' => 'otro-nombre', 'permissions' => ['cursos.ver']])->assertUnprocessable();
        $this->como($admin, 'DELETE', "roles/{$rolAdmin->id}")->assertUnprocessable();
        $this->como($admin, 'DELETE', "roles/{$coordinador->id}")->assertUnprocessable();

        $this->assertDatabaseHas('roles', ['name' => 'admin']);
        $this->assertDatabaseHas('roles', ['name' => 'coordinador']);
    }

    public function test_el_rol_admin_no_puede_perder_los_permisos_de_administracion(): void
    {
        $admin = $this->admin();
        $rolAdmin = Role::findByName('admin');
        $antes = $rolAdmin->permissions()->pluck('name')->sort()->values()->all();

        $this->como($admin, 'PUT', "roles/{$rolAdmin->id}", ['name' => 'admin', 'permissions' => ['cursos.ver']])->assertUnprocessable();

        $this->assertSame($antes, $rolAdmin->refresh()->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_un_gestor_de_roles_no_puede_escalar_privilegios(): void
    {
        $this->admin();
        $gestor = $this->gestor(['roles.ver', 'roles.crear', 'roles.editar', 'roles.eliminar']);

        // No concede permisos que no posee (R4c) ni edita el rol admin.
        $this->como($gestor, 'POST', 'roles', ['name' => 'poderoso', 'permissions' => ['usuarios.asignar-roles']])->assertForbidden();
        $this->como($gestor, 'PUT', 'roles/'.Role::findByName('admin')->id, ['name' => 'admin', 'permissions' => ['cursos.ver']])->assertForbidden();
        $this->como($gestor, 'DELETE', 'roles/'.Role::findByName('admin')->id)->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'poderoso']);
        $this->assertGreaterThanOrEqual(3, AuditLog::query()->where('action', 'acceso_denegado')->count());
    }

    public function test_el_listado_de_usuarios_filtra_por_rol_y_estado_de_verificacion(): void
    {
        $admin = $this->admin();
        $coordinador = $this->usuarioConRol('coordinador', 'coord@example.test');
        $coordinador->forceFill(['email_verified_at' => null])->save();
        User::factory()->create(['email' => 'sinrol@example.test', 'email_verified_at' => now()]);

        $this->como($admin, 'GET', 'usuarios?rol=coordinador')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'coord@example.test');

        $this->como($admin, 'GET', 'usuarios?rol=sin_rol')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'sinrol@example.test');

        $this->como($admin, 'GET', 'usuarios?estado=no_verificado')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'coord@example.test');

        $this->como($admin, 'GET', 'usuarios?estado=verificado')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
