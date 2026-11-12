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

    public function test_web_validation_keeps_only_form_fields_in_old_input(): void
    {
        $data = $this->registration('  Nueva@Example.INVALID ');
        $data['password_confirmation'] = 'DistintaClave123';

        $this->from(route('register'))->post(route('register.post'), $data + [
            'access_token' => 'synthetic-token', 'api_key' => 'synthetic-key',
            'credentials' => ['secret' => 'synthetic-secret'],
        ])->assertRedirect(route('register'))->assertSessionHasErrors('password')
            ->assertSessionHas('_old_input', [
                'name' => $data['name'], 'email' => 'nueva@example.invalid', 'terms' => '1',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_duplicate_web_registration_also_minimizes_old_input(): void
    {
        User::factory()->create(['email' => 'existente@example.invalid']);

        $this->from(route('register'))->post(route('register.post'),
            $this->registration('existente@example.invalid') + ['access_token' => 'synthetic-token'],
        )->assertRedirect(route('register'))
            ->assertSessionHasErrors(['email' => 'Ya existe una cuenta con este correo.'])
            ->assertSessionMissing('_old_input.access_token')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_validation_does_not_flash_unexpected_query_parameters(): void
    {
        $data = $this->registration('nueva@example.invalid');
        $data['password_confirmation'] = 'DistintaClave123';

        $this->from(route('register'))->post(route('register.post').'?access_token=synthetic-token', $data)
            ->assertRedirect(route('register'))->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.access_token');
    }

    #[DataProvider('registrationRoutes')]
    public function test_existing_email_remains_an_explicit_validation_error_without_mutation(string $route): void
    {
        $user = User::factory()->create(['email' => 'Existente@Example.INVALID']);
        $before = $user->refresh()->getRawOriginal();

        $this->postJson(route($route), $this->registration('  EXISTENTE@Example.INVALID '))
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Ya existe una cuenta con este correo.',
                'errors' => ['email' => ['Ya existe una cuenta con este correo.']],
            ]);

        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    #[DataProvider('registrationRoutes')]
    public function test_five_duplicate_attempts_exhaust_the_existing_limit_and_it_expires(string $route): void
    {
        User::factory()->create(['email' => 'existente@example.invalid']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route($route), $this->registration('existente@example.invalid'))
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->postJson(route($route), $this->registration('nueva@example.invalid'))
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('users', 1);

        $this->travel(61)->seconds();
        $this->postJson(route($route), $this->registration('existente@example.invalid'))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_guest_registration_limit_is_shared_between_web_and_api(): void
    {
        User::factory()->create(['email' => 'existente@example.invalid']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $route = $attempt % 2 === 0 ? 'register.post' : 'api.auth.register';
            $this->postJson(route($route), $this->registration('existente@example.invalid'))
                ->assertUnprocessable();
        }

        $this->postJson(route('api.auth.register'), $this->registration('nueva@example.invalid'))
            ->assertTooManyRequests();
        $this->assertDatabaseCount('users', 1);
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
