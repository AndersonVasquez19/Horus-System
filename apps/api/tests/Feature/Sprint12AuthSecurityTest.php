<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Firebase\JWT\JWT;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class Sprint12AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => null]);
        Notification::fake();
        Http::preventStrayRequests();
    }

    public static function canonicalUrls(): array
    {
        return [['https://horus.example'], ['https://horus.example/portal']];
    }

    #[DataProvider('canonicalUrls')]
    public function test_reset_link_uses_canonical_origin_instead_of_untrusted_host(string $canonical): void
    {
        config(['app.url' => $canonical]);
        $user = User::factory()->create(['email' => 'ficticia@example.invalid']);
        $this->post('http://untrusted.example/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user, $canonical): bool {
            $previousUrl = url('/probe');
            $url = $notification->toMail($user)->viewData['url'];
            // No se imprime el enlace ni su token en la salida de la prueba.
            $this->assertTrue(str_starts_with($url, $canonical.'/reset-password/'));
            $this->assertTrue(parse_url($url, PHP_URL_HOST) === 'horus.example');
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertTrue(($query['email'] ?? null) === $user->email);
            $this->assertSame($previousUrl, url('/probe'));

            return true;
        });
        Http::assertNothingSent();
    }

    public function test_repeated_recovery_does_not_disclose_account_existence_or_send_twice(): void
    {
        $user = User::factory()->create(['email' => 'ficticia@example.invalid']);
        $statuses = [];
        foreach ([$user->email, $user->email, 'ausente@example.invalid', 'ausente@example.invalid'] as $email) {
            $this->post(route('password.email'), ['email' => $email])
                ->assertSessionHasNoErrors()->assertSessionHas('status');
            $statuses[] = session('status');
        }
        $this->assertCount(1, array_unique($statuses));
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        Http::assertNothingSent();
    }

    public function test_recovery_delivery_failure_keeps_generic_response_and_private_logs(): void
    {
        User::factory()->create(['email' => 'ficticia@example.invalid']);
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('fake-private-provider-detail'));
        Log::spy();
        $this->post(route('password.email'), ['email' => 'ficticia@example.invalid'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        Log::shouldHaveReceived('warning')->with('auth.password_reset', ['outcome' => 'delivery_unavailable'])->once();
        Http::assertNothingSent();
    }

    public function test_invalid_reset_never_flashes_passwords_tokens_or_unexpected_fields(): void
    {
        $user = User::factory()->create(['email' => 'ficticia@example.invalid']);
        $this->post(route('password.store'), [
            'email' => $user->email, 'token' => 'invalid-test-token',
            'password' => 'NuevaPrueba123', 'password_confirmation' => 'NuevaPrueba123',
            'api_key' => 'fake-sensitive-value', '_token' => 'fake-csrf',
        ])->assertSessionHasErrors('email')->assertSessionHas('_old_input.email', $user->email);
        foreach (['password', 'password_confirmation', 'token', '_token', 'api_key'] as $field) {
            $this->assertFalse(array_key_exists($field, session('_old_input', [])), $field.' fue guardado en sesión');
        }
        Notification::assertNothingSent();
    }

    public function test_reset_failure_rolls_back_password_version_sessions_and_token_consumption(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'ficticia@example.invalid', 'password' => 'AnteriorPrueba123']);
        $user->refresh();
        $version = $user->jwt_version;
        $token = Password::createToken($user);
        DB::table('sessions')->insert(['id' => 'ficticia-session', 'user_id' => $user->id,
            'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        Event::listen(PasswordReset::class, function (): void {
            throw new RuntimeException('Fallo sintético posterior a la revocación');
        });
        $this->postJson(route('password.store'), ['email' => $user->email, 'token' => $token,
            'password' => 'NuevaPrueba123', 'password_confirmation' => 'NuevaPrueba123'])->assertStatus(500);
        $this->assertTrue(Hash::check('AnteriorPrueba123', $user->fresh()->password));
        $this->assertSame($version, $user->fresh()->jwt_version);
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertDatabaseHas('sessions', ['id' => 'ficticia-session', 'user_id' => $user->id]);
        Notification::assertNothingSent();
    }

    public function test_api_registration_ignores_privileged_unexpected_fields(): void
    {
        $this->postJson(route('api.auth.register'), ['name' => 'Prueba', 'email' => 'nueva@example.invalid',
            'password' => 'NuevaPrueba123', 'password_confirmation' => 'NuevaPrueba123', 'roles' => ['admin'],
            'jwt_version' => 999, 'email_verified_at' => now()->toDateTimeString()])->assertCreated();
        $user = User::where('email', 'nueva@example.invalid')->sole();
        $this->assertCount(0, $user->roles);
        $this->assertSame(0, $user->jwt_version);
        $this->assertNull($user->email_verified_at);
        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_reset_validation_failure_does_not_flash_credentials_or_unexpected_fields(): void
    {
        $this->post(route('password.store'), ['email' => 'ficticia@example.invalid', 'token' => 'fake-token',
            'password' => 'weak', 'password_confirmation' => 'different', 'api_key' => 'fake-value'])
            ->assertSessionHasErrors('password')->assertSessionHas('_old_input.email', 'ficticia@example.invalid');
        $this->assertSame(['email'], array_keys(session('_old_input', [])));
    }

    public static function invalidCanonicalUrls(): array
    {
        return [[''], ['//untrusted.example'], ['https://user:password@horus.example'], ['https://horus.example?redirect=elsewhere']];
    }

    #[DataProvider('invalidCanonicalUrls')]
    public function test_invalid_canonical_configuration_never_falls_back_to_request_host(string $canonical): void
    {
        config(['app.url' => $canonical]);
        $this->expectException(LogicException::class);
        (new ResetPasswordNotification('fake-token'))->toMail(User::factory()->make());
    }

    public static function rejectedTokens(): array
    {
        return [['expired'], ['future'], ['issuer'], ['audience'], ['revoked'], ['missing-user'], ['algorithm']];
    }

    #[DataProvider('rejectedTokens')]
    public function test_jwt_endpoint_rejects_invalid_temporal_identity_and_revocation_claims(string $case): void
    {
        config(['jwt.secret' => str_repeat('test-only-key-', 6), 'jwt.leeway' => 0]);
        $user = User::factory()->create();
        $claims = ['iss' => config('jwt.issuer'), 'aud' => config('jwt.issuer'), 'sub' => (string) $user->id,
            'iat' => time(), 'nbf' => time(), 'exp' => time() + 900, 'ver' => 0];
        $claims = array_replace($claims, match ($case) {
            'expired' => ['exp' => time() - 60],
            'future' => ['nbf' => time() + 3600],
            'issuer' => ['iss' => 'untrusted.example'],
            'audience' => ['aud' => 'untrusted.example'],
            'revoked' => ['ver' => 999],
            'missing-user' => ['sub' => '999999999'],
            default => [],
        });
        $token = JWT::encode($claims, config('jwt.secret'), $case === 'algorithm' ? 'HS512' : 'HS256');
        $this->withToken($token)->getJson(route('api.auth.me'))->assertUnauthorized();
        Http::assertNothingSent();
    }
}
