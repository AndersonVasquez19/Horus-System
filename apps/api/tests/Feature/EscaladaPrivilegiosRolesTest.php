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
 * Sprint 21.1 (R4): administración de roles sin escalada de privilegios.
 *
 * SQLite en memoria NO reproduce `SELECT ... FOR UPDATE`: no se mide aquí la concurrencia real.
 */
class EscaladaPrivilegiosRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---- 10. Escalada mediante roles.editar --------------------------------------------------

    public function test_roles_editar_cannot_be_used_to_escalate_through_a_role_the_actor_holds(): void
    {
        $gestor = $this->gestorRoles();
        $antes = $this->permisosDe('coordinador');

        // Cadena clásica: añadirse `usuarios.asignar-roles` editando el propio rol y luego asignarse `admin`.
        $this->actingAs($gestor)
            ->put(route('admin.roles.update', Role::findByName('coordinador')), [
                'name' => 'coordinador',
                'permissions' => array_merge($antes, ['usuarios.asignar-roles']),
            ])
            ->assertForbidden();

        $this->assertSame($antes, $this->permisosDe('coordinador'));
        $fila = AuditLog::where('action', 'acceso_denegado')->sole();
        $this->assertSame('R4b', $fila->changes['regla']);
        $this->assertSame('rol_propio_no_editable', $fila->changes['motivo']);
        $this->assertSame($gestor->id, $fila->user_id);
        $this->assertSame(Role::class, $fila->model_type);
        $this->assertNotContains('usuarios.asignar-roles', app(AdminProtection::class)->effectivePermissionNames($gestor));
    }

    public function test_a_non_admin_cannot_edit_the_admin_role_not_even_to_remove_non_critical_permissions(): void
    {
        $gestor = $this->gestorRoles();
        $rol = Role::findByName('admin');
        $antes = $this->permisosDe('admin');
        $sinAuditoria = array_values(array_diff($antes, ['auditoria.ver', 'auditoria.exportar']));

        $this->actingAs($gestor)
            ->put(route('admin.roles.update', $rol), ['name' => 'admin', 'permissions' => $sinAuditoria])
            ->assertForbidden();

        $this->assertSame($antes, $this->permisosDe('admin'));
        $this->assertSame('R4a', AuditLog::where('action', 'acceso_denegado')->sole()->changes['regla']);
    }

    public function test_an_admin_keeps_managing_roles_including_the_admin_role(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::create(['name' => 'auditor', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $rol), ['name' => 'auditor', 'permissions' => ['auditoria.ver', 'usuarios.ver']])
            ->assertRedirect(route('admin.roles.index'));
        $this->assertEqualsCanonicalizing(['auditoria.ver', 'usuarios.ver'], $this->permisosDe('auditor'));

        $sinExportar = array_values(array_diff($this->permisosDe('admin'), ['auditoria.exportar']));
        $this->put(route('admin.roles.update', Role::findByName('admin')), ['name' => 'admin', 'permissions' => $sinExportar])
            ->assertRedirect(route('admin.roles.index'));

        // Las reglas de integridad del rol admin siguen siendo 422 para el administrador.
        $this->put(route('admin.roles.update', Role::findByName('admin')), ['name' => 'admin', 'permissions' => ['reportes.ver']])
            ->assertSessionHasErrors('permissions');
        $this->assertSame(0, AuditLog::where('action', 'acceso_denegado')->count());
    }

    // ---- 11. Concesión de permisos no poseídos (R4c) -----------------------------------------

    public function test_a_non_admin_cannot_grant_permissions_they_do_not_hold_but_can_grant_their_own(): void
    {
        $gestor = $this->gestorRoles();
        $rol = Role::create(['name' => 'analista', 'guard_name' => 'web']);
        $rol->givePermissionTo('cursos.ver');

        $this->actingAs($gestor)
            ->put(route('admin.roles.update', $rol), ['name' => 'analista', 'permissions' => ['cursos.ver', 'usuarios.eliminar', 'auditoria.ver']])
            ->assertForbidden();
        $this->assertSame(['cursos.ver'], $this->permisosDe('analista'));

        $fila = AuditLog::where('action', 'acceso_denegado')->sole();
        $this->assertSame('R4c', $fila->changes['regla']);
        $this->assertEqualsCanonicalizing(['usuarios.eliminar', 'auditoria.ver'], $fila->changes['contexto']['permisos_excedidos']);

        // Solo cuenta lo que se AÑADE: conservar permisos existentes y sumar uno propio es válido.
        $this->put(route('admin.roles.update', $rol), ['name' => 'analista', 'permissions' => ['cursos.ver', 'reportes.ver']])
            ->assertRedirect(route('admin.roles.index'));
        $this->assertEqualsCanonicalizing(['cursos.ver', 'reportes.ver'], $this->permisosDe('analista'));
    }

    public function test_creating_a_role_is_limited_to_the_permissions_the_actor_holds(): void
    {
        $gestor = $this->gestorRoles();

        $this->actingAs($gestor)
            ->post(route('admin.roles.store'), ['name' => 'tomado', 'permissions' => ['reportes.ver', 'usuarios.asignar-roles']])
            ->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'tomado']);

        $this->post(route('admin.roles.store'), ['name' => 'lector', 'permissions' => ['reportes.ver', 'cursos.ver']])
            ->assertRedirect(route('admin.roles.index'));
        $this->assertEqualsCanonicalizing(['reportes.ver', 'cursos.ver'], $this->permisosDe('lector'));
        $this->assertSame($gestor->id, AuditLog::where('action', 'role_created')->sole()->user_id);
    }

    public function test_a_non_admin_cannot_modify_a_role_that_exceeds_their_own_permissions(): void
    {
        $gestor = $this->gestorRoles();
        $rol = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        $rol->givePermissionTo(['auditoria.ver', 'reportes.ver']);

        // Ni siquiera para QUITAR permisos: el rol supera al actor (R4e).
        $this->actingAs($gestor)
            ->put(route('admin.roles.update', $rol), ['name' => 'auditor', 'permissions' => ['reportes.ver']])
            ->assertForbidden();
        $this->delete(route('admin.roles.destroy', $rol))->assertForbidden();

        $this->assertEqualsCanonicalizing(['auditoria.ver', 'reportes.ver'], $this->permisosDe('auditor'));
        $this->assertDatabaseHas('roles', ['name' => 'auditor']);
        $this->assertSame(2, AuditLog::where('changes->regla', 'R4e')->count());
    }

    // ---- 12-13. Roles propios y eliminación de roles asignados -------------------------------

    public function test_a_non_admin_cannot_delete_roles_assigned_to_their_own_account_nor_the_admin_role(): void
    {
        $gestor = $this->gestorRoles();
        $inocuo = Role::create(['name' => 'temporal', 'guard_name' => 'web']);
        $inocuo->givePermissionTo('cursos.ver');

        $this->actingAs($gestor)->delete(route('admin.roles.destroy', Role::findByName('coordinador')))->assertForbidden();
        $this->delete(route('admin.roles.destroy', Role::findByName('admin')))->assertForbidden();

        $this->assertDatabaseHas('roles', ['name' => 'coordinador']);
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
        $this->assertTrue($gestor->fresh()->hasRole('coordinador'));
        $this->assertSame(['R4b', 'R4a'], AuditLog::where('action', 'acceso_denegado')->orderBy('id')->get()->map(fn ($f) => $f->changes['regla'])->all());

        // Un rol dentro del alcance y no asignado al actor se puede eliminar.
        $this->delete(route('admin.roles.destroy', $inocuo))->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseMissing('roles', ['name' => 'temporal']);
    }

    public function test_the_admin_role_still_cannot_be_deleted_by_an_admin(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->delete(route('admin.roles.destroy', Role::findByName('admin')))
            ->assertSessionHasErrors('rol');
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
    }

    // ---- 15. Actor degradado durante la operación --------------------------------------------

    public function test_an_actor_demoted_mid_request_cannot_edit_roles(): void
    {
        $gestor = $this->gestorRoles();
        $rol = Role::create(['name' => 'analista', 'guard_name' => 'web']);

        $degradado = false;
        $nivelBase = DB::transactionLevel(); // solo cuenta la lectura dentro de la sección exclusiva
        Role::retrieved(function (Role $r) use (&$degradado, $gestor, $nivelBase): void {
            if ($r->name === 'admin' && ! $degradado && DB::transactionLevel() > $nivelBase) {
                $degradado = true;
                DB::table('model_has_permissions')->where('model_id', $gestor->id)->delete();
            }
        });

        $this->actingAs($gestor)
            ->put(route('admin.roles.update', $rol), ['name' => 'renombrado', 'permissions' => []])
            ->assertForbidden();

        $this->assertTrue($degradado);
        $this->assertSame('analista', $rol->fresh()->name);
        $this->assertSame('actor_sin_permiso_vigente', AuditLog::where('action', 'acceso_denegado')->sole()->changes['motivo']);
    }

    // ---- 17-19. Atomicidad y caché ------------------------------------------------------------

    public function test_a_failing_audit_rolls_back_role_operations(): void
    {
        $admin = $this->makeUser('admin');
        $rol = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        AuditLog::creating(static function (): void {
            throw new RuntimeException('fallo-de-auditoria');
        });
        $this->withoutExceptionHandling();

        foreach ([
            fn () => $this->actingAs($admin)->post(route('admin.roles.store'), ['name' => 'nuevo', 'permissions' => ['reportes.ver']]),
            fn () => $this->put(route('admin.roles.update', $rol), ['name' => 'renombrado', 'permissions' => ['reportes.ver']]),
            fn () => $this->delete(route('admin.roles.destroy', $rol)),
        ] as $intento) {
            try {
                $intento();
                $this->fail('El fallo de auditoría debía propagarse.');
            } catch (RuntimeException $e) {
                $this->assertSame('fallo-de-auditoria', $e->getMessage());
            }
        }

        $this->assertDatabaseMissing('roles', ['name' => 'nuevo']);
        $this->assertSame('auditor', $rol->fresh()->name);
        $this->assertSame([], $this->permisosDe('auditor'));
    }

    public function test_a_denied_role_change_does_not_touch_the_permission_cache(): void
    {
        $gestor = $this->gestorRoles();
        $clave = app(PermissionRegistrar::class)->cacheKey;
        app(PermissionRegistrar::class)->getPermissions();
        $this->assertTrue(Cache::has($clave));

        $this->actingAs($gestor)
            ->put(route('admin.roles.update', Role::findByName('admin')), ['name' => 'admin', 'permissions' => ['reportes.ver']])
            ->assertForbidden();

        $this->assertTrue(Cache::has($clave), 'Una denegación no invalida la caché.');
    }

    public function test_an_allowed_role_change_forgets_the_cache_after_the_commit(): void
    {
        $gestor = $this->gestorRoles();
        $rol = Role::create(['name' => 'analista', 'guard_name' => 'web']);
        AuditLog::created(static function (): void {
            Cache::put(app(PermissionRegistrar::class)->cacheKey, 'CACHE-OBSOLETA');
        });

        $this->actingAs($gestor)
            ->put(route('admin.roles.update', $rol), ['name' => 'analista', 'permissions' => ['reportes.ver']])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertFalse(Cache::has(app(PermissionRegistrar::class)->cacheKey));
    }

    // ---- 21. Privacidad ----------------------------------------------------------------------

    public function test_role_denial_rows_contain_only_technical_names(): void
    {
        $gestor = $this->gestorRoles();

        $this->actingAs($gestor)
            ->put(route('admin.roles.update', Role::findByName('coordinador')), [
                'name' => 'coordinador', 'permissions' => ['usuarios.asignar-roles'],
                '_token' => 'token-secreto', 'password' => 'ClaveSecreta12345',
            ])
            ->assertForbidden();

        $fila = AuditLog::where('action', 'acceso_denegado')->sole();
        $this->assertSame(['esquema', 'categoria', 'regla', 'motivo', 'operacion', 'contexto'], array_keys($fila->changes));
        $volcado = (string) json_encode($fila->getAttributes());
        foreach (['token-secreto', 'ClaveSecreta12345', $gestor->email, $gestor->name] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $volcado);
        }
    }

    // ---- Auxiliares --------------------------------------------------------------------------

    /** Un coordinador que además gestiona roles, sin ser administrador. */
    private function gestorRoles(): User
    {
        return $this->makeUser('coordinador', ['roles.ver', 'roles.crear', 'roles.editar', 'roles.eliminar']);
    }

    /** @return list<string> */
    private function permisosDe(string $rol): array
    {
        $permisos = Role::findByName($rol)->permissions()->pluck('name')->all();
        sort($permisos);

        return $permisos;
    }

    /** @param  list<string>  $extraPermissions */
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
