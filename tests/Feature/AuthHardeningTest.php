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
use Tests\TestCase;

/**
 * Regresiones de la auditoría de login/registro. Datos ficticios; SQLite en memoria.
 */
class AuthHardeningTest extends TestCase
{
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

    public function test_registration_assigns_no_role_and_cannot_enter_the_panel(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->post(route('register.post'), $this->registroValido())->assertRedirect(route('login'));

        $user = User::where('email', 'nueva@example.invalid')->firstOrFail();
        $this->assertCount(0, $user->roles);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

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

    public function test_panel_stores_a_normalized_email_so_the_account_can_sign_in(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.usuarios.store'), [
            'name' => 'Persona Panel', 'email' => '  Juan@Example.INVALID ',
            'password' => self::STRONG, 'password_confirmation' => self::STRONG,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'juan@example.invalid']);

        auth()->logout();
        $this->post(route('login.post'), ['email' => 'Juan@Example.INVALID', 'password' => self::STRONG])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_email_uniqueness_ignores_case_against_existing_rows(): void
    {
        User::factory()->create(['email' => 'Existente@Example.INVALID']);

        $this->post(route('register.post'), $this->registroValido('existente@example.invalid'))
            ->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    // --- Contraseña: una sola política ------------------------------------------------------

    public function test_panel_rejects_weak_passwords_on_create_and_update(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.usuarios.store'), [
            'name' => 'Débil', 'email' => 'debil@example.invalid',
            'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa',
        ])->assertSessionHasErrors('password');

        $this->actingAs($admin)->put(route('admin.usuarios.update', $target), [
            'name' => $target->name, 'email' => $target->email,
            'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa',
        ])->assertSessionHasErrors('password');
    }

    public function test_reset_rejects_a_password_the_registration_would_reject(): void
    {
        $user = User::factory()->create(['email' => 'persona@example.invalid']);

        $this->post(route('password.store'), [
            'token' => Password::createToken($user), 'email' => 'persona@example.invalid',
            'password' => 'Abcdef12', 'password_confirmation' => 'Abcdef12',
        ])->assertSessionHasErrors('password');
    }

    // --- Recuperación ------------------------------------------------------------------------

    public function test_forgot_password_does_not_reveal_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'persona@example.invalid']);

        $this->post(route('password.email'), ['email' => 'desconocida@example.invalid'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        Notification::assertNothingSent();

        $this->post(route('password.email'), ['email' => '  PERSONA@Example.INVALID '])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        Notification::assertSentTo($user, \App\Notifications\ResetPasswordNotification::class);
    }

    public function test_reset_accepts_uppercase_email_and_revokes_jwt(): void
    {
        $user = User::factory()->create(['email' => 'persona@example.invalid']);
        $versionAntes = (int) $user->jwt_version;

        $this->post(route('password.store'), [
            'token' => Password::createToken($user), 'email' => 'PERSONA@Example.INVALID',
            'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('NuevaClave123', $user->password));
        $this->assertSame($versionAntes + 1, (int) $user->jwt_version);
    }

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

        $this->actingAs($admin)->put(route('admin.usuarios.update', $target), [
            'name' => $target->name, 'email' => $target->email,
            'password' => 'OtraClave12345', 'password_confirmation' => 'OtraClave12345',
        ])->assertSessionHasNoErrors();

        $this->withToken($token)->getJson(route('api.auth.me'))->assertUnauthorized();
    }

    public function test_password_reset_deletes_the_database_sessions_of_that_account_only(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'persona@example.invalid']);
        $other = User::factory()->create();
        foreach ([['s-user-1', $user], ['s-user-2', $user], ['s-other', $other]] as [$id, $owner]) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time(),
            ]);
        }

        $this->post(route('password.store'), [
            'token' => Password::createToken($user), 'email' => 'persona@example.invalid',
            'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 's-user-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 's-user-2']);
        $this->assertDatabaseHas('sessions', ['id' => 's-other']);
    }

    public function test_panel_password_change_deletes_sessions_of_the_edited_account(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $target = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 's-target', 'user_id' => $target->id, 'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->actingAs($admin)->put(route('admin.usuarios.update', $target), [
            'name' => $target->name, 'email' => $target->email,
            'password' => 'OtraClave12345', 'password_confirmation' => 'OtraClave12345',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 's-target']);
    }

    // --- Cuenta sin rol ----------------------------------------------------------------------

    public function test_signed_in_account_without_a_role_sees_a_logout_button(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('home'))->assertOk()->assertSee('Cerrar sesión');
        $this->get(route('home'))->assertOk();
    }

    public function test_roleless_login_does_not_keep_the_intended_admin_url(): void
    {
        User::factory()->create(['email' => 'sinrol@example.invalid', 'password' => self::STRONG]);

        $this->withSession(['url.intended' => url('/admin')])
            ->post(route('login.post'), ['email' => 'sinrol@example.invalid', 'password' => self::STRONG])
            ->assertRedirect(route('home'))
            ->assertSessionMissing('url.intended');
    }
}
