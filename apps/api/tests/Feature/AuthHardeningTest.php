<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\LlamaUsuariosDelPanel;
use Tests\TestCase;

/**
 * Regresiones de la auditoría de login/registro. Datos ficticios; SQLite en memoria.
 */
class AuthHardeningTest extends TestCase
{
    use LlamaUsuariosDelPanel;
    use RefreshDatabase;

    private const STRONG = 'ClaveSegura123';

    /** @return array<string, mixed> */
    private function registroValido(string $email = 'nueva@example.invalid'): array
    {
        return [
            'name' => 'Cuenta Ficticia', 'email' => $email,
            'password' => self::STRONG, 'password_confirmation' => self::STRONG, 'terms' => '1',
        ];
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['email' => 'admin@example.invalid']);
        $admin->assignRole('admin');

        return $admin;
    }

    // --- Rol al crear la cuenta -------------------------------------------------------------

    public function test_api_registration_assigns_no_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->postJson(route('api.auth.register'), [
            'name' => 'Cliente API', 'email' => 'api@example.invalid',
            'password' => self::STRONG, 'password_confirmation' => self::STRONG,
        ])->assertCreated();

        $this->assertCount(0, User::where('email', 'api@example.invalid')->firstOrFail()->roles);
    }

    // --- API: entradas que no son texto -----------------------------------------------------

    public function test_api_register_and_login_reject_non_text_email_without_a_500(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([['x'], 123, true, null, ''] as $valor) {
            $this->postJson(route('api.auth.login'), ['email' => $valor, 'password' => 'x'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->postJson(route('api.auth.register'), [
                'name' => 'Ana', 'email' => $valor,
                'password' => self::STRONG, 'password_confirmation' => self::STRONG,
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->assertSame(0, User::count());
    }

    // --- Correo: normalización y unicidad ---------------------------------------------------

    // --- Contraseña: una sola política ------------------------------------------------------

    public function test_panel_rejects_weak_passwords_on_create_and_update(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->crearUsuarioPanel([
            'name' => 'Débil', 'email' => 'debil@example.invalid',
            'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa',
        ])->assertJsonValidationErrors('password');

        $this->actingAs($admin)->actualizarUsuarioPanel($target, [
            'name' => $target->name, 'email' => $target->email,
            'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa',
        ])->assertJsonValidationErrors('password');
    }

    // --- Recuperación ------------------------------------------------------------------------

    // --- Revocación al cambiar la contraseña -------------------------------------------------

    public function test_panel_password_change_revokes_jwt_tokens(): void
    {
        $admin = $this->admin();
        $token = $this->postJson(route('api.auth.register'), [
            'name' => 'Cliente API', 'email' => 'api@example.invalid',
            'password' => self::STRONG, 'password_confirmation' => self::STRONG,
        ])->assertCreated()->json('access_token');
        $target = User::where('email', 'api@example.invalid')->firstOrFail();

        $this->withToken($token)->getJson(route('api.auth.me'))->assertOk();

        $this->actingAs($admin)->actualizarUsuarioPanel($target, [
            'name' => $target->name, 'email' => $target->email,
            'password' => 'OtraClave12345', 'password_confirmation' => 'OtraClave12345',
        ])->assertJsonMissingValidationErrors();

        $this->withToken($token)->getJson(route('api.auth.me'))->assertUnauthorized();
    }

    public function test_panel_password_change_deletes_sessions_of_the_edited_account(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $target = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 's-target', 'user_id' => $target->id, 'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->actingAs($admin)->actualizarUsuarioPanel($target, [
            'name' => $target->name, 'email' => $target->email,
            'password' => 'OtraClave12345', 'password_confirmation' => 'OtraClave12345',
        ])->assertJsonMissingValidationErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 's-target']);
    }

    // --- Cuenta sin rol ----------------------------------------------------------------------

}
