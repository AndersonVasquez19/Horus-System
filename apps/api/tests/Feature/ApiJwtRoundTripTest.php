<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ida y vuelta de JWT (issue + decode) con JwtTokenService en strict_types.
 *
 * AuthenticateJwt convierte cualquier Throwable en 401: un TypeError en decode() rechazaría en
 * silencio todos los tokens válidos. Esta prueba exige que un token recién emitido funcione.
 * Datos ficticios.
 */
class ApiJwtRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_token_authenticates_and_is_revoked_on_logout(): void
    {
        $token = $this->postJson(route('api.auth.register'), [
            'name' => 'Cliente JWT Ficticio',
            'email' => 'jwt.ficticio@example.invalid',
            'password' => 'ClaveSegura123',
            'password_confirmation' => 'ClaveSegura123',
        ])->assertSuccessful()->json('access_token');

        $this->assertIsString($token);

        $this->withToken($token)->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('user.email', 'jwt.ficticio@example.invalid');

        $this->withToken($token)->postJson(route('api.auth.logout'))->assertOk();

        $this->withToken($token)->getJson(route('api.auth.me'))->assertUnauthorized();
    }

    public function test_tampered_token_is_rejected(): void
    {
        $token = $this->postJson(route('api.auth.register'), [
            'name' => 'Cliente JWT Ficticio',
            'email' => 'jwt.alterado@example.invalid',
            'password' => 'ClaveSegura123',
            'password_confirmation' => 'ClaveSegura123',
        ])->json('access_token');

        $this->withToken($token.'x')->getJson(route('api.auth.me'))->assertUnauthorized();
        $this->getJson(route('api.auth.me'))->assertUnauthorized();
    }
}
