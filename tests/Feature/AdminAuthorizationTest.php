<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\ProcesoInterno;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Matriz de acceso del panel /admin: autenticación, rol base y permiso Spatie por operación.
 */
class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['admin', 'coordinador', 'secretaria'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    /**
     * Páginas GET del panel y roles que pueden abrirlas según RolesAndPermissionsSeeder.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['admin.dashboard', self::ALL],
            'busqueda' => ['admin.busqueda.index', self::ALL],
            'estudiantes.index' => ['admin.estudiantes.index', self::ALL],
            'estudiantes.create' => ['admin.estudiantes.create', self::ALL],
            'cursos.index' => ['admin.cursos.index', self::ALL],
            'cursos.create' => ['admin.cursos.create', ['admin', 'coordinador']],
            'matriculas.index' => ['admin.matriculas.index', self::ALL],
            'matriculas.create' => ['admin.matriculas.create', self::ALL],
            'matriculas.csv' => ['admin.matriculas.csv', self::ALL],
            'usuarios.index' => ['admin.usuarios.index', ['admin']],
            'usuarios.create' => ['admin.usuarios.create', ['admin']],
            'roles.index' => ['admin.roles.index', ['admin']],
            'procesos.index' => ['admin.procesos.index', ['admin', 'coordinador']],
            'procesos.create' => ['admin.procesos.create', ['admin']],
            'aprobaciones.index' => ['admin.aprobaciones.index', self::ALL],
            'notificaciones.index' => ['admin.notificaciones.index', self::ALL],
            'reportes.index' => ['admin.reportes.index', self::ALL],
            'configuracion.index' => ['admin.configuracion.index', ['admin']],
            'auditoria.index' => ['admin.auditoria.index', ['admin']],
            'auditoria.exportar' => ['admin.auditoria.exportar', ['admin']],
            'respaldo.index' => ['admin.respaldo.index', ['admin', 'coordinador']],
            'asistente.index' => ['admin.asistente.index', ['admin', 'coordinador']],
        ];
    }

    /**
     * @param  list<string>  $allowedRoles
     */
    #[DataProvider('adminPages')]
    public function test_admin_pages_follow_the_role_permission_matrix(string $routeName, array $allowedRoles): void
    {
        foreach (self::ALL as $role) {
            $response = $this->actingAs($this->staff($role))->get(route($routeName));

            if (in_array($role, $allowedRoles, true)) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    #[DataProvider('adminPages')]
    public function test_guests_are_redirected_to_login(string $routeName): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    #[DataProvider('adminPages')]
    public function test_authenticated_users_without_staff_role_are_forbidden(string $routeName): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route($routeName))
            ->assertForbidden();
    }

    public function test_guests_cannot_reach_destructive_or_export_operations(): void
    {
        $estudiante = $this->makeStudent('70000001');

        $this->delete(route('admin.estudiantes.destroy', $estudiante))->assertRedirect(route('login'));
        $this->post(route('admin.reportes.exportar.excel'))->assertRedirect(route('login'));
        $this->get(route('admin.respaldo.exportar'))->assertRedirect(route('login'));
        $this->post(route('admin.usuarios.store'), [])->assertRedirect(route('login'));

        $this->assertNotSoftDeleted($estudiante);
    }

    public function test_secretaria_cannot_edit_delete_or_restore_students(): void
    {
        $secretaria = $this->staff('secretaria');
        $estudiante = $this->makeStudent('70000002');
        $eliminado = $this->makeStudent('70000003');
        $eliminado->delete();

        $this->actingAs($secretaria)->get(route('admin.estudiantes.edit', $estudiante))->assertForbidden();
        $this->put(route('admin.estudiantes.update', $estudiante), $this->studentPayload('70000002', 'Cambiado'))
            ->assertForbidden();
        $this->delete(route('admin.estudiantes.destroy', $estudiante))->assertForbidden();
        $this->patch(route('admin.estudiantes.restaurar', $eliminado->id))->assertForbidden();

        $this->assertDatabaseHas('estudiantes', ['id' => $estudiante->id, 'nombres' => 'Estudiante', 'deleted_at' => null]);
        $this->assertSoftDeleted($eliminado);
    }

    public function test_secretaria_can_create_students(): void
    {
        $this->actingAs($this->staff('secretaria'))
            ->post(route('admin.estudiantes.store'), $this->studentPayload('70000004', 'Nueva'))
            ->assertRedirect(route('admin.estudiantes.index'));

        $this->assertDatabaseHas('estudiantes', ['dni' => '70000004']);
    }

    public function test_coordinador_cannot_delete_or_restore_students_and_courses(): void
    {
        $coordinador = $this->staff('coordinador');
        $estudiante = $this->makeStudent('70000005');
        $curso = $this->makeCourse();
        $cursoEliminado = $this->makeCourse(['codigo' => 'CUR-DEL']);
        $cursoEliminado->delete();

        $this->actingAs($coordinador)->delete(route('admin.estudiantes.destroy', $estudiante))->assertForbidden();
        $this->delete(route('admin.cursos.destroy', $curso))->assertForbidden();
        $this->patch(route('admin.cursos.restaurar', $cursoEliminado->id))->assertForbidden();

        $this->assertNotSoftDeleted($estudiante);
        $this->assertNotSoftDeleted($curso);
        $this->assertSoftDeleted($cursoEliminado);
    }

    public function test_secretaria_cannot_create_or_edit_courses(): void
    {
        $secretaria = $this->staff('secretaria');
        $curso = $this->makeCourse();

        $this->actingAs($secretaria)->post(route('admin.cursos.store'), [])->assertForbidden();
        $this->get(route('admin.cursos.edit', $curso))->assertForbidden();
        $this->put(route('admin.cursos.update', $curso), [])->assertForbidden();
    }

    public function test_enrollment_destructive_operations_require_specific_permissions(): void
    {
        $curso = $this->makeCourse();
        $matricula = $this->makeEnrollment($this->makeStudent('70000006'), $curso);

        foreach (['coordinador', 'secretaria'] as $role) {
            $this->actingAs($this->staff($role))
                ->patch(route('admin.matriculas.anular', $matricula))
                ->assertForbidden();
            $this->delete(route('admin.matriculas.destroy', $matricula))->assertForbidden();
        }

        $this->actingAs($this->staff('secretaria'))
            ->get(route('admin.matriculas.edit', $matricula))
            ->assertForbidden();
        $this->put(route('admin.matriculas.update', $matricula), [])->assertForbidden();

        $this->assertDatabaseHas('matriculas', ['id' => $matricula->id, 'estado' => 'pendiente', 'deleted_at' => null]);
        $this->assertSame(4, $curso->fresh()->cupos_disponibles);
    }

    public function test_admin_can_cancel_enrollments_and_coordinador_can_restore_them(): void
    {
        $curso = $this->makeCourse();
        $matricula = $this->makeEnrollment($this->makeStudent('70000007'), $curso);

        $this->actingAs($this->staff('admin'))
            ->delete(route('admin.matriculas.destroy', $matricula))
            ->assertRedirect(route('admin.matriculas.index'));
        $this->assertSoftDeleted($matricula);

        $this->actingAs($this->staff('coordinador'))
            ->patch(route('admin.matriculas.restaurar', $matricula->id))
            ->assertRedirect(route('admin.matriculas.index'));
        $this->assertNotSoftDeleted($matricula);
    }

    public function test_exports_are_restricted_to_their_permissions(): void
    {
        foreach (['secretaria'] as $role) {
            $this->actingAs($this->staff($role))->post(route('admin.reportes.exportar.excel'))->assertForbidden();
            $this->post(route('admin.reportes.exportar.pdf'))->assertForbidden();
        }

        foreach (['coordinador', 'secretaria'] as $role) {
            $this->actingAs($this->staff($role))->get(route('admin.respaldo.exportar'))->assertForbidden();
            $this->get(route('admin.auditoria.exportar'))->assertForbidden();
        }

        $this->actingAs($this->staff('coordinador'))
            ->post(route('admin.reportes.exportar.excel'))
            ->assertOk()
            ->assertDownload();
    }

    public function test_only_admin_manages_users_roles_settings_and_processes(): void
    {
        $objetivo = User::factory()->create();
        $proceso = ProcesoInterno::create(['titulo' => 'Proceso', 'estado' => 'pendiente', 'prioridad' => 'media']);
        $rol = Role::findByName('secretaria');

        foreach (['coordinador', 'secretaria'] as $role) {
            $this->actingAs($this->staff($role));
            $this->post(route('admin.usuarios.store'), [])->assertForbidden();
            $this->get(route('admin.usuarios.edit', $objetivo))->assertForbidden();
            $this->put(route('admin.usuarios.update', $objetivo), [])->assertForbidden();
            $this->delete(route('admin.usuarios.destroy', $objetivo))->assertForbidden();
            $this->post(route('admin.roles.store'), ['name' => 'nuevo'])->assertForbidden();
            $this->put(route('admin.roles.update', $rol), ['name' => 'renombrado'])->assertForbidden();
            $this->delete(route('admin.roles.destroy', $rol))->assertForbidden();
            $this->put(route('admin.configuracion.update'), ['settings' => ['moneda' => 'X']])->assertForbidden();
            $this->post(route('admin.procesos.store'), [])->assertForbidden();
            $this->get(route('admin.procesos.edit', $proceso))->assertForbidden();
            $this->delete(route('admin.procesos.destroy', $proceso))->assertForbidden();
        }

        $this->assertModelExists($objetivo);
        $this->assertModelExists($proceso);
        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'name' => 'secretaria']);
        $this->assertDatabaseMissing('roles', ['name' => 'nuevo']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'moneda', 'value' => 'X']);
    }

    public function test_process_detail_requires_process_permission(): void
    {
        $proceso = ProcesoInterno::create(['titulo' => 'Proceso', 'estado' => 'pendiente', 'prioridad' => 'media']);

        $this->actingAs($this->staff('secretaria'))
            ->get(route('admin.procesos.show', $proceso))
            ->assertForbidden();
    }

    public function test_secretaria_cannot_manage_approvals_or_use_the_assistant(): void
    {
        $aprobacion = AprobacionInterna::create(['titulo' => 'Solicitud', 'tipo' => 'general', 'estado' => 'pendiente']);

        $this->actingAs($this->staff('secretaria'));
        $this->post(route('admin.aprobaciones.store'), [])->assertForbidden();
        $this->put(route('admin.aprobaciones.update', $aprobacion), [])->assertForbidden();
        $this->delete(route('admin.aprobaciones.destroy', $aprobacion))->assertForbidden();
        $this->postJson(route('admin.asistente.mensaje'), ['mensaje' => 'hola', 'session_id' => 's'])->assertForbidden();

        $this->assertModelExists($aprobacion);
    }

    public function test_admin_user_update_changes_the_bound_user_instead_of_creating_one(): void
    {
        $objetivo = User::factory()->create(['name' => 'Original', 'email' => 'objetivo@example.com']);
        $objetivo->assignRole('secretaria');
        $admin = $this->staff('admin');
        $usuarios = User::count();

        $this->actingAs($admin)
            ->put(route('admin.usuarios.update', $objetivo), [
                'name' => 'Actualizado',
                'email' => 'objetivo@example.com',
                'roles' => ['coordinador'],
            ])
            ->assertRedirect(route('admin.usuarios.index'));

        $this->assertSame($usuarios, User::count());
        $this->assertSame('Actualizado', $objetivo->fresh()->name);
        $this->assertTrue($objetivo->fresh()->hasRole('coordinador'));
        $this->assertFalse($objetivo->fresh()->hasRole('secretaria'));
    }

    public function test_admin_can_delete_other_users_but_not_itself(): void
    {
        $admin = $this->staff('admin');
        $objetivo = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.usuarios.edit', $objetivo))->assertOk()->assertSee($objetivo->email);

        $this->delete(route('admin.usuarios.destroy', $objetivo))->assertRedirect(route('admin.usuarios.index'));
        $this->assertModelMissing($objetivo);

        $this->delete(route('admin.usuarios.destroy', $admin))->assertSessionHasErrors('usuario');
        $this->assertModelExists($admin);
    }

    public function test_assigning_roles_requires_the_assign_roles_permission(): void
    {
        $editor = $this->staff('coordinador');
        $editor->givePermissionTo('usuarios.editar');
        $objetivo = User::factory()->create(['email' => 'objetivo@example.com']);
        $objetivo->assignRole('secretaria');

        $this->actingAs($editor)
            ->put(route('admin.usuarios.update', $objetivo), [
                'name' => 'Escalado',
                'email' => 'objetivo@example.com',
                'roles' => ['admin'],
            ])
            ->assertForbidden();

        $this->assertFalse($objetivo->fresh()->hasRole('admin'));
        $this->assertNotSame('Escalado', $objetivo->fresh()->name);

        $this->put(route('admin.usuarios.update', $objetivo), [
            'name' => 'Sin cambio de rol',
            'email' => 'objetivo@example.com',
        ])->assertRedirect(route('admin.usuarios.index'));

        $this->assertSame('Sin cambio de rol', $objetivo->fresh()->name);
        $this->assertTrue($objetivo->fresh()->hasRole('secretaria'));
    }

    public function test_creating_users_with_roles_requires_the_assign_roles_permission(): void
    {
        $creador = $this->staff('coordinador');
        $creador->givePermissionTo('usuarios.crear');

        $this->actingAs($creador)
            ->post(route('admin.usuarios.store'), [
                'name' => 'Nuevo',
                'email' => 'nuevo@example.com',
                'password' => 'Segura12345',
                'password_confirmation' => 'Segura12345',
                'roles' => ['admin'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@example.com']);
    }

    public function test_admin_approval_update_and_delete_act_on_the_bound_record(): void
    {
        $aprobacion = AprobacionInterna::create(['titulo' => 'Solicitud', 'tipo' => 'general', 'estado' => 'pendiente']);
        $total = AprobacionInterna::count();

        $this->actingAs($this->staff('coordinador'))
            ->put(route('admin.aprobaciones.update', $aprobacion), [
                'titulo' => 'Solicitud revisada',
                'tipo' => 'general',
                'estado' => 'aprobado',
            ])
            ->assertRedirect(route('admin.aprobaciones.index'));

        $this->assertSame($total, AprobacionInterna::count());
        $this->assertSame('aprobado', $aprobacion->fresh()->estado);

        $this->delete(route('admin.aprobaciones.destroy', $aprobacion))->assertRedirect(route('admin.aprobaciones.index'));
        $this->assertModelMissing($aprobacion);
    }

    public function test_dashboard_hides_audit_activity_without_audit_permission(): void
    {
        AuditLog::record('evento_confidencial_de_prueba');

        $this->actingAs($this->staff('secretaria'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('evento_confidencial_de_prueba');

        $this->actingAs($this->staff('admin'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('evento_confidencial_de_prueba');
    }

    public function test_search_only_returns_sections_the_user_may_view(): void
    {
        $this->makeStudent('70000099');
        User::factory()->create(['name' => 'Persona 70000099']);

        $this->actingAs($this->staff('secretaria'))
            ->get(route('admin.busqueda.index', ['q' => '70000099']))
            ->assertOk()
            ->assertSee('70000099')
            ->assertDontSee('Persona 70000099');
    }

    public function test_public_registration_never_grants_staff_access(): void
    {
        $this->post(route('register.post'), [
            'name' => 'Visitante',
            'email' => 'visitante@example.com',
            'password' => 'ClaveSegura123',
            'password_confirmation' => 'ClaveSegura123',
            'terms' => '1',
        ])->assertRedirect(route('login'));

        $user = User::where('email', 'visitante@example.com')->firstOrFail();
        $this->assertCount(0, $user->getRoleNames());
        $this->assertCount(0, $user->getAllPermissions());

        $this->post(route('login.post'), [
            'email' => 'visitante@example.com',
            'password' => 'ClaveSegura123',
        ])->assertRedirect(route('home'));

        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_api_registration_never_grants_staff_roles(): void
    {
        $this->postJson(route('api.auth.register'), [
            'name' => 'Cliente API',
            'email' => 'api@example.com',
            'password' => 'ClaveSegura123',
            'password_confirmation' => 'ClaveSegura123',
        ])->assertSuccessful();

        $this->assertCount(0, User::where('email', 'api@example.com')->firstOrFail()->getRoleNames());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['password' => Hash::make('irrelevante-'.$role)]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function studentPayload(string $dni, string $nombres): array
    {
        return [
            'dni' => $dni,
            'nombres' => $nombres,
            'apellidos' => 'Prueba',
            'estado' => 'activo',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCourse(array $attributes = []): Curso
    {
        return Curso::create(array_replace([
            'codigo' => 'CUR-AUTH',
            'nombre' => 'Curso de autorización',
            'descripcion' => 'Descripción del curso.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 5,
            'cupos_disponibles' => 5,
            'estado' => 'activo',
        ], $attributes));
    }

    private function makeStudent(string $dni): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni,
            'nombres' => 'Estudiante',
            'apellidos' => 'De Prueba',
            'estado' => 'activo',
        ]);
    }

    private function makeEnrollment(Estudiante $estudiante, Curso $curso): Matricula
    {
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pendiente',
        ]);
        $curso->decrement('cupos_disponibles');

        return $matricula;
    }
}
