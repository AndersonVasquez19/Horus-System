<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L1.1: `matriculas.exportar` y `matriculas.eliminar` son permisos propios (mínimo privilegio).
 */
class MatriculaSensitivePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_27_000012_add_matriculas_exportar_eliminar_permissions.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---- Matriz de roles --------------------------------------------------------------------

    public function test_only_admin_holds_the_new_sensitive_permissions(): void
    {
        foreach (['matriculas.exportar', 'matriculas.eliminar'] as $permiso) {
            $this->assertTrue(Role::findByName('admin')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('coordinador')->hasPermissionTo($permiso), "coordinador no debe tener {$permiso}");
            $this->assertFalse(Role::findByName('secretaria')->hasPermissionTo($permiso), "secretaria no debe tener {$permiso}");
        }
    }

    public function test_other_enrollment_permissions_keep_their_previous_matrix(): void
    {
        $coordinador = Role::findByName('coordinador');
        $secretaria = Role::findByName('secretaria');

        foreach (['matriculas.ver', 'matriculas.crear', 'matriculas.editar', 'matriculas.restaurar'] as $permiso) {
            $this->assertTrue($coordinador->hasPermissionTo($permiso), "coordinador conserva {$permiso}");
        }
        foreach (['matriculas.ver', 'matriculas.crear'] as $permiso) {
            $this->assertTrue($secretaria->hasPermissionTo($permiso), "secretaria conserva {$permiso}");
        }
        foreach (['matriculas.anular'] as $permiso) {
            $this->assertFalse($coordinador->hasPermissionTo($permiso));
            $this->assertFalse($secretaria->hasPermissionTo($permiso));
        }
        $this->assertFalse($secretaria->hasPermissionTo('matriculas.editar'));
        $this->assertFalse($secretaria->hasPermissionTo('matriculas.restaurar'));
    }

    // ---- Exportación CSV --------------------------------------------------------------------

    public function test_csv_export_is_allowed_only_to_admin_among_the_base_roles(): void
    {
        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->get(route('admin.matriculas.csv'))->assertForbidden();
        }

        $this->actingAs($this->staff('admin'))->get(route('admin.matriculas.csv'))->assertOk();
    }

    public function test_reading_enrollments_does_not_allow_exporting_them(): void
    {
        $lector = $this->staff('secretaria');
        $this->assertTrue($lector->can('matriculas.ver'));

        $this->actingAs($lector)->get(route('admin.matriculas.index'))->assertOk();
        $this->get(route('admin.matriculas.csv'))->assertForbidden();
    }

    public function test_exporting_requires_the_export_permission_and_nothing_else_grants_it(): void
    {
        $exportador = $this->staff('secretaria', ['matriculas.exportar']);

        $this->actingAs($exportador)->get(route('admin.matriculas.csv'))->assertOk();

        // Ni anular ni eliminar ni editar conceden exportar.
        $otro = $this->staff('coordinador', ['matriculas.anular', 'matriculas.eliminar']);
        $this->actingAs($otro)->get(route('admin.matriculas.csv'))->assertForbidden();
    }

    // ---- Eliminación ------------------------------------------------------------------------

    public function test_deleting_is_allowed_only_to_admin_among_the_base_roles(): void
    {
        $matricula = $this->makeEnrollment('70000101');

        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->delete(route('admin.matriculas.destroy', $matricula))->assertForbidden();
        }
        $this->assertNotSoftDeleted($matricula);

        $this->actingAs($this->staff('admin'))
            ->delete(route('admin.matriculas.destroy', $matricula))
            ->assertRedirect(route('admin.matriculas.index'));
        $this->assertSoftDeleted($matricula);
    }

    public function test_cancelling_does_not_allow_deleting_and_deleting_does_not_allow_cancelling(): void
    {
        $matricula = $this->makeEnrollment('70000102');

        $soloAnular = $this->staff('coordinador', ['matriculas.anular']);
        $this->actingAs($soloAnular)->delete(route('admin.matriculas.destroy', $matricula))->assertForbidden();
        $this->assertNotSoftDeleted($matricula);

        $soloEliminar = $this->staff('coordinador', ['matriculas.eliminar']);
        $this->actingAs($soloEliminar)->patch(route('admin.matriculas.anular', $matricula))->assertForbidden();
        $this->assertSame('pendiente', $matricula->fresh()->estado);

        $this->actingAs($soloAnular)
            ->patch(route('admin.matriculas.anular', $matricula))
            ->assertRedirect(route('admin.matriculas.index'));
        $this->assertSame('anulada', $matricula->fresh()->estado);

        $this->actingAs($soloEliminar)
            ->delete(route('admin.matriculas.destroy', $matricula))
            ->assertRedirect(route('admin.matriculas.index'));
        $this->assertSoftDeleted($matricula);
    }

    public function test_guests_are_redirected_for_both_operations(): void
    {
        $matricula = $this->makeEnrollment('70000103');

        $this->get(route('admin.matriculas.csv'))->assertRedirect(route('login'));
        $this->delete(route('admin.matriculas.destroy', $matricula))->assertRedirect(route('login'));
        $this->assertNotSoftDeleted($matricula);
    }

    // ---- Vistas -----------------------------------------------------------------------------

    public function test_list_hides_export_and_delete_controls_without_permission(): void
    {
        $this->makeEnrollment('70000104');

        $this->actingAs($this->staff('coordinador'))
            ->get(route('admin.matriculas.index'))
            ->assertOk()
            ->assertDontSee('Exportar CSV')
            ->assertDontSee('Eliminar matrícula');

        $this->actingAs($this->staff('admin'))
            ->get(route('admin.matriculas.index'))
            ->assertOk()
            ->assertSee('Exportar CSV')
            ->assertSee('Eliminar matrícula');
    }

    // ---- Migración de permisos para bases existentes ----------------------------------------

    public function test_migration_adds_the_permissions_to_an_existing_database_for_admin_only(): void
    {
        $this->simulateLegacyDatabase();
        $this->assertNull(Permission::where('name', 'matriculas.exportar')->first());

        $this->migration()->up();

        $admin = Role::findByName('admin');
        foreach (['matriculas.exportar', 'matriculas.eliminar'] as $permiso) {
            $this->assertDatabaseHas('permissions', ['name' => $permiso, 'guard_name' => 'web']);
            $this->assertTrue($admin->fresh()->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('coordinador')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('secretaria')->hasPermissionTo($permiso));
        }
    }

    public function test_migration_is_idempotent_and_does_not_touch_existing_assignments(): void
    {
        $this->simulateLegacyDatabase();
        $antes = [
            'coordinador' => Role::findByName('coordinador')->permissions->pluck('name')->sort()->values()->all(),
            'secretaria' => Role::findByName('secretaria')->permissions->pluck('name')->sort()->values()->all(),
        ];
        $permisos = Permission::count();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($permisos + 2, Permission::count());
        $this->assertSame(
            2,
            DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->whereIn('permissions.name', ['matriculas.exportar', 'matriculas.eliminar'])
                ->count(),
        );
        $this->assertSame($antes['coordinador'], Role::findByName('coordinador')->permissions->pluck('name')->sort()->values()->all());
        $this->assertSame($antes['secretaria'], Role::findByName('secretaria')->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_migration_works_when_the_admin_role_does_not_exist_yet(): void
    {
        $this->simulateLegacyDatabase();
        Role::query()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->migration()->up();

        $this->assertDatabaseHas('permissions', ['name' => 'matriculas.exportar']);
        $this->assertSame(0, DB::table('role_has_permissions')->count());
    }

    public function test_migration_down_removes_only_the_two_permissions(): void
    {
        $total = Permission::count();

        $this->migration()->down();

        $this->assertSame($total - 2, Permission::count());
        $this->assertDatabaseMissing('permissions', ['name' => 'matriculas.exportar']);
        $this->assertDatabaseMissing('permissions', ['name' => 'matriculas.eliminar']);
        $this->assertDatabaseHas('permissions', ['name' => 'matriculas.anular']);
    }

    // ---- Utilidades -------------------------------------------------------------------------

    /**
     * @param  list<string>  $extraPermissions
     */
    private function staff(string $rol, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        foreach ($extraPermissions as $permiso) {
            $user->givePermissionTo($permiso);
        }

        return $user;
    }

    /** Deja los permisos como estaban antes de L1.1 (sin los dos nuevos). */
    private function simulateLegacyDatabase(): void
    {
        $this->migration()->down();
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function makeEnrollment(string $dni): Matricula
    {
        $curso = Curso::firstOrCreate(['codigo' => 'CUR-PERM'], [
            'nombre' => 'Curso permisos',
            'descripcion' => 'Descripción.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 10,
            'cupos_disponibles' => 10,
            'estado' => 'activo',
        ]);
        $estudiante = Estudiante::create(['dni' => $dni, 'nombres' => 'Estudiante', 'apellidos' => 'De Prueba', 'estado' => 'activo']);
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
