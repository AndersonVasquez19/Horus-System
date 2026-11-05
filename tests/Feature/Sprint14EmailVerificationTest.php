<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\VerificarRegistroMail;
use App\Models\RegistroPendiente;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\VerificacionRegistroService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class Sprint14EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['registro-verificacion.enabled' => true, 'registro-verificacion.mailer' => 'array',
            'jwt.secret' => str_repeat('x', 64), 'jwt.issuer' => 'https://horus.example']);
        Mail::fake();
        Http::preventStrayRequests();
    }

    /** @return array<string, string> */
    private function registration(string $email = 'nueva@example.invalid'): array
    {
        return ['name' => 'Nombre inicial', 'email' => $email, 'password' => 'InicialClave123',
            'password_confirmation' => 'InicialClave123', 'terms' => '1'];
    }

    /** @return array<string, string> */
    private function confirmation(string $token): array
    {
        return ['token' => $token, 'name' => 'Nombre confirmado', 'password' => 'DefinitivaClave123',
            'password_confirmation' => 'DefinitivaClave123', 'terms' => '1'];
    }

    private function code(string $email = 'nueva@example.invalid'): string
    {
        $code = '';
        Mail::assertSent(VerificarRegistroMail::class, function (VerificarRegistroMail $mail) use ($email, &$code): bool {
            if (! $mail->hasTo($email)) {
                return false;
            }
            preg_match('/[a-f0-9]{64}/', $mail->render(), $matches);
            $code = $matches[0] ?? '';

            return $code !== '';
        });

        return $code;
    }

    /** @return array<string, array{string}> */
    public static function channels(): array
    {
        return ['web' => ['register.post'], 'api' => ['api.auth.register']];
    }

    #[DataProvider('channels')]
    public function test_registration_is_pending_and_identical_for_new_and_existing_email(string $route): void
    {
        $existing = User::factory()->create(['email' => 'existente@example.invalid']);
        $before = $existing->refresh()->getRawOriginal();
        $new = $this->postJson(route($route), $this->registration('  Nueva@Example.INVALID '))->assertAccepted();
        $known = $this->postJson(route($route), $this->registration('existente@example.invalid'))->assertAccepted();
        $this->assertSame($new->getContent(), $known->getContent());
        $new->assertExactJson(['message' => VerificacionRegistroService::SOLICITUD_RECIBIDA]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('registros_pendientes', 2);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertGuest();
        Mail::assertSentCount(2);
        $raw = json_encode(DB::table('registros_pendientes')->get(), JSON_THROW_ON_ERROR);
        $this->assertFalse(str_contains($raw, 'InicialClave123'));
        $this->assertFalse(str_contains($raw, 'nueva@example.invalid'));
        $this->assertFalse(str_contains($raw, $this->code()));
        $this->assertSame([], RegistroPendiente::firstOrFail()->toArray());
    }

    public function test_web_form_uses_the_same_neutral_redirect_without_logging_in(): void
    {
        User::factory()->create(['email' => 'existente@example.invalid']);
        foreach (['nueva@example.invalid', 'existente@example.invalid'] as $email) {
            $this->from(route('register'))->post(route('register.post'), $this->registration($email))
                ->assertRedirect(route('login'))->assertSessionHasNoErrors()
                ->assertSessionHas('success', VerificacionRegistroService::SOLICITUD_RECIBIDA);
            $this->assertGuest();
        }
    }

    public function test_valid_token_creates_verified_account_with_only_the_final_password_and_no_role(): void
    {
        Event::fake([Verified::class]);
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $token = $this->code();
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($token))
            ->assertOk()->assertExactJson(['message' => VerificacionRegistroService::CONFIRMACION_COMPLETA]);
        $user = User::where('email', 'nueva@example.invalid')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Nombre confirmado', $user->name);
        $this->assertTrue(Hash::check('DefinitivaClave123', $user->password));
        $this->assertFalse(Hash::check('InicialClave123', $user->password));
        $this->assertCount(0, $user->roles);
        $this->assertGuest();
        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($token))->assertUnprocessable();
        $login = $this->postJson(route('api.auth.login'), ['email' => ' NUEVA@Example.INVALID ',
            'password' => 'DefinitivaClave123'])->assertOk();
        $this->withToken($login->json('access_token'))->getJson(route('api.auth.me'))->assertOk();
    }

    public function test_invalid_expired_and_rotated_tokens_do_not_create_accounts(): void
    {
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $old = $this->code();
        $invalid = $this->postJson(route('api.auth.verification.confirm'), $this->confirmation(str_repeat('0', 64)))
            ->assertUnprocessable();
        $this->travel(30)->minutes();
        $expired = $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($old))
            ->assertUnprocessable();
        $this->assertSame($invalid->getContent(), $expired->getContent());
        Mail::fake();
        $this->postJson(route('api.auth.verification.resend'), ['email' => ' NUEVA@Example.INVALID '])->assertAccepted();
        $fresh = $this->code();
        $this->assertFalse(hash_equals($fresh, $old));
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($old))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($fresh))->assertOk();
    }

    public function test_registration_and_resend_share_cooldown_without_rotating_the_current_code(): void
    {
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $original = RegistroPendiente::firstOrFail()->token_hash;
        $this->postJson(route('api.auth.verification.resend'), ['email' => 'nueva@example.invalid'])->assertAccepted();
        $this->postJson(route('register.post'), $this->registration())->assertAccepted();
        Mail::assertSentCount(1);
        $this->assertSame($original, RegistroPendiente::firstOrFail()->token_hash);
        $this->travel(61)->seconds();
        $this->postJson(route('api.auth.verification.resend'), ['email' => 'nueva@example.invalid'])->assertAccepted();
        Mail::assertSentCount(2);
        $this->assertNotSame($original, RegistroPendiente::firstOrFail()->token_hash);
    }

    public function test_existing_verified_account_is_not_overwritten_after_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'existente@example.invalid']);
        $before = $user->refresh()->getRawOriginal();
        $this->postJson(route('api.auth.register'), $this->registration($user->email))->assertAccepted();
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($this->code($user->email)))->assertOk();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_unverified_account_is_blocked_then_confirmed_with_new_credentials_and_revocation(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'nueva@example.invalid', 'password' => 'InicialClave123']);
        config(['registro-verificacion.enabled' => false]);
        $oldJwt = app(JwtTokenService::class)->issue($user)['access_token'];
        config(['registro-verificacion.enabled' => true]);
        $unknown = $this->postJson(route('api.auth.login'), ['email' => 'ausente@example.invalid', 'password' => 'InicialClave123']);
        $blocked = $this->postJson(route('api.auth.login'), ['email' => $user->email, 'password' => 'InicialClave123'])->assertUnprocessable();
        $this->assertSame($unknown->getContent(), $blocked->getContent());
        $this->withToken($oldJwt)->getJson(route('api.auth.me'))->assertUnauthorized();
        $this->post(route('login.post'), ['email' => $user->email, 'password' => 'InicialClave123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($this->code()))->assertOk();
        $this->assertSame(1, $user->fresh()->jwt_version);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->withToken($oldJwt)->getJson(route('api.auth.me'))->assertUnauthorized();
        $this->postJson(route('api.auth.login'), ['email' => $user->email, 'password' => 'DefinitivaClave123'])->assertOk();
    }

    public function test_token_service_refuses_to_issue_for_an_unverified_user(): void
    {
        $this->expectException(RuntimeException::class);
        app(JwtTokenService::class)->issue(User::factory()->unverified()->create());
    }

    public function test_preexisting_unverified_web_session_does_not_reach_authenticated_routes(): void
    {
        $user = $this->adminUser();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_mail_failure_keeps_neutral_response_and_redacts_diagnostics_without_retry(): void
    {
        Log::spy();
        Mail::shouldReceive('mailer')->once()->with('array')->andThrow(new TransportException('synthetic-sensitive-detail'));
        $this->postJson(route('api.auth.register'), $this->registration())
            ->assertAccepted()->assertExactJson(['message' => VerificacionRegistroService::SOLICITUD_RECIBIDA]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('registros_pendientes', 1);
        Log::shouldHaveReceived('warning')->once()->with('auth.registration_verification', ['outcome' => 'delivery_unavailable']);
    }

    public function test_log_transport_is_never_used_for_verification_codes(): void
    {
        config(['registro-verificacion.mailer' => 'log']);
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_reset_does_not_bypass_email_verification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'nueva@example.invalid']);
        $this->post(route('password.store'), ['email' => $user->email, 'token' => Password::createToken($user),
            'password' => 'DefinitivaClave123', 'password_confirmation' => 'DefinitivaClave123'])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->postJson(route('api.auth.login'), ['email' => $user->email, 'password' => 'DefinitivaClave123'])->assertUnprocessable();
    }

    public function test_confirmation_rolls_back_the_account_and_token_if_a_listener_fails(): void
    {
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $code = $this->code();
        Event::listen(Verified::class, static function (): never {
            throw new RuntimeException('synthetic failure');
        });
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($code))->assertServerError();
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(RegistroPendiente::firstOrFail()->consumed_at);
    }

    public function test_request_and_confirmation_limits_reject_excess_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.auth.verification.resend'), ['email' => 'nueva@example.invalid'])->assertAccepted();
        }
        $this->postJson(route('api.auth.verification.resend'), ['email' => 'otra@example.invalid'])->assertTooManyRequests();
        $this->travel(61)->seconds();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson(route('api.auth.verification.confirm'), $this->confirmation(str_repeat('0', 64)))->assertUnprocessable();
        }
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation(str_repeat('0', 64)))->assertTooManyRequests();
    }

    public function test_disabled_mode_keeps_legacy_registration_and_hides_new_endpoints(): void
    {
        config(['registro-verificacion.enabled' => false]);
        $this->postJson(route('api.auth.register'), $this->registration())->assertCreated()->assertJsonStructure(['access_token']);
        $this->postJson(route('api.auth.verification.resend'), ['email' => 'nueva@example.invalid'])->assertNotFound();
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation(str_repeat('0', 64)))->assertNotFound();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('registros_pendientes', 0);
    }

    public function test_feature_is_disabled_in_the_shipped_configuration(): void
    {
        $defaults = require base_path('config/registro-verificacion.php');
        $this->assertFalse($defaults['enabled']);
        $this->assertNull($defaults['mailer']);
    }

    public function test_missing_mailer_does_not_fall_back_to_the_general_mailer(): void
    {
        config(['registro-verificacion.mailer' => null]);
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_native_verification_notification_does_nothing_when_disabled(): void
    {
        config(['registro-verificacion.enabled' => false]);
        User::factory()->unverified()->create()->sendEmailVerificationNotification();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('registros_pendientes', 0);
    }

    public function test_pending_registration_migration_preserves_existing_seeded_users(): void
    {
        $user = $this->adminUser();
        $before = $user->refresh()->getRawOriginal();
        $roles = $user->getRoleNames()->all();
        $migration = require database_path('migrations/2026_10_28_000022_create_registros_pendientes_table.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('registros_pendientes', 0);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($roles, $user->fresh()->getRoleNames()->all());
    }

    public function test_reset_errors_do_not_distinguish_existing_accounts_when_enabled(): void
    {
        User::factory()->unverified()->create(['email' => 'existente@example.invalid']);
        $responses = [];
        foreach (['ausente@example.invalid', 'existente@example.invalid'] as $email) {
            $response = $this->from(route('password.request'))->post(route('password.store'), [
                'email' => $email, 'token' => 'invalid-token', 'password' => 'DefinitivaClave123',
                'password_confirmation' => 'DefinitivaClave123',
            ])->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
            $responses[] = $response->getSession()->get('errors')->getBag('default')->get('email');
        }
        $this->assertSame($responses[0], $responses[1]);
    }

    public function test_email_change_revokes_verification_and_old_jwt_while_preserving_roles(): void
    {
        $user = $this->adminUser();
        $token = app(JwtTokenService::class)->issue($user)['access_token'];
        $roles = $user->getRoleNames()->all();
        $user->email = 'otro@example.invalid';
        $user->save();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(1, $user->fresh()->jwt_version);
        $this->assertSame($roles, $user->fresh()->getRoleNames()->all());
        $this->withToken($token)->getJson(route('api.auth.me'))->assertUnauthorized();
    }

    public function test_confirmation_validation_does_not_flash_token_or_password_and_does_not_consume_token(): void
    {
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $data = $this->confirmation($this->code());
        $data['password_confirmation'] = 'DistintaClave123';
        $this->post(route('verification.confirm'), $data)->assertUnprocessable()
            ->assertSessionMissing('_old_input.token')->assertSessionMissing('_old_input.password');
        $this->assertNull(RegistroPendiente::firstOrFail()->consumed_at);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_old_web_session_cannot_become_usable_after_email_confirmation(): void
    {
        $user = $this->adminUser();
        $user->forceFill(['email' => 'nueva@example.invalid', 'email_verified_at' => null])->save();
        $oldVersion = $user->jwt_version;
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($this->code()))->assertOk();

        // array no permite SessionRevoker::revokeSessions; simula sesión antigua rehidratada.
        $this->actingAs($user->fresh())->withSession(['auth.verification_user' => $user->id,
            'auth.verification_version' => $oldVersion])->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_old_reset_token_from_deleted_account_cannot_reset_the_newly_confirmed_account(): void
    {
        $user = User::factory()->create(['email' => 'nueva@example.invalid']);
        $old = Password::createToken($user);
        $user->delete();
        $this->postJson(route('api.auth.register'), $this->registration())->assertAccepted();
        $this->postJson(route('api.auth.verification.confirm'), $this->confirmation($this->code()))->assertOk();
        $this->post(route('password.store'), ['email' => 'nueva@example.invalid', 'token' => $old,
            'password' => 'AtacanteClave123', 'password_confirmation' => 'AtacanteClave123'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('DefinitivaClave123', User::where('email', 'nueva@example.invalid')->firstOrFail()->password));
    }

    public function test_web_confirmation_and_login_work_only_after_final_password_is_set(): void
    {
        $this->post(route('register.post'), $this->registration())->assertRedirect(route('login'));
        $this->post(route('login.post'), ['email' => 'nueva@example.invalid', 'password' => 'InicialClave123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->postJson(route('verification.confirm'), $this->confirmation($this->code()))->assertOk();
        $this->assertGuest();
        $this->post(route('login.post'), ['email' => 'nueva@example.invalid', 'password' => 'DefinitivaClave123'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    #[DataProvider('channels')]
    public function test_invalid_other_fields_do_not_restore_the_uniqueness_signal(string $route): void
    {
        User::factory()->create(['email' => 'existente@example.invalid']);
        foreach (['ausente@example.invalid', 'existente@example.invalid'] as $email) {
            $data = $this->registration($email);
            unset($data['password'], $data['password_confirmation']);
            $this->postJson(route($route), $data)->assertUnprocessable()
                ->assertJsonValidationErrors('password')->assertJsonMissingValidationErrors('email');
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('registros_pendientes', 0);
    }
}
