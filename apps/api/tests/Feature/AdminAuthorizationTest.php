<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\LlamaAuditoriaDelPanel;
use Tests\Support\LlamaCursosDelPanel;
use Tests\Support\LlamaEstudiantesDelPanel;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\Support\LlamaRolesDelPanel;
use Tests\Support\LlamaUsuariosDelPanel;
use Tests\Support\LlamaRespaldoDelPanel;
use Tests\TestCase;

/**
 * Matriz de acceso del panel /admin: autenticación, rol base y permiso Spatie por operación.
 */
class AdminAuthorizationTest extends TestCase
{
    use LlamaRespaldoDelPanel;
    use LlamaAuditoriaDelPanel;
    use LlamaCursosDelPanel;
    use LlamaEstudiantesDelPanel;
    use LlamaMatriculasDelPanel;
    use LlamaRolesDelPanel;
    use LlamaUsuariosDelPanel;
    use RefreshDatabase;

    private const ALL = ['admin', 'coordinador', 'secretaria'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    public function test_guests_cannot_reach_destructive_or_export_operations(): void
    {
        $estudiante = $this->makeStudent('70000001');

        $this->panelEstudiantes('DELETE', (string) $estudiante->id)->assertUnauthorized();
        $this->panelRespaldo('POST', 'exportar')->assertUnauthorized();
        $this->crearUsuarioPanel([])->assertUnauthorized();

        $this->assertNotSoftDeleted($estudiante);
    }

    public function test_secretaria_cannot_edit_delete_or_restore_students(): void
    {
        $secretaria = $this->staff('secretaria');
        $estudiante = $this->makeStudent('70000002');
        $eliminado = $this->makeStudent('70000003');
        $eliminado->delete();

        $this->actingAs($secretaria);
        $this->panelEstudiantes('PUT', (string) $estudiante->id, $this->studentPayload('70000002', 'Cambiado'))
            ->assertForbidden();
        $this->panelEstudiantes('DELETE', (string) $estudiante->id)->assertForbidden();
        $this->panelEstudiantes('POST', $eliminado->id.'/restaurar')->assertForbidden();

        $this->assertDatabaseHas('estudiantes', ['id' => $estudiante->id, 'nombres' => 'Estudiante', 'deleted_at' => null]);
        $this->assertSoftDeleted($eliminado);
    }

    public function test_secretaria_can_create_students(): void
    {
        $this->actingAs($this->staff('secretaria'));
        $this->panelEstudiantes('POST', '', $this->studentPayload('70000004', 'Nueva'))->assertCreated();

        $this->assertDatabaseHas('estudiantes', ['dni' => '70000004']);
    }

    public function test_coordinador_cannot_delete_or_restore_students_and_courses(): void
    {
        $coordinador = $this->staff('coordinador');
        $estudiante = $this->makeStudent('70000005');
        $curso = $this->makeCourse();
        $cursoEliminado = $this->makeCourse(['codigo' => 'CUR-DEL']);
        $cursoEliminado->delete();

        $this->actingAs($coordinador);
        $this->panelEstudiantes('DELETE', (string) $estudiante->id)->assertForbidden();
        $this->panelCursos('DELETE', (string) $curso->id)->assertForbidden();
        $this->panelCursos('POST', $cursoEliminado->id.'/restaurar')->assertForbidden();

        $this->assertNotSoftDeleted($estudiante);
        $this->assertNotSoftDeleted($curso);
        $this->assertSoftDeleted($cursoEliminado);
    }

    public function test_secretaria_cannot_create_or_edit_courses(): void
    {
        $secretaria = $this->staff('secretaria');
        $curso = $this->makeCourse();

        $this->actingAs($secretaria);
        $this->panelCursos('POST', '', [])->assertForbidden();
        $this->panelCursos('PUT', (string) $curso->id, [])->assertForbidden();
    }

    public function test_enrollment_destructive_operations_require_specific_permissions(): void
    {
        $curso = $this->makeCourse();
        $matricula = $this->makeEnrollment($this->makeStudent('70000006'), $curso);

        foreach (['coordinador', 'secretaria'] as $role) {
            $this->actingAs($this->staff($role))
                ->panelMatriculas('POST', $matricula->id.'/anular')
                ->assertForbidden();
            $this->panelMatriculas('DELETE', (string) $matricula->id)->assertForbidden();
        }

        $this->actingAs($this->staff('secretaria'));
        $this->panelMatriculas('PUT', (string) $matricula->id, [])->assertForbidden();

        $this->assertDatabaseHas('matriculas', ['id' => $matricula->id, 'estado' => 'pendiente', 'deleted_at' => null]);
        $this->assertSame(4, $curso->fresh()->cupos_disponibles);
    }

    public function test_el_listado_de_matriculas_del_panel_sigue_la_matriz_de_roles(): void
    {
        $this->panelMatriculas('GET')->assertUnauthorized();
        foreach (self::ALL as $rol) {
            $this->actingAs($this->staff($rol))->panelMatriculas('GET')->assertOk();
        }
        $this->actingAs(User::factory()->create())->panelMatriculas('GET')->assertForbidden();
    }

    public function test_admin_can_cancel_enrollments_and_coordinador_can_restore_them(): void
    {
        $curso = $this->makeCourse();
        $matricula = $this->makeEnrollment($this->makeStudent('70000007'), $curso);

        $this->actingAs($this->staff('admin'))
            ->panelMatriculas('DELETE', (string) $matricula->id)
            ->assertOk();
        $this->assertSoftDeleted($matricula);

        $this->actingAs($this->staff('coordinador'))
            ->panelMatriculas('POST', $matricula->id.'/restaurar')
            ->assertOk();
        $this->assertNotSoftDeleted($matricula);
    }

    public function test_exports_are_restricted_to_their_permissions(): void
    {
        // Las exportaciones de reportes (Excel/PDF) se retiraron de Blade el 14/11/2026: su matriz de permisos está en
        // Panel\ReportesConsistenciaPanelTest::test_export_endpoints_require_their_own_permission y ReportesPanelTest.

        foreach (['coordinador', 'secretaria'] as $role) {
            $this->actingAs($this->staff($role))->panelRespaldo('POST', 'exportar')->assertForbidden();
            $this->panelAuditoria('POST', 'exportar')->assertForbidden();
        }

        // La descarga permitida (200) del coordinador se comprueba en ReportExcelExportTest, sin la
        // transacción envolvente de RefreshDatabase que la salvaguarda de snapshot rechaza en PostgreSQL.
    }

    public function test_only_admin_manages_users_roles_and_settings(): void
    {
        $objetivo = User::factory()->create();
        $rol = Role::findByName('secretaria');

        foreach (['coordinador', 'secretaria'] as $role) {
            $this->actingAs($this->staff($role));
            $this->crearUsuarioPanel([])->assertForbidden();
            $this->llamarUsuariosPanel('GET')->assertForbidden();
            $this->llamarUsuariosPanel('GET', (string) $objetivo->id)->assertForbidden();
            $this->actualizarUsuarioPanel($objetivo, [])->assertForbidden();
            $this->eliminarUsuarioPanel($objetivo)->assertForbidden();
            // Los roles los sirve el panel (lectura incluida): sin `roles.*` es 403.
            $this->llamarRolesPanel('GET')->assertForbidden();
            $this->crearRolPanel(['name' => 'nuevo'])->assertForbidden();
            $this->actualizarRolPanel($rol, ['name' => 'renombrado'])->assertForbidden();
            $this->eliminarRolPanel($rol)->assertForbidden();
        }

        $this->assertModelExists($objetivo);
        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'name' => 'secretaria']);
        $this->assertDatabaseMissing('roles', ['name' => 'nuevo']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'moneda', 'value' => 'X']);
    }

    public function test_secretaria_cannot_use_the_assistant(): void
    {
        $this->actingAs($this->staff('secretaria'));
        config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);
        $this->postJson('/api/panel/v1/compartido/asistente/mensaje', ['mensaje' => 'hola', 'session_id' => 's'], ['X-Horus-Web-Token' => 'token-de-servicio-del-bff-para-pruebas-0123456789'])->assertForbidden();
    }

    public function test_admin_user_update_changes_the_bound_user_instead_of_creating_one(): void
    {
        $objetivo = User::factory()->create(['name' => 'Original', 'email' => 'objetivo@example.com']);
        $objetivo->assignRole('secretaria');
        $admin = $this->staff('admin');
        $usuarios = User::count();

        $this->actingAs($admin)
            ->actualizarUsuarioPanel($objetivo, [
                'name' => 'Actualizado',
                'email' => 'objetivo@example.com',
                'roles' => ['coordinador'],
            ])
            ->assertSuccessful();

        $this->assertSame($usuarios, User::count());
        $this->assertSame('Actualizado', $objetivo->fresh()->name);
        $this->assertTrue($objetivo->fresh()->hasRole('coordinador'));
        $this->assertFalse($objetivo->fresh()->hasRole('secretaria'));
    }

    public function test_admin_can_delete_other_users_but_not_itself(): void
    {
        $admin = $this->staff('admin');
        $objetivo = User::factory()->create();

        $this->actingAs($admin)->llamarUsuariosPanel('GET', (string) $objetivo->id)->assertOk()->assertJsonPath('data.email', $objetivo->email);

        $this->eliminarUsuarioPanel($objetivo)->assertSuccessful();
        $this->assertModelMissing($objetivo);

        $this->eliminarUsuarioPanel($admin)->assertJsonValidationErrors('usuario');
        $this->assertModelExists($admin);
    }

    public function test_assigning_roles_requires_the_assign_roles_permission(): void
    {
        $editor = $this->staff('coordinador');
        $editor->givePermissionTo('usuarios.editar');
        $objetivo = User::factory()->create(['email' => 'objetivo@example.com']);
        $objetivo->assignRole('secretaria');

        $this->actingAs($editor)
            ->actualizarUsuarioPanel($objetivo, [
                'name' => 'Escalado',
                'email' => 'objetivo@example.com',
                'roles' => ['admin'],
            ])
            ->assertForbidden();

        $this->assertFalse($objetivo->fresh()->hasRole('admin'));
        $this->assertNotSame('Escalado', $objetivo->fresh()->name);

        $this->actualizarUsuarioPanel($objetivo, [
            'name' => 'Sin cambio de rol',
            'email' => 'objetivo@example.com',
        ])->assertSuccessful();

        $this->assertSame('Sin cambio de rol', $objetivo->fresh()->name);
        $this->assertTrue($objetivo->fresh()->hasRole('secretaria'));
    }

    public function test_creating_users_with_roles_requires_the_assign_roles_permission(): void
    {
        $creador = $this->staff('coordinador');
        $creador->givePermissionTo('usuarios.crear');

        $this->actingAs($creador)
            ->crearUsuarioPanel([
                'name' => 'Nuevo',
                'email' => 'nuevo@example.com',
                'password' => 'Segura12345',
                'password_confirmation' => 'Segura12345',
                'roles' => ['admin'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@example.com']);
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
