<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\CredentialTiming;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Endurecimiento de autenticación: limitadores separados, anti-enumeración, JWT y cabeceras. */
class AuthHardeningSprint22Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        Notification::fake();
    }

    public function test_chat_requests_do_not_consume_the_registration_quota(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('chat.mensaje'), ['mensaje' => 'hola']);
        }

        $this->postJson(route('api.auth.register'), [
            'name' => 'Cuenta', 'email' => 'nueva@example.invalid',
            'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ])->assertStatus(201);
    }

    public function test_password_reset_returns_the_same_error_for_unknown_and_known_emails(): void
    {
        User::factory()->create(['email' => 'existe@example.invalid']);
        $payload = ['token' => 'token-invalido', 'password' => 'NuevaClave12345', 'password_confirmation' => 'NuevaClave12345'];

        $a = $this->from(route('login'))->post(route('password.store'), $payload + ['email' => 'existe@example.invalid']);
        $b = $this->from(route('login'))->post(route('password.store'), $payload + ['email' => 'no-existe@example.invalid']);

        $a->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN)]);
        $b->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN)]);
    }

    public function test_reset_page_is_not_cached_and_sends_no_referrer(): void
    {
        $this->get(route('password.reset', ['token' => 'abc']))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_credential_timing_check_is_false_without_a_hash_and_true_for_a_match(): void
    {
        $hash = Hash::make('ClaveSegura123');

        $this->assertTrue(CredentialTiming::check('ClaveSegura123', $hash));
        $this->assertFalse(CredentialTiming::check('otra', $hash));
        $this->assertFalse(CredentialTiming::check('ClaveSegura123', null));
    }

    public function test_api_login_uses_the_same_error_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'existe@example.invalid', 'password' => Hash::make('ClaveSegura123')]);

        $unknown = $this->postJson(route('api.auth.login'), ['email' => 'nadie@example.invalid', 'password' => 'ClaveSegura123']);
        $wrong = $this->postJson(route('api.auth.login'), ['email' => 'existe@example.invalid', 'password' => 'incorrecta']);

        $unknown->assertUnprocessable();
        $wrong->assertUnprocessable();
        $this->assertSame($unknown->json(), $wrong->json());
    }

    public function test_changing_the_email_revokes_existing_jwt_even_without_verification(): void
    {
        $user = User::factory()->create(['jwt_version' => 3]);
        $token = app(JwtTokenService::class)->issue($user)['access_token'];

        $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$token])->assertOk();

        $user->update(['email' => 'otro@example.invalid']);

        $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_jwt_with_alg_none_or_foreign_secret_is_rejected(): void
    {
        $user = User::factory()->create();
        $claims = [
            'iss' => config('jwt.issuer'), 'aud' => config('jwt.issuer'), 'sub' => (string) $user->id,
            'iat' => time(), 'nbf' => time(), 'exp' => time() + 600, 'ver' => (int) $user->jwt_version,
        ];

        $forged = JWT::encode($claims, str_repeat('x', 40), 'HS256');
        $none = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=').'.'
            .rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.';

        foreach ([$forged, $none] as $token) {
            $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        }
    }

    public function test_jwt_is_not_accepted_from_the_query_string(): void
    {
        $user = User::factory()->create();
        $token = app(JwtTokenService::class)->issue($user)['access_token'];

        $this->getJson(route('api.auth.me').'?access_token='.$token)->assertUnauthorized();
    }

    public function test_jwt_ttl_is_capped(): void
    {
        config(['jwt.ttl' => 99999999]);
        $user = User::factory()->create();

        $this->assertSame(86400, app(JwtTokenService::class)->issue($user)['expires_in']);
    }

    public function test_jwt_leeway_is_restored_after_decoding(): void
    {
        config(['jwt.leeway' => 5000]);
        JWT::$leeway = 0;
        $user = User::factory()->create();
        $token = app(JwtTokenService::class)->issue($user)['access_token'];

        app(JwtTokenService::class)->decode($token);

        $this->assertSame(0, JWT::$leeway);
    }

    public function test_security_headers_are_present(): void
    {
        $this->get(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_password_longer_than_bcrypt_limit_is_rejected(): void
    {
        $largo = 'Aa1'.str_repeat('x', 80);

        $this->post(route('register.post'), [
            'name' => 'Cuenta', 'email' => 'larga@example.invalid', 'terms' => '1',
            'password' => $largo, 'password_confirmation' => $largo,
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }
}
