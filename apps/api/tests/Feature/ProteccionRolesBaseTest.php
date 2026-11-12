<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 1 de la auditoría del panel (#16): los roles base `coordinador` y `secretaria` no se renombran ni
 * se eliminan (como `admin`), pero sus permisos siguen siendo editables desde Roles.
 *
 * SQLite en memoria.
 */
class ProteccionRolesBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    public function test_los_roles_base_no_se_pueden_renombrar(): void
    {
        $admin = $this->adminUser();

        foreach (['coordinador', 'secretaria'] as $nombre) {
            $rol = Role::findByName($nombre, 'web');
            $permisos = $rol->permissions()->pluck('name')->all();

            $this->actingAs($admin)
                ->put(route('admin.roles.update', $rol), ['name' => $nombre.'-renombrado', 'permissions' => $permisos])
                ->assertSessionHasErrors('name');

            $this->assertDatabaseHas('roles', ['name' => $nombre]);
            $this->assertDatabaseMissing('roles', ['name' => $nombre.'-renombrado']);
        }
    }

    public function test_los_roles_base_no_se_pueden_eliminar(): void
    {
        $admin = $this->adminUser();

        foreach (['coordinador', 'secretaria'] as $nombre) {
            $this->actingAs($admin)
                ->delete(route('admin.roles.destroy', Role::findByName($nombre, 'web')))
                ->assertSessionHasErrors('rol');

            $this->assertDatabaseHas('roles', ['name' => $nombre]);
        }
    }

    public function test_los_permisos_de_un_rol_base_siguen_siendo_editables(): void
    {
        $admin = $this->adminUser();
        $rol = Role::findByName('secretaria', 'web');

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $rol), ['name' => 'secretaria', 'permissions' => ['estudiantes.ver']])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSame(['estudiantes.ver'], $rol->fresh()->permissions()->pluck('name')->all());
    }

    public function test_un_rol_personalizado_si_se_puede_renombrar_y_eliminar(): void
    {
        $admin = $this->adminUser();
        $rol = Role::create(['name' => 'auxiliar', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $rol), ['name' => 'auxiliar-b', 'permissions' => []])
            ->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseHas('roles', ['name' => 'auxiliar-b']);

        $this->actingAs($admin)->delete(route('admin.roles.destroy', $rol->fresh()))->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseMissing('roles', ['name' => 'auxiliar-b']);
    }
}
