<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Sprint13RegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        Notification::fake();
    }

    /** @return array<string, string> */
    private function registration(string $email): array
    {
        return [
            'name' => 'Cuenta ficticia', 'email' => $email, 'terms' => '1',
            'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ];
    }

    /** @return array<string, array{string}> */
    public static function registrationRoutes(): array
    {
        return ['web' => ['register.post'], 'api' => ['api.auth.register']];
    }

    public function test_api_success_preserves_its_payload_and_stores_normalized_hashed_credentials(): void
    {
        config(['jwt.secret' => str_repeat('x', 64), 'jwt.issuer' => 'https://horus.example']);

        $response = $this->postJson(route('api.auth.register'), $this->registration('  Nueva@Example.INVALID '))
            ->assertCreated()->assertJsonStructure([
                'user' => ['id', 'name', 'email'], 'access_token', 'token_type', 'expires_in',
            ])->assertJsonPath('user.email', 'nueva@example.invalid');
        $user = User::where('email', 'nueva@example.invalid')->firstOrFail();

        $this->assertEqualsCanonicalizing(['user', 'access_token', 'token_type', 'expires_in'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(['id', 'name', 'email'], array_keys($response->json('user')));
        $this->assertTrue(Hash::check('ClaveSegura123', $user->password));
        $this->assertCount(0, $user->roles);
        $this->assertGuest();
        Notification::assertNothingSent();
    }
}
