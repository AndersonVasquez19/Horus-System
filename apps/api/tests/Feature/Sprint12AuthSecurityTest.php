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

    public function test_api_registration_ignores_privileged_unexpected_fields(): void
    {
        $this->postJson(route('api.auth.register'), ['name' => 'Prueba', 'email' => 'nueva@example.invalid',
            'password' => 'NuevaPrueba123', 'password_confirmation' => 'NuevaPrueba123', 'roles' => ['admin'],
            'jwt_version' => 999, 'email_verified_at' => now()->toDateTimeString()])->assertCreated();
        $user = User::where('email', 'nueva@example.invalid')->sole();
        $this->assertCount(0, $user->roles);
        $this->assertSame(0, $user->jwt_version);
        $this->assertNull($user->email_verified_at);
        Http::assertNothingSent();
    }

    public static function invalidCanonicalUrls(): array
    {
        return [[''], ['//untrusted.example'], ['https://user:password@horus.example'], ['https://horus.example?redirect=elsewhere']];
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
