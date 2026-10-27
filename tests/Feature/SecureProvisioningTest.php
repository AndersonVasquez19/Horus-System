<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecureProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Prueba-Segura-2026';

    public function test_base_seeders_create_no_users_and_are_repeatable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Estudiante::count());
        $this->assertSame(0, Curso::count());
        $this->assertSame(count(RolesAndPermissionsSeeder::PERMISSIONS), Permission::count());
        $this->assertSame(3, Role::count());
    }

    public function test_seeders_keep_the_existing_permission_matrix(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            RolesAndPermissionsSeeder::PERMISSIONS,
            Role::findByName('admin')->permissions->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS,
            Role::findByName('coordinador')->permissions->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS,
            Role::findByName('secretaria')->permissions->pluck('name')->all(),
        );
    }

    public function test_reseeding_does_not_touch_existing_users_passwords_or_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $existente = User::factory()->create([
            'email' => 'admin@horusgroup.com',
            'password' => 'Clave-Propia-123',
        ]);
        $existente->assignRole('secretaria');
        $hash = $existente->fresh()->password;

        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $existente->refresh();
        $this->assertSame($hash, $existente->password);
        $this->assertTrue(Hash::check('Clave-Propia-123', $existente->password));
        $this->assertSame(['secretaria'], $existente->getRoleNames()->all());
        $this->assertSame(1, User::count());
    }

    public function test_reseeding_keeps_settings_edited_from_the_panel(): void
    {
        $this->seed(DatabaseSeeder::class);
        SystemSetting::where('key', 'telefono')->update(['value' => '+51 900 000 000']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('+51 900 000 000', SystemSetting::where('key', 'telefono')->value('value'));
    }

    public function test_demo_data_is_separate_and_creates_no_users(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(0, Curso::count());
        $this->assertGreaterThan(0, Estudiante::count());
        $this->assertSame(0, User::count());
    }

    public function test_demo_data_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        try {
            $this->app->make(DemoDataSeeder::class)->run();
        } finally {
            $this->assertSame(0, Curso::count());
        }
    }

    public function test_command_creates_a_staff_user_without_printing_the_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('horus:crear-usuario', ['--email' => 'Jefa@Example.com', '--name' => 'Jefa', '--rol' => 'admin'])
            ->expectsQuestion('Contraseña (no se mostrará)', self::STRONG_PASSWORD)
            ->expectsQuestion('Repite la contraseña', self::STRONG_PASSWORD)
            ->expectsOutput('Cuenta creada con el rol admin.')
            ->doesntExpectOutputToContain(self::STRONG_PASSWORD)
            ->assertSuccessful();

        $user = User::where('email', 'jefa@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->password));
        $this->assertNotSame(self::STRONG_PASSWORD, $user->password);
    }

    public function test_command_never_modifies_an_existing_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $existente = User::factory()->create(['email' => 'jefa@example.com', 'password' => 'Clave-Propia-123']);
        $hash = $existente->fresh()->password;

        $this->artisan('horus:crear-usuario', ['--email' => 'jefa@example.com', '--name' => 'Otra', '--rol' => 'admin'])
            ->expectsOutput('Ya existe una cuenta con ese correo. No se modificó.')
            ->assertFailed();

        $existente->refresh();
        $this->assertSame($hash, $existente->password);
        $this->assertFalse($existente->hasRole('admin'));
    }

    public function test_command_rejects_weak_or_mismatched_passwords_and_unknown_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('horus:crear-usuario', ['--email' => 'a@example.com', '--name' => 'A'])
            ->expectsQuestion('Contraseña (no se mostrará)', 'corta')
            ->expectsQuestion('Repite la contraseña', 'corta')
            ->assertFailed();

        $this->artisan('horus:crear-usuario', ['--email' => 'a@example.com', '--name' => 'A'])
            ->expectsQuestion('Contraseña (no se mostrará)', self::STRONG_PASSWORD)
            ->expectsQuestion('Repite la contraseña', self::STRONG_PASSWORD.'x')
            ->assertFailed();

        $this->artisan('horus:crear-usuario', ['--email' => 'a@example.com', '--name' => 'A', '--rol' => 'superusuario'])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_command_requires_seeded_roles(): void
    {
        $this->artisan('horus:crear-usuario', ['--email' => 'a@example.com', '--name' => 'A'])
            ->expectsOutputToContain('RolesAndPermissionsSeeder')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
