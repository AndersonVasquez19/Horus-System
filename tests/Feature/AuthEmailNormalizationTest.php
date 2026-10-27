<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * El correo de login/registro se normaliza (espacios y mayúsculas) igual que antes de ListFilter,
 * y un valor que no es texto se rechaza con error de validación, nunca con un 500. Datos ficticios.
 */
class AuthEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_accepts_email_with_spaces_and_uppercase(): void
    {
        User::factory()->create(['email' => 'persona@example.invalid', 'password' => Hash::make('ClaveSegura123')]);

        $this->post(route('login.post'), ['email' => "  PERSONA@Example.INVALID \t", 'password' => 'ClaveSegura123'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_login_rejects_wrong_password_with_the_same_message(): void
    {
        User::factory()->create(['email' => 'persona@example.invalid', 'password' => Hash::make('ClaveSegura123')]);

        $this->post(route('login.post'), ['email' => 'persona@example.invalid', 'password' => 'otra'])
            ->assertSessionHasErrors(['email' => 'Las credenciales ingresadas no son válidas.']);
        $this->assertGuest();
    }

    public function test_registration_stores_the_normalized_email(): void
    {
        $this->post(route('register.post'), [
            'name' => 'Cuenta Ficticia', 'email' => '  Nueva@Example.INVALID ',
            'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123', 'terms' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['email' => 'nueva@example.invalid']);
    }

    public function test_non_text_email_is_a_validation_error_on_login_and_registration(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([['x'], 123, true, null, ''] as $valor) {
            $this->postJson(route('login.post'), ['email' => $valor, 'password' => 'x'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->postJson(route('register.post'), [
                'name' => 'Ana', 'email' => $valor, 'password' => 'ClaveSegura123',
                'password_confirmation' => 'ClaveSegura123', 'terms' => '1',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_password_recovery_and_reset_still_work(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'persona@example.invalid']);

        $this->post(route('password.email'), ['email' => 'persona@example.invalid'])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $token = Password::createToken($user);
        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'persona@example.invalid',
            'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NuevaClave123', $user->fresh()->password));
    }
}
