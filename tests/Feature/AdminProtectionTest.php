<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminProtection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * L1.1: el sistema nunca debe quedarse sin administración.
 *
 * SQLite en memoria no reproduce el bloqueo `SELECT ... FOR UPDATE` de PostgreSQL: estas pruebas
 * validan las reglas y la secuencia de operaciones, no la concurrencia real (ver docs/seguridad-l1.1.md).
 */
class AdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---- Rol admin -------------------------------------------------------------------------

    public function test_the_admin_role_cannot_be_renamed(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::findByName('admin');

        $this->actingAs($admin)
            ->from(route('admin.roles.index'))
            ->put(route('admin.roles.update', $rol), [
                'name' => 'superadmin',
                'permissions' => RolesAndPermissionsSeeder::PERMISSIONS,
            ])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasErrors(['name' => 'El rol principal "admin" no se puede renombrar.']);

        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'name' => 'admin']);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_admin_role_cannot_lose_critical_permissions(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::findByName('admin');
        $total = $rol->permissions()->count();

        // Sin ninguna casilla marcada (no llega la clave `permissions`).
        $this->actingAs($admin)
            ->put(route('admin.roles.update', $rol), ['name' => 'admin'])
            ->assertSessionHasErrors('permissions');

        // Quitando solo uno de los permisos críticos.
        $sinAsignarRoles = array_values(array_diff(
            RolesAndPermissionsSeeder::PERMISSIONS,
            ['usuarios.asignar-roles'],
        ));
        $this->put(route('admin.roles.update', $rol), ['name' => 'admin', 'permissions' => $sinAsignarRoles])
            ->assertSessionHasErrors(['permissions' => 'El rol "admin" no puede perder permisos de administración: usuarios.asignar-roles.']);

        $this->assertSame($total, $rol->fresh()->permissions()->count());
        $this->assertTrue($admin->fresh()->can('usuarios.asignar-roles'));
    }

    public function test_the_admin_role_may_drop_non_critical_permissions_and_keep_its_name(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::findByName('admin');
        $permisos = array_values(array_diff(RolesAndPermissionsSeeder::PERMISSIONS, ['respaldo.exportar']));

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $rol), ['name' => 'admin', 'permissions' => $permisos])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();

        $this->assertFalse($rol->fresh()->hasPermissionTo('respaldo.exportar'));
        $this->assertTrue($rol->fresh()->hasPermissionTo('usuarios.asignar-roles'));
    }

    public function test_the_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::findByName('admin');

        $this->actingAs($admin)
            ->delete(route('admin.roles.destroy', $rol))
            ->assertSessionHasErrors(['rol' => 'No se puede eliminar el rol principal de administración.']);

        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'name' => 'admin']);
    }

    public function test_other_roles_remain_editable_and_deletable(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('admin.roles.store'), ['name' => 'auditor', 'permissions' => ['auditoria.ver']])
            ->assertRedirect(route('admin.roles.index'));
        $auditor = Role::findByName('auditor');

        $this->put(route('admin.roles.update', $auditor), ['name' => 'auditor-externo', 'permissions' => ['auditoria.ver', 'reportes.ver']])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('roles', ['id' => $auditor->id, 'name' => 'auditor-externo']);

        $this->delete(route('admin.roles.destroy', $auditor))->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseMissing('roles', ['id' => $auditor->id]);
    }

    public function test_coordinator_and_secretary_permissions_are_not_changed_by_the_protection(): void
    {
        $this->assertEqualsCanonicalizing(
            RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS,
            Role::findByName('coordinador')->permissions->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS,
            Role::findByName('secretaria')->permissions->pluck('name')->all(),
        );
        $this->assertSame(3, Role::count());
    }

    // ---- Último administrador ---------------------------------------------------------------

    public function test_the_last_admin_cannot_be_deleted(): void
    {
        $ultimo = $this->makeUser('admin');
        $gestor = $this->makeUser('coordinador', ['usuarios.eliminar']);

        $this->actingAs($gestor)
            ->delete(route('admin.usuarios.destroy', $ultimo))
            ->assertSessionHasErrors(['usuario' => 'No se puede eliminar al último administrador del sistema.']);

        $this->assertModelExists($ultimo);
        $this->assertTrue($ultimo->fresh()->hasRole('admin'));
    }

    public function test_an_admin_can_be_deleted_when_another_one_remains(): void
    {
        $uno = $this->makeUser('admin');
        $dos = $this->makeUser('admin');
        $gestor = $this->makeUser('coordinador', ['usuarios.eliminar']);

        $this->actingAs($gestor)
            ->delete(route('admin.usuarios.destroy', $uno))
            ->assertRedirect(route('admin.usuarios.index'));

        $this->assertModelMissing($uno);
        $this->assertSame(1, app(AdminProtection::class)->activeAdminCount());

        // Ahora $dos es el último: ya no puede eliminarse.
        $this->delete(route('admin.usuarios.destroy', $dos))
            ->assertSessionHasErrors('usuario');
        $this->assertModelExists($dos);
    }

    public function test_the_last_admin_cannot_lose_the_admin_role(): void
    {
        $ultimo = $this->makeUser('admin');
        $gestor = $this->makeUser('coordinador', ['usuarios.editar', 'usuarios.asignar-roles']);

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $ultimo), [
                'name' => 'Nombre nuevo',
                'email' => $ultimo->email,
                'roles' => ['secretaria'],
            ])
            ->assertSessionHasErrors(['roles' => 'No se puede retirar el rol de administrador al último administrador del sistema.']);

        // Tampoco quitando todos los roles (formulario sin casillas marcadas).
        $this->put(route('admin.usuarios.update', $ultimo), [
            'name' => 'Nombre nuevo',
            'email' => $ultimo->email,
        ])->assertSessionHasErrors('roles');

        $ultimo->refresh();
        $this->assertTrue($ultimo->hasRole('admin'));
        $this->assertNotSame('Nombre nuevo', $ultimo->name, 'La operación rechazada no debe aplicar cambios parciales.');
    }

    public function test_an_admin_cannot_remove_their_own_admin_role_even_if_others_exist(): void
    {
        $yo = $this->makeUser('admin');
        $this->makeUser('admin');

        $this->actingAs($yo)
            ->put(route('admin.usuarios.update', $yo), [
                'name' => $yo->name,
                'email' => $yo->email,
                'roles' => ['coordinador'],
            ])
            ->assertSessionHasErrors(['roles' => 'No puedes retirarte a ti mismo el rol de administrador. Pídeselo a otro administrador.']);

        $this->put(route('admin.usuarios.update', $yo), [
            'name' => $yo->name,
            'email' => $yo->email,
        ])->assertSessionHasErrors('roles');

        $this->assertTrue($yo->fresh()->hasRole('admin'));
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_an_admin_can_still_edit_their_own_profile_keeping_the_role(): void
    {
        $yo = $this->makeUser('admin');

        $this->actingAs($yo)
            ->put(route('admin.usuarios.update', $yo), [
                'name' => 'Nombre actualizado',
                'email' => $yo->email,
                'roles' => ['admin'],
            ])
            ->assertRedirect(route('admin.usuarios.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Nombre actualizado', $yo->fresh()->name);
        $this->assertTrue($yo->fresh()->hasRole('admin'));
    }

    public function test_an_admin_can_demote_another_admin_while_one_remains(): void
    {
        $uno = $this->makeUser('admin');
        $dos = $this->makeUser('admin');

        $this->actingAs($uno)
            ->put(route('admin.usuarios.update', $dos), [
                'name' => $dos->name,
                'email' => $dos->email,
                'roles' => ['secretaria'],
            ])
            ->assertRedirect(route('admin.usuarios.index'));

        $this->assertFalse($dos->fresh()->hasRole('admin'));
        $this->assertTrue($dos->fresh()->hasRole('secretaria'));
        $this->assertSame(1, app(AdminProtection::class)->activeAdminCount());
    }

    /**
     * Secuencia de dos operaciones "cruzadas": cada administrador intenta degradar al otro.
     * Solo la primera puede prosperar; la segunda se encuentra con un único administrador.
     * (La carrera real entre transacciones simultáneas se verificará en PostgreSQL desechable.)
     */
    public function test_crossed_demotions_never_leave_the_system_without_admins(): void
    {
        $uno = $this->makeUser('admin');
        $dos = $this->makeUser('admin');
        $gestor = $this->makeUser('coordinador', ['usuarios.editar', 'usuarios.asignar-roles']);

        $this->actingAs($gestor)
            ->put(route('admin.usuarios.update', $uno), ['name' => $uno->name, 'email' => $uno->email, 'roles' => ['secretaria']])
            ->assertRedirect(route('admin.usuarios.index'));

        $this->put(route('admin.usuarios.update', $dos), ['name' => $dos->name, 'email' => $dos->email, 'roles' => ['secretaria']])
            ->assertSessionHasErrors('roles');

        $this->assertSame(1, app(AdminProtection::class)->activeAdminCount());
        $this->assertTrue($dos->fresh()->hasRole('admin'));
    }

    public function test_non_admin_users_can_still_be_managed_normally(): void
    {
        $admin = $this->makeUser('admin');
        $objetivo = $this->makeUser('secretaria');

        $this->actingAs($admin)
            ->put(route('admin.usuarios.update', $objetivo), [
                'name' => $objetivo->name,
                'email' => $objetivo->email,
                'roles' => ['coordinador'],
            ])
            ->assertRedirect(route('admin.usuarios.index'));
        $this->assertTrue($objetivo->fresh()->hasRole('coordinador'));

        $this->delete(route('admin.usuarios.destroy', $objetivo))->assertRedirect(route('admin.usuarios.index'));
        $this->assertModelMissing($objetivo);
    }

    public function test_user_update_audit_records_previous_roles(): void
    {
        $admin = $this->makeUser('admin');
        $objetivo = $this->makeUser('secretaria');

        $this->actingAs($admin)->put(route('admin.usuarios.update', $objetivo), [
            'name' => $objetivo->name,
            'email' => $objetivo->email,
            'roles' => ['coordinador'],
        ]);

        $log = AuditLog::where('action', 'user_updated')->latest('id')->firstOrFail();
        $this->assertSame(['secretaria'], $log->changes['before']['roles']);
        $this->assertSame(['coordinador'], $log->changes['after']['roles']);
    }

    // ---- Serialización ----------------------------------------------------------------------

    public function test_exclusive_section_runs_in_a_transaction_that_rolls_back_on_failure(): void
    {
        $protection = app(AdminProtection::class);
        $nivel = DB::transactionLevel();

        try {
            $protection->runExclusively(function () use ($nivel): void {
                $this->assertGreaterThan($nivel, DB::transactionLevel());
                User::factory()->create(['email' => 'transitorio@example.com']);

                throw new RuntimeException('fallo simulado');
            });
            $this->fail('La excepción debía propagarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('fallo simulado', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'transitorio@example.com']);
    }

    public function test_exclusive_section_reads_the_admin_role_row_first(): void
    {
        $consultas = [];
        DB::listen(function ($query) use (&$consultas): void {
            $consultas[] = $query->sql;
        });

        app(AdminProtection::class)->runExclusively(fn () => null);

        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql): bool => str_contains($sql, 'from "roles"') && str_contains($sql, '"name" = ?'),
        ));
    }

    // ---- Caché de permisos de Spatie --------------------------------------------------------
    //
    // `DB::afterCommit()` con RefreshDatabase: Laravel trata la transacción de la prueba como raíz, y el
    // `DB::transaction()` del controlador (un savepoint) se comporta como la transacción real: sus callbacks
    // se ejecutan al confirmarla y se descartan si hay rollback. Lo que SQLite NO demuestra es lo que ocurre
    // entre conexiones de PostgreSQL (otra petición leyendo datos aún no confirmados); aquí se simula esa
    // petición escribiendo la caché a mano entre el cambio y el commit (docs/seguridad-l1.1.md, sección 7).

    public function test_role_update_forgets_the_permission_cache_after_the_commit(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        $this->refillPermissionCacheBeforeCommit('CACHE-OBSOLETA');

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $rol), ['name' => 'auditor', 'permissions' => ['auditoria.ver']])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Cache::has(app(PermissionRegistrar::class)->cacheKey), 'La caché rellenada antes del commit debe invalidarse después.');
        $this->assertTrue($rol->fresh()->hasPermissionTo('auditoria.ver'));
    }

    public function test_role_deletion_forgets_the_permission_cache_after_the_commit(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        $rol->givePermissionTo('auditoria.ver');
        $this->refillPermissionCacheBeforeCommit('CACHE-OBSOLETA');

        $this->actingAs($admin)
            ->delete(route('admin.roles.destroy', $rol))
            ->assertRedirect(route('admin.roles.index'));

        $this->assertFalse(Cache::has(app(PermissionRegistrar::class)->cacheKey));
        $this->assertDatabaseMissing('roles', ['id' => $rol->id]);
    }

    public function test_a_rolled_back_role_change_does_not_forget_the_cache_and_the_error_is_not_hidden(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        $clave = app(PermissionRegistrar::class)->cacheKey;

        // Tras modificar el rol: otra petición rellena la caché y la operación falla antes del commit.
        $this->refillPermissionCacheBeforeCommit('CACHE-VIGENTE');
        AuditLog::created(static function (): void {
            throw new RuntimeException('fallo-antes-del-commit');
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->put(route('admin.roles.update', $rol), ['name' => 'renombrado', 'permissions' => ['auditoria.ver']]);
            $this->fail('La excepción debía propagarse, no ocultarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('fallo-antes-del-commit', $e->getMessage());
        }

        $this->assertSame('CACHE-VIGENTE', Cache::get($clave), 'Un rollback no debe vaciar la caché: la invalidación diferida se descarta.');
        $this->assertSame('auditor', $rol->fresh()->name, 'El cambio de nombre debe haberse revertido.');
        $this->assertSame(0, $rol->fresh()->permissions()->count(), 'Los permisos deben haberse revertido.');
    }

    public function test_a_rejected_admin_role_change_leaves_permissions_and_cache_untouched(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::findByName('admin');
        $total = $rol->permissions()->count();
        app(PermissionRegistrar::class)->getPermissions(); // la caché ya está rellena y vigente

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $rol), ['name' => 'admin', 'permissions' => ['reportes.ver']])
            ->assertSessionHasErrors('permissions');

        $this->assertTrue(Cache::has(app(PermissionRegistrar::class)->cacheKey), 'Un rechazo previo al cambio no invalida la caché.');
        $this->assertSame($total, $rol->fresh()->permissions()->count());
    }

    /** Simula otra petición que vuelve a llenar la caché con los datos aún no confirmados. */
    private function refillPermissionCacheBeforeCommit(string $valor): void
    {
        AuditLog::created(static function () use ($valor): void {
            Cache::put(app(PermissionRegistrar::class)->cacheKey, $valor);
        });
    }

    /**
     * @param  list<string>  $extraPermissions
     */
    private function makeUser(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        foreach ($extraPermissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
