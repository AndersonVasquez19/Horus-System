<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Http\Middleware\AutenticarBffPanel;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base de las pruebas de /api/panel/v1: llama como lo haría el BFF de apps/web (token de servicio, IP del
 * visitante y la cookie de sesión que el BFF reenvía).
 */
abstract class PanelApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    protected const PASSWORD = 'Clave-Segura-2026!';

    /** Valor descifrado de la cookie de sesión, como la guardaría el navegador a través del BFF. */
    protected ?string $cookieSesion = null;

    protected string $ipCliente = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();
        config(['horus-web.service_token' => self::TOKEN]);
    }

    /**
     * ValidateCsrfToken se salta durante las pruebas unitarias; aquí se fuerza para comprobar el contrato real.
     */
    protected function exigirCsrf(): void
    {
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    protected function panel(string $metodo, string $ruta, array $datos = [], array $cabeceras = []): TestResponse
    {
        $peticion = $this->withHeaders([
            'X-Horus-Web-Token' => self::TOKEN,
            AutenticarBffPanel::CABECERA_IP => $this->ipCliente,
            ...$cabeceras,
        ]);

        if ($this->cookieSesion !== null) {
            $peticion = $peticion->withCookie((string) config('session.cookie'), $this->cookieSesion);
        }

        $respuesta = $peticion->json($metodo, '/api/panel/v1/'.ltrim($ruta, '/'), $datos);

        $nueva = $respuesta->getCookie((string) config('session.cookie'), decrypt: true);
        if ($nueva !== null) {
            $this->cookieSesion = $nueva->getValue();
        }

        return $respuesta;
    }

    protected function tokenCsrf(): string
    {
        return (string) $this->panel('GET', 'auth/csrf')->assertOk()->json('csrf_token');
    }

    protected function iniciarSesion(string $email, string $password = self::PASSWORD): TestResponse
    {
        return $this->panel('POST', 'auth/login', ['email' => $email, 'password' => $password], [
            'X-CSRF-TOKEN' => $this->tokenCsrf(),
        ]);
    }

    protected function usuarioConRol(string $rol, string $email): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['email' => $email, 'password' => self::PASSWORD]);
        $user->assignRole($rol);

        return $user;
    }
}
