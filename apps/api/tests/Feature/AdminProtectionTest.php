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
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\LlamaRolesDelPanel;
use Tests\Support\LlamaUsuariosDelPanel;
use Tests\TestCase;

/**
 * L1.1: el sistema nunca debe quedarse sin administración.
 *
 * SQLite en memoria no reproduce el bloqueo `SELECT ... FOR UPDATE` de PostgreSQL: estas pruebas
 * validan las reglas y la secuencia de operaciones, no la concurrencia real (ver docs/seguridad-l1.1.md).
 */
class AdminProtectionTest extends TestCase
{
    use LlamaRolesDelPanel;
    use LlamaUsuariosDelPanel;
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
            ->actualizarRolPanel($rol, [
                'name' => 'superadmin',
                'permissions' => RolesAndPermissionsSeeder::PERMISSIONS,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'El rol principal "admin" no se puede renombrar.']);

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
            ->actualizarRolPanel($rol, ['name' => 'admin'])
            ->assertJsonValidationErrors('permissions');

        // Quitando solo uno de los permisos críticos.
        $sinAsignarRoles = array_values(array_diff(
            RolesAndPermissionsSeeder::PERMISSIONS,
            ['usuarios.asignar-roles'],
        ));
        $this->actualizarRolPanel($rol, ['name' => 'admin', 'permissions' => $sinAsignarRoles])
            ->assertJsonValidationErrors(['permissions' => 'El rol "admin" no puede perder permisos de administración: usuarios.asignar-roles.']);

        $this->assertSame($total, $rol->fresh()->permissions()->count());
        $this->assertTrue($admin->fresh()->can('usuarios.asignar-roles'));
    }

    public function test_the_admin_role_may_drop_non_critical_permissions_and_keep_its_name(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::findByName('admin');
        $permisos = array_values(array_diff(RolesAndPermissionsSeeder::PERMISSIONS, ['respaldo.exportar']));

        $this->actingAs($admin)
            ->actualizarRolPanel($rol, ['name' => 'admin', 'permissions' => $permisos])
            ->assertSuccessful()
            ->assertJsonMissingValidationErrors();

        $this->assertFalse($rol->fresh()->hasPermissionTo('respaldo.exportar'));
        $this->assertTrue($rol->fresh()->hasPermissionTo('usuarios.asignar-roles'));
    }

    public function test_the_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::findByName('admin');

        $this->actingAs($admin)
            ->eliminarRolPanel($rol)
            ->assertJsonValidationErrors(['rol' => 'No se puede eliminar el rol principal de administración.']);

        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'name' => 'admin']);
    }

    public function test_other_roles_remain_editable_and_deletable(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->crearRolPanel(['name' => 'auditor', 'permissions' => ['auditoria.ver']])
            ->assertSuccessful();
        $auditor = Role::findByName('auditor');

        $this->actualizarRolPanel($auditor, ['name' => 'auditor-externo', 'permissions' => ['auditoria.ver', 'reportes.ver']])
            ->assertSuccessful()
            ->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('roles', ['id' => $auditor->id, 'name' => 'auditor-externo']);

        $this->eliminarRolPanel($auditor)->assertSuccessful();
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

        // Sprint 21.1: un no administrador ya no llega a la regla del último administrador (403, R3).
        $this->actingAs($gestor)
            ->eliminarUsuarioPanel($ultimo)
            ->assertForbidden();

        // La regla de integridad sigue vigente como segunda barrera (p. ej. ante un actor degradado a medias).
        try {
            app(AdminProtection::class)->assertUserCanBeDeleted($ultimo, $gestor);
            $this->fail('Debía rechazar la eliminación del último administrador.');
        } catch (ValidationException $e) {
            $this->assertSame('No se puede eliminar al último administrador del sistema.', $e->errors()['usuario'][0]);
        }

        $this->assertModelExists($ultimo);
        $this->assertTrue($ultimo->fresh()->hasRole('admin'));
    }

    public function test_an_admin_can_be_deleted_when_another_one_remains(): void
    {
        $uno = $this->makeUser('admin');
        $dos = $this->makeUser('admin');

        // Solo un administrador elimina a otro administrador (R3).
        $this->actingAs($dos)
            ->eliminarUsuarioPanel($uno)
            ->assertSuccessful();

        $this->assertModelMissing($uno);
        $this->assertSame(1, app(AdminProtection::class)->activeAdminCount());

        // Ahora $dos es el último: no puede eliminarse (ni a sí mismo ni por otra vía).
        $this->eliminarUsuarioPanel($dos)
            ->assertJsonValidationErrors('usuario');
        $this->assertModelExists($dos);
        $this->expectException(ValidationException::class);
        app(AdminProtection::class)->assertUserCanBeDeleted($dos, null);
    }

    public function test_the_last_admin_cannot_lose_the_admin_role(): void
    {
        $ultimo = $this->makeUser('admin');
        $gestor = $this->makeUser('coordinador', ['usuarios.editar', 'usuarios.asignar-roles']);

        // Sprint 21.1: un no administrador ya no puede tocar al administrador (403, R1/R2).
        $this->actingAs($gestor)
            ->actualizarUsuarioPanel($ultimo, [
                'name' => 'Nombre nuevo',
                'email' => $ultimo->email,
                'roles' => ['secretaria'],
            ])
            ->assertForbidden();

        // La regla del último administrador sigue vigente como segunda barrera (con roles y sin ninguno).
        foreach ([['secretaria'], []] as $nuevos) {
            try {
                app(AdminProtection::class)->assertRoleChangeKeepsAdministration($ultimo, $nuevos, $gestor);
                $this->fail('Debía rechazar retirar el rol al último administrador.');
            } catch (ValidationException $e) {
                $this->assertSame('No se puede retirar el rol de administrador al último administrador del sistema.', $e->errors()['roles'][0]);
            }
        }

        $ultimo->refresh();
        $this->assertTrue($ultimo->hasRole('admin'));
        $this->assertNotSame('Nombre nuevo', $ultimo->name, 'La operación rechazada no debe aplicar cambios parciales.');
    }

    public function test_an_admin_cannot_remove_their_own_admin_role_even_if_others_exist(): void
    {
        $yo = $this->makeUser('admin');
        $this->makeUser('admin');

        $this->actingAs($yo)
            ->actualizarUsuarioPanel($yo, [
                'name' => $yo->name,
                'email' => $yo->email,
                'roles' => ['coordinador'],
            ])
            ->assertJsonValidationErrors(['roles' => 'No puedes retirarte a ti mismo el rol de administrador. Pídeselo a otro administrador.']);

        $this->actualizarUsuarioPanel($yo, [
            'name' => $yo->name,
            'email' => $yo->email,
        ])->assertJsonValidationErrors('roles');

        $this->assertTrue($yo->fresh()->hasRole('admin'));
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_an_admin_can_still_edit_their_own_profile_keeping_the_role(): void
    {
        $yo = $this->makeUser('admin');

        $this->actingAs($yo)
            ->actualizarUsuarioPanel($yo, [
                'name' => 'Nombre actualizado',
                'email' => $yo->email,
                'roles' => ['admin'],
            ])
            ->assertSuccessful()
            ->assertJsonMissingValidationErrors();

        $this->assertSame('Nombre actualizado', $yo->fresh()->name);
        $this->assertTrue($yo->fresh()->hasRole('admin'));
    }

    public function test_an_admin_can_demote_another_admin_while_one_remains(): void
    {
        $uno = $this->makeUser('admin');
        $dos = $this->makeUser('admin');

        $this->actingAs($uno)
            ->actualizarUsuarioPanel($dos, [
                'name' => $dos->name,
                'email' => $dos->email,
                'roles' => ['secretaria'],
            ])
            ->assertSuccessful();

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

        // Un administrador degrada al otro...
        $this->actingAs($uno)
            ->actualizarUsuarioPanel($dos, ['name' => $dos->name, 'email' => $dos->email, 'roles' => ['secretaria']])
            ->assertSuccessful();

        // ...y el que queda no puede quitarse a sí mismo el rol: nunca se llega a cero administradores.
        $this->actualizarUsuarioPanel($uno, ['name' => $uno->name, 'email' => $uno->email, 'roles' => ['secretaria']])
            ->assertJsonValidationErrors('roles');

        $this->assertSame(1, app(AdminProtection::class)->activeAdminCount());
        $this->assertTrue($uno->fresh()->hasRole('admin'));
    }

    public function test_non_admin_users_can_still_be_managed_normally(): void
    {
        $admin = $this->makeUser('admin');
        $objetivo = $this->makeUser('secretaria');

        $this->actingAs($admin)
            ->actualizarUsuarioPanel($objetivo, [
                'name' => $objetivo->name,
                'email' => $objetivo->email,
                'roles' => ['coordinador'],
            ])
            ->assertSuccessful();
        $this->assertTrue($objetivo->fresh()->hasRole('coordinador'));

        $this->eliminarUsuarioPanel($objetivo)->assertSuccessful();
        $this->assertModelMissing($objetivo);
    }

    public function test_user_update_audit_records_previous_roles(): void
    {
        $admin = $this->makeUser('admin');
        $objetivo = $this->makeUser('secretaria');

        $this->actingAs($admin)->actualizarUsuarioPanel($objetivo, [
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
            ->actualizarRolPanel($rol, ['name' => 'auditor', 'permissions' => ['auditoria.ver']])
            ->assertSuccessful()
            ->assertJsonMissingValidationErrors();

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
            ->eliminarRolPanel($rol)
            ->assertSuccessful();

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
            $this->actingAs($admin)->actualizarRolPanel($rol, ['name' => 'renombrado', 'permissions' => ['auditoria.ver']]);
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
            ->actualizarRolPanel($rol, ['name' => 'admin', 'permissions' => ['reportes.ver']])
            ->assertJsonValidationErrors('permissions');

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
