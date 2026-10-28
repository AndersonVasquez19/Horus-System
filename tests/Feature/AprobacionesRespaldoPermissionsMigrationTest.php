<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 15-B: bases sembradas antes de que existieran los permisos de Aprobaciones y Respaldo
 * (caso medido en solo lectura sobre la base de desarrollo) los reciben por migración.
 */
class AprobacionesRespaldoPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_28_000024_add_aprobaciones_respaldo_permissions.php';

    private const PERMISOS = ['aprobaciones.ver', 'aprobaciones.gestionar', 'respaldo.ver', 'respaldo.exportar'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_legacy_database_denies_admin_until_the_migration_runs(): void
    {
        $this->simulateLegacyDatabase();
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get(route('admin.aprobaciones.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.respaldo.index'))->assertForbidden();

        $this->migration()->up();

        $admin = $admin->fresh();
        $this->actingAs($admin)->get(route('admin.aprobaciones.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.respaldo.index'))->assertOk();
    }

    public function test_migration_reproduces_the_seeder_matrix_for_these_permissions(): void
    {
        $this->simulateLegacyDatabase();

        $this->migration()->up();

        $matrices = [
            'admin' => RolesAndPermissionsSeeder::PERMISSIONS,
            'coordinador' => RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS,
            'secretaria' => RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS,
        ];
        foreach ($matrices as $rol => $esperados) {
            foreach (self::PERMISOS as $permiso) {
                $this->assertSame(
                    in_array($permiso, $esperados, true),
                    Role::findByName($rol)->hasPermissionTo($permiso),
                    "{$rol} / {$permiso}",
                );
            }
        }
    }

    public function test_migration_is_idempotent_and_keeps_every_other_assignment(): void
    {
        $this->simulateLegacyDatabase();
        $manual = $this->staff('secretaria');
        $manual->givePermissionTo('reportes.exportar.pdf');
        $otrosAntes = $this->otherGrants();
        $permisos = Permission::count();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($permisos + 4, Permission::count());
        $this->assertSame($otrosAntes, $this->otherGrants());
        $this->assertTrue($manual->fresh()->hasDirectPermission('reportes.exportar.pdf'));
        $this->assertSame(8, DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('permissions.name', self::PERMISOS)->count());
    }

    public function test_migration_without_roles_only_creates_permissions(): void
    {
        $this->simulateLegacyDatabase();
        Role::query()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->migration()->up();

        $this->assertSame(4, Permission::whereIn('name', self::PERMISOS)->count());
        $this->assertSame(0, DB::table('role_has_permissions')->count());
    }

    public function test_fresh_installation_keeps_seeder_grants_and_down_has_no_effect(): void
    {
        $antes = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame($antes, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertSame(4, Permission::whereIn('name', self::PERMISOS)->count());
    }

    /** Estado medido: los cuatro permisos no existen y nadie los tiene. */
    private function simulateLegacyDatabase(): void
    {
        Permission::whereIn('name', self::PERMISOS)->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(0, Permission::whereIn('name', self::PERMISOS)->count());
    }

    /** @return list<string> */
    private function otherGrants(): array
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->whereNotIn('permissions.name', self::PERMISOS)
            ->orderBy('roles.name')->orderBy('permissions.name')
            ->get(['roles.name as rol', 'permissions.name as permiso'])
            ->map(fn ($r) => $r->rol.':'.$r->permiso)->all();
    }

    private function staff(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }
}
