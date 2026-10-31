<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AccionAdministrativaDenegada;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminProtection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 21.1 (R1, R2, R3): protección de administradores y prevención de escalada en usuarios.
 *
 * SQLite en memoria NO reproduce `SELECT ... FOR UPDATE`: estas pruebas validan reglas, orden de
 * operaciones y atomicidad, no la concurrencia real de PostgreSQL (pendiente de autorización aparte).
 */
class ProteccionAdministradoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---- 1. Administrador modifica a otro administrador --------------------------------------

    public function test_an_admin_can_modify_another_admin(): void
    {
        $uno = $this->makeUser('admin');
        $dos = $this->makeUser('admin');

        $this->actingAs($uno)
            ->put(route('admin.usuarios.update', $dos), ['name' => 'Renombrado', 'email' => $dos->email, 'roles' => ['admin']])
            ->assertRedirect(route('admin.usuarios.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renombrado', $dos->fresh()->name);
        $log = AuditLog::where('action', 'user_updated')->latest('id')->firstOrFail();
        $this->assertSame($uno->id, $log->user_id, 'El actor queda registrado de forma explícita.');
        $this->assertSame(0, AuditLog::where('action', 'acceso_denegado')->count());
    }

    // ---- 2. No administrador intenta modificar a un administrador ----------------------------

    public function test_a_non_admin_cannot_modify_an_admin_and_the_denial_is_audited(): void
    {
        $admin = $this->makeUser('admin');
        $gestor = $this->gestor();
        $antes = $admin->only(['name', 'email', 'password']);

        $respuesta = $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $admin), [
                'name' => 'Tomado',
                'email' => 'tomado@example.com',
                'password' => 'NuevaClave12345',
                'password_confirmation' => 'NuevaClave12345',
            ])
            ->assertForbidden();

        $respuesta->assertDontSee('admin_protegido_edicion')->assertDontSee('tomado@example.com');
        $this->assertSame($antes, $admin->fresh()->only(['name', 'email', 'password']), 'Ningún cambio parcial.');

        // 16. La denegación sobrevive al rollback de la transacción.
        $fila = AuditLog::where('action', 'acceso_denegado')->sole();
        $this->assertSame($gestor->id, $fila->user_id);
        $this->assertSame(User::class, $fila->model_type);
        $this->assertSame($admin->id, (int) $fila->model_id);
        $this->assertSame('R1', $fila->changes['regla']);
        $this->assertSame('admin_protegido_edicion', $fila->changes['motivo']);
        $this->assertSame('usuario.actualizar', $fila->changes['operacion']);
        $this->assertTrue($fila->changes['contexto']['objetivo_es_admin']);
        $this->assertSame(0, AuditLog::where('action', 'user_updated')->count());
    }

    // ---- 3. Cuentas con más privilegios que el actor (R1+) -----------------------------------

    public function test_a_non_admin_cannot_manage_an_account_with_more_privileges(): void
    {
        $gestor = $this->gestor();
        Role::create(['name' => 'auditor', 'guard_name' => 'web'])->givePermissionTo('auditoria.ver');
        $superior = $this->makeUser('auditor');

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $superior), ['name' => 'Tomado', 'email' => $superior->email])
            ->assertForbidden();

        $this->assertNotSame('Tomado', $superior->fresh()->name);
        $fila = AuditLog::where('action', 'acceso_denegado')->sole();
        $this->assertSame('R1+', $fila->changes['regla']);
        $this->assertSame('cuenta_fuera_de_alcance', $fila->changes['motivo']);
        $this->assertSame(['auditoria.ver'], $fila->changes['contexto']['permisos_excedidos']);
    }

    public function test_a_non_admin_can_still_manage_accounts_within_their_scope(): void
    {
        $gestor = $this->gestor();
        $objetivo = $this->makeUser('secretaria');

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => 'Cambio válido', 'email' => $objetivo->email, 'roles' => ['secretaria']])
            ->assertRedirect(route('admin.usuarios.index'));

        $this->assertSame('Cambio válido', $objetivo->fresh()->name);
        $this->assertSame(0, AuditLog::where('action', 'acceso_denegado')->count());
    }

    // ---- 4. Usuario modifica su propio perfil (D-11) -----------------------------------------

    public function test_a_user_can_edit_their_own_profile_without_touching_their_roles(): void
    {
        $gestor = $this->gestor();
        $versionJwt = (int) $gestor->jwt_version;

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $gestor), [
                'name' => 'Mi nuevo nombre',
                'email' => 'mi-correo@example.com',
                'password' => 'ClaveNueva12345',
                'password_confirmation' => 'ClaveNueva12345',
            ])
            ->assertRedirect(route('admin.usuarios.index'))
            ->assertSessionHasNoErrors();

        $gestor->refresh();
        $this->assertSame('Mi nuevo nombre', $gestor->name);
        $this->assertSame('mi-correo@example.com', $gestor->email);
        $this->assertTrue(Hash::check('ClaveNueva12345', $gestor->password));
        $this->assertSame($versionJwt + 1, (int) $gestor->jwt_version, 'Cambiar la clave revoca los JWT.');
        $this->assertTrue($gestor->hasRole('coordinador'), 'Omitir `roles` no equivale a quitarlos.');
    }

    // ---- 5. Usuario intenta modificar sus propios roles (R2c) --------------------------------

    public function test_a_non_admin_cannot_change_their_own_roles(): void
    {
        $gestor = $this->gestor();

        foreach ([['coordinador', 'secretaria'], []] as $roles) {
            $this->actingAs($gestor)
                ->put(route('admin.usuarios.update', $gestor), ['name' => $gestor->name, 'email' => $gestor->email, 'roles' => $roles])
                ->assertForbidden();
        }

        $this->assertSame(['coordinador'], app(AdminProtection::class)->roleNamesOf($gestor));
        $this->assertSame(2, AuditLog::where('action', 'acceso_denegado')->where('changes->regla', 'R2c')->count());
    }

    // ---- 6. Asignación del rol admin (R2a) ---------------------------------------------------

    public function test_a_non_admin_cannot_assign_the_admin_role_to_others_or_to_themselves(): void
    {
        $gestor = $this->gestor();
        $objetivo = $this->makeUser('secretaria');

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['admin']])
            ->assertForbidden();
        $this->put(route('admin.usuarios.update', $gestor), ['name' => $gestor->name, 'email' => $gestor->email, 'roles' => ['coordinador', 'admin']])
            ->assertForbidden();
        $this->post(route('admin.usuarios.store'), [
            'name' => 'Nuevo admin', 'email' => 'nuevo-admin@example.com',
            'password' => 'Segura12345', 'password_confirmation' => 'Segura12345', 'roles' => ['admin'],
        ])->assertForbidden();

        $this->assertSame(['secretaria'], app(AdminProtection::class)->roleNamesOf($objetivo));
        $this->assertSame(['coordinador'], app(AdminProtection::class)->roleNamesOf($gestor));
        $this->assertDatabaseMissing('users', ['email' => 'nuevo-admin@example.com']);
        // Dos intentos sobre otras cuentas (actualizar y crear) y uno sobre sí mismo (R2c tiene prioridad).
        $this->assertSame(2, AuditLog::where('changes->motivo', 'rol_admin_reservado')->count());
        $this->assertSame(1, AuditLog::where('changes->motivo', 'autoasignacion_roles')->count());
    }

    // ---- 7. Roles con permisos superiores (R2b) ----------------------------------------------

    public function test_a_non_admin_cannot_assign_a_role_with_permissions_beyond_their_own(): void
    {
        $gestor = $this->gestor();
        $objetivo = $this->makeUser('secretaria');
        Role::create(['name' => 'auditor', 'guard_name' => 'web'])->givePermissionTo(['auditoria.ver', 'reportes.ver']);

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['secretaria', 'auditor']])
            ->assertForbidden();
        $this->post(route('admin.usuarios.store'), [
            'name' => 'Nuevo', 'email' => 'nuevo@example.com',
            'password' => 'Segura12345', 'password_confirmation' => 'Segura12345', 'roles' => ['auditor'],
        ])->assertForbidden();

        $this->assertSame(['secretaria'], app(AdminProtection::class)->roleNamesOf($objetivo));
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@example.com']);
        $fila = AuditLog::where('changes->motivo', 'rol_fuera_de_alcance')->latest('id')->firstOrFail();
        $this->assertSame(['auditoria.ver'], $fila->changes['contexto']['permisos_excedidos']);
    }

    // ---- 14. Combinaciones de roles ----------------------------------------------------------

    public function test_role_combinations_within_the_ceiling_are_allowed_and_cannot_be_used_to_escalate(): void
    {
        $gestor = $this->gestor();
        $objetivo = $this->makeUser('secretaria');
        Role::create(['name' => 'lector', 'guard_name' => 'web'])->givePermissionTo('cursos.ver');
        Role::create(['name' => 'auditor', 'guard_name' => 'web'])->givePermissionTo('auditoria.ver');

        // Dos roles, ambos dentro del techo del actor: permitido.
        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['secretaria', 'lector']])
            ->assertRedirect(route('admin.usuarios.index'));
        $this->assertEqualsCanonicalizing(['secretaria', 'lector'], app(AdminProtection::class)->roleNamesOf($objetivo));

        // Un rol inocuo junto a uno excedido: se rechaza el conjunto completo.
        $this->put(route('admin.usuarios.update', $objetivo), ['name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['secretaria', 'lector', 'auditor']])
            ->assertForbidden();
        $this->assertEqualsCanonicalizing(['secretaria', 'lector'], app(AdminProtection::class)->roleNamesOf($objetivo));

        // Un objetivo que ya supera al actor no se puede «completar» con un rol inocuo.
        $superior = $this->makeUser('auditor');
        $this->put(route('admin.usuarios.update', $superior), ['name' => $superior->name, 'email' => $superior->email, 'roles' => ['auditor', 'lector']])
            ->assertForbidden();
        $this->assertSame(['auditor'], app(AdminProtection::class)->roleNamesOf($superior));
    }

    public function test_assigning_roles_without_the_assign_permission_is_denied_and_audited(): void
    {
        $editor = $this->makeUser('coordinador', ['usuarios.editar']);
        $objetivo = $this->makeUser('secretaria');

        $this->actingAs($editor)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['secretaria']])
            ->assertForbidden();

        $this->assertSame('sin_permiso_asignar_roles', AuditLog::where('action', 'acceso_denegado')->sole()->changes['motivo']);
    }

    // ---- 8-9. Eliminación y último administrador (R3) ----------------------------------------

    public function test_only_an_admin_can_delete_an_admin_even_when_another_remains(): void
    {
        $uno = $this->makeUser('admin');
        $dos = $this->makeUser('admin');
        $gestor = $this->gestor();

        $this->actingAs($gestor)
            ->delete(route('admin.usuarios.destroy', $uno))
            ->assertForbidden();

        $this->assertModelExists($uno);
        $fila = AuditLog::where('action', 'acceso_denegado')->sole();
        $this->assertSame('R3', $fila->changes['regla']);
        $this->assertSame('admin_protegido_eliminacion', $fila->changes['motivo']);

        $this->actingAs($dos)->delete(route('admin.usuarios.destroy', $uno))->assertRedirect(route('admin.usuarios.index'));
        $this->assertModelMissing($uno);
        $this->assertSame(1, app(AdminProtection::class)->activeAdminCount());
    }

    public function test_the_authorization_rules_are_evaluated_before_the_last_admin_rule(): void
    {
        $ultimo = $this->makeUser('admin');
        $gestor = $this->gestor();

        // Un no administrador recibe 403 (sin revelar que es el último administrador), no el 422.
        $this->actingAs($gestor)->delete(route('admin.usuarios.destroy', $ultimo))->assertForbidden()->assertSessionHasNoErrors();

        // Y un administrador sí recibe la regla de integridad.
        $this->actingAs($ultimo)->delete(route('admin.usuarios.destroy', $ultimo))->assertSessionHasErrors('usuario');
        $this->assertModelExists($ultimo);
    }

    public function test_a_non_admin_cannot_delete_an_account_with_more_privileges_but_can_delete_one_within_scope(): void
    {
        $gestor = $this->gestor();
        Role::create(['name' => 'auditor', 'guard_name' => 'web'])->givePermissionTo('auditoria.ver');
        $superior = $this->makeUser('auditor');
        $propia = $this->makeUser('secretaria');

        $this->actingAs($gestor)->delete(route('admin.usuarios.destroy', $superior))->assertForbidden();
        $this->assertModelExists($superior);
        $this->assertSame('R1+', AuditLog::where('action', 'acceso_denegado')->sole()->changes['regla']);

        $this->delete(route('admin.usuarios.destroy', $propia))->assertRedirect(route('admin.usuarios.index'));
        $this->assertModelMissing($propia);
    }

    // ---- 15. Actor degradado durante la operación --------------------------------------------

    public function test_an_actor_demoted_mid_request_is_denied_under_the_lock(): void
    {
        $gestor = $this->gestor();
        $objetivo = $this->makeUser('secretaria');

        // La petición supera el middleware con el permiso vigente (caché de Spatie)...
        // ...pero otra operación degrada al actor justo después de que esta tome el bloqueo del rol `admin`.
        $degradado = false;
        $nivelBase = DB::transactionLevel(); // solo cuenta la lectura dentro de la sección exclusiva
        Role::retrieved(function (Role $rol) use (&$degradado, $gestor, $nivelBase): void {
            if ($rol->name === 'admin' && ! $degradado && DB::transactionLevel() > $nivelBase) {
                $degradado = true;
                DB::table('model_has_permissions')->where('model_id', $gestor->id)->delete();
                DB::table('model_has_roles')->where('model_id', $gestor->id)->delete();
            }
        });

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => 'Tarde', 'email' => $objetivo->email])
            ->assertForbidden();

        $this->assertTrue($degradado, 'La degradación se aplicó dentro de la sección exclusiva.');
        $this->assertNotSame('Tarde', $objetivo->fresh()->name);
        $fila = AuditLog::where('action', 'acceso_denegado')->sole();
        $this->assertSame('actor_sin_permiso_vigente', $fila->changes['motivo']);
        $this->assertSame($gestor->id, $fila->user_id);
    }

    public function test_a_deleted_actor_is_denied(): void
    {
        $gestor = $this->gestor();
        $copia = User::findOrFail($gestor->id);
        $gestor->delete();

        $this->expectException(AccionAdministrativaDenegada::class);
        app(AdminProtection::class)->actorVigente($copia, ['usuarios.editar'], 'usuario.actualizar', User::class, null);
    }

    // ---- 17. Atomicidad si falla la auditoría ------------------------------------------------

    public function test_a_failing_audit_rolls_back_the_whole_user_operation(): void
    {
        $admin = $this->makeUser('admin');
        $objetivo = $this->makeUser('secretaria');
        $nombre = $objetivo->name;
        AuditLog::creating(static function (): void {
            throw new RuntimeException('fallo-de-auditoria');
        });
        $this->withoutExceptionHandling();

        $intentos = [
            fn () => $this->actingAs($admin)->put(route('admin.usuarios.update', $objetivo), [
                'name' => 'Otro', 'email' => $objetivo->email, 'roles' => ['coordinador'],
                'password' => 'ClaveNueva12345', 'password_confirmation' => 'ClaveNueva12345',
            ]),
            fn () => $this->post(route('admin.usuarios.store'), [
                'name' => 'Nuevo', 'email' => 'nuevo@example.com', 'password' => 'Segura12345',
                'password_confirmation' => 'Segura12345', 'roles' => ['secretaria'],
            ]),
            fn () => $this->delete(route('admin.usuarios.destroy', $objetivo)),
        ];

        foreach ($intentos as $intento) {
            try {
                $intento();
                $this->fail('El fallo de auditoría debía propagarse y revertir la operación.');
            } catch (RuntimeException $e) {
                $this->assertSame('fallo-de-auditoria', $e->getMessage());
            }
        }

        $objetivo->refresh();
        $this->assertSame($nombre, $objetivo->name);
        $this->assertSame(['secretaria'], app(AdminProtection::class)->roleNamesOf($objetivo));
        $this->assertFalse(Hash::check('ClaveNueva12345', $objetivo->password));
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@example.com']);
        $this->assertModelExists($objetivo);
    }

    public function test_a_failing_audit_never_turns_a_denial_into_something_else(): void
    {
        $admin = $this->makeUser('admin');
        $gestor = $this->gestor();
        AuditLog::creating(static function (): void {
            throw new RuntimeException('fallo-de-auditoria');
        });

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $admin), ['name' => 'Tomado', 'email' => $admin->email])
            ->assertForbidden();

        $this->assertNotSame('Tomado', $admin->fresh()->name);
    }

    // ---- 18-19. Caché de Spatie después del commit -------------------------------------------

    public function test_user_role_changes_forget_the_permission_cache_after_the_commit(): void
    {
        $admin = $this->makeUser('admin');
        $objetivo = $this->makeUser('secretaria');
        $this->refillPermissionCacheBeforeCommit('CACHE-OBSOLETA');

        $this->actingAs($admin)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['coordinador']])
            ->assertRedirect(route('admin.usuarios.index'));

        $this->assertFalse(Cache::has(app(PermissionRegistrar::class)->cacheKey), 'La caché rellenada antes del commit debe invalidarse después.');
    }

    public function test_user_creation_with_roles_forgets_the_cache_after_the_commit(): void
    {
        $admin = $this->makeUser('admin');
        $this->refillPermissionCacheBeforeCommit('CACHE-OBSOLETA');

        $this->actingAs($admin)->post(route('admin.usuarios.store'), [
            'name' => 'Nuevo', 'email' => 'nuevo@example.com', 'password' => 'Segura12345',
            'password_confirmation' => 'Segura12345', 'roles' => ['secretaria'],
        ])->assertRedirect(route('admin.usuarios.index'));

        $this->assertFalse(Cache::has(app(PermissionRegistrar::class)->cacheKey));
    }

    public function test_a_rolled_back_or_denied_user_operation_does_not_forget_the_cache_prematurely(): void
    {
        $admin = $this->makeUser('admin');
        $gestor = $this->gestor();
        $objetivo = $this->makeUser('secretaria');
        $clave = app(PermissionRegistrar::class)->cacheKey;

        // Denegación (403): no se toca la caché (se rellena de verdad y debe seguir presente).
        app(PermissionRegistrar::class)->getPermissions();
        $this->assertTrue(Cache::has($clave));
        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $objetivo), ['name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['admin']])
            ->assertForbidden();
        $this->assertTrue(Cache::has($clave), 'Una denegación no invalida la caché.');

        // Fallo después del cambio de roles (rollback): la invalidación diferida se descarta.
        Cache::put($clave, 'CACHE-VIGENTE');
        AuditLog::created(static function (): void {
            throw new RuntimeException('fallo-antes-del-commit');
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->put(route('admin.usuarios.update', $objetivo), [
                'name' => $objetivo->name, 'email' => $objetivo->email, 'roles' => ['coordinador'],
            ]);
            $this->fail('La excepción debía propagarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('fallo-antes-del-commit', $e->getMessage());
        }

        $this->assertSame('CACHE-VIGENTE', Cache::get($clave));
        $this->assertSame(['secretaria'], app(AdminProtection::class)->roleNamesOf($objetivo), 'El cambio de roles se revirtió.');
    }

    // ---- 20. Permisos de los roles base ------------------------------------------------------

    public function test_the_three_base_roles_keep_their_permissions_and_the_panel_flows_still_work(): void
    {
        $admin = $this->makeUser('admin');

        // Flujo legítimo completo de un administrador.
        $this->actingAs($admin)->post(route('admin.usuarios.store'), [
            'name' => 'Nuevo', 'email' => 'nuevo@example.com', 'password' => 'Segura12345',
            'password_confirmation' => 'Segura12345', 'roles' => ['coordinador'],
        ])->assertRedirect(route('admin.usuarios.index'));
        $nuevo = User::where('email', 'nuevo@example.com')->firstOrFail();
        $this->put(route('admin.usuarios.update', $nuevo), ['name' => 'Editado', 'email' => $nuevo->email, 'roles' => ['secretaria']])
            ->assertRedirect(route('admin.usuarios.index'));
        $this->delete(route('admin.usuarios.destroy', $nuevo))->assertRedirect(route('admin.usuarios.index'));
        $this->assertSame(0, AuditLog::where('action', 'acceso_denegado')->count());

        // Coordinador y secretaria no gestionan usuarios ni roles (como antes).
        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->makeUser($rol))->get(route('admin.usuarios.index'))->assertForbidden();
        }

        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::PERMISSIONS, Role::findByName('admin')->permissions->pluck('name')->all());
        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS, Role::findByName('coordinador')->permissions->pluck('name')->all());
        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS, Role::findByName('secretaria')->permissions->pluck('name')->all());
    }

    // ---- 21. Privacidad ----------------------------------------------------------------------

    public function test_denial_rows_never_contain_sensitive_values(): void
    {
        $admin = $this->makeUser('admin');
        $gestor = $this->gestor();

        $this->actingAs($gestor)->put(route('admin.usuarios.update', $admin), [
            'name' => 'Nombre Secreto Persona',
            'email' => 'correo-secreto@example.com',
            'password' => 'ClaveSecreta12345',
            'password_confirmation' => 'ClaveSecreta12345',
            'roles' => ['admin'],
        ])->assertForbidden();
        $this->delete(route('admin.usuarios.destroy', $admin))->assertForbidden();

        $filas = AuditLog::where('action', 'acceso_denegado')->get();
        $this->assertCount(2, $filas);

        $volcado = (string) json_encode($filas->map->getAttributes()->all());
        foreach ([
            'Nombre Secreto Persona', 'correo-secreto@example.com', 'ClaveSecreta12345', $admin->email, $admin->name,
            $gestor->email, (string) $admin->password, 'laravel_session', 'XSRF',
        ] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $volcado);
        }

        foreach ($filas as $fila) {
            $this->assertSame(['esquema', 'categoria', 'regla', 'motivo', 'operacion', 'contexto'], array_keys($fila->changes));
        }
    }

    // ---- Auxiliares --------------------------------------------------------------------------

    /** Un coordinador con permisos de gestión de usuarios, pero sin ser administrador. */
    private function gestor(): User
    {
        return $this->makeUser('coordinador', ['usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'usuarios.asignar-roles']);
    }

    /** @param  list<string>  $extraPermissions */
    private function makeUser(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        foreach ($extraPermissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** Simula otra petición que vuelve a llenar la caché con datos aún no confirmados. */
    private function refillPermissionCacheBeforeCommit(string $valor): void
    {
        AuditLog::created(static function () use ($valor): void {
            Cache::put(app(PermissionRegistrar::class)->cacheKey, $valor);
        });
    }
}
