<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * horus:promover-admin: promoción local explícita. SQLite en memoria; nunca toca una base real.
 * El nombre del entorno y el host se simulan en la configuración de la prueba.
 */
class PromoverAdminLocalTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'companero@example.invalid';

    private function cuenta(string $email = self::EMAIL): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function entorno(string $nombre): void
    {
        $this->app['env'] = $nombre;
    }

    /** @param array<string, mixed> $extra */
    private function promover(array $extra = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('horus:promover-admin', $extra + ['--email' => self::EMAIL, '--base' => ':memory:']);
    }

    public function test_promotes_an_existing_account_in_the_local_environment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();
        $this->entorno('local');

        $this->promover()->expectsOutputToContain('ahora tiene el rol admin')->assertExitCode(0);

        $this->assertTrue($user->fresh()->hasRole('admin'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_role_granted_local', 'model_id' => $user->id]);
    }

    public function test_also_allowed_in_development_and_in_testing_with_sqlite_memory(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();

        $this->entorno('development');
        $this->promover()->assertExitCode(0);
        $this->assertTrue($user->fresh()->hasRole('admin'));

        $otro = $this->cuenta('otro@example.invalid');
        $this->entorno('testing');
        $this->artisan('horus:promover-admin', ['--email' => 'otro@example.invalid', '--base' => ':memory:'])->assertExitCode(0);
        $this->assertTrue($otro->fresh()->hasRole('admin'));
    }

    public function test_email_is_matched_ignoring_case_and_spaces(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta('Companero@Example.INVALID');
        $this->entorno('local');

        $this->promover(['--email' => '  COMPANERO@example.invalid '])->assertExitCode(0);

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_refuses_forbidden_environments_without_changing_anything(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();

        foreach (['production', 'staging'] as $entorno) {
            $this->entorno($entorno);
            $this->promover()->expectsOutputToContain('no autorizado')->assertExitCode(1);
        }

        $this->entorno('testing');
        config(['database.connections.sqlite.database' => 'archivo-real.sqlite']);
        $this->promover()->expectsOutputToContain('no autorizado')->assertExitCode(1);

        $this->assertFalse($user->fresh()->hasRole('admin'));
        $this->assertSame(0, DB::table('model_has_roles')->count());
    }

    public function test_refuses_a_database_that_is_not_on_this_machine(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();
        $this->entorno('local');
        config(['database.connections.sqlite.host' => 'db.remoto.example.invalid']);

        $this->promover()->expectsOutputToContain('no está en esta máquina')->assertExitCode(1);

        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_accepts_loopback_hosts(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();
        $this->entorno('local');
        config(['database.connections.sqlite.host' => '127.0.0.1']);

        $this->promover()->assertExitCode(0);

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_requires_the_database_name_as_explicit_confirmation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();
        $this->entorno('local');

        $this->promover(['--base' => 'horus_group'])
            ->expectsOutputToContain('no coincide')->assertExitCode(1);

        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_nonexistent_account_is_not_created(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->entorno('local');

        $this->promover()->expectsOutputToContain('No existe una cuenta')->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_reports_missing_roles_and_never_runs_seeders(): void
    {
        $user = $this->cuenta(); // sin sembrar roles
        $this->entorno('local');
        $permisosAntes = DB::table('permissions')->count(); // algunas migraciones ya crean permisos

        $this->promover()
            ->expectsOutputToContain('Faltan roles en esta base: admin, coordinador, secretaria')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('roles')->count());
        $this->assertSame($permisosAntes, DB::table('permissions')->count());
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_reports_only_the_roles_that_are_missing(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        DB::table('roles')->where('name', 'secretaria')->delete();
        $this->cuenta();
        $this->entorno('local');

        $this->promover()->expectsOutputToContain('Faltan roles en esta base: secretaria.')->assertExitCode(1);
    }

    public function test_repeated_promotion_does_not_duplicate_the_assignment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();
        $this->entorno('local');

        $this->promover()->assertExitCode(0);
        $this->promover()->expectsOutputToContain('ya tiene el rol admin')->assertExitCode(0);

        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $user->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'admin_role_granted_local')->count());
    }

    public function test_promoted_account_signs_in_and_reaches_the_panel_after_registering_normally(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->post(route('register.post'), [
            'name' => 'Compañero Ficticio', 'email' => self::EMAIL,
            'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123', 'terms' => '1',
        ])->assertRedirect(route('login'));

        $this->entorno('local');
        $this->promover()->assertExitCode(0);
        $this->entorno('testing');

        $this->post(route('login.post'), ['email' => self::EMAIL, 'password' => 'ClaveSegura123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_there_is_no_http_route_for_the_command(): void
    {
        $rutas = collect(app('router')->getRoutes()->getRoutes())->map->uri()->implode(' ');

        $this->assertStringNotContainsString('promover', $rutas);
    }

    public function test_output_never_contains_credentials(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = $this->cuenta();
        $this->entorno('local');

        Artisan::call('horus:promover-admin', ['--email' => self::EMAIL, '--base' => ':memory:']);
        $salida = Artisan::output();

        $this->assertStringContainsString('ahora tiene el rol admin', $salida);
        $this->assertStringNotContainsString($user->password, $salida);
        $this->assertStringNotContainsString((string) config('app.key'), $salida);    }
}
