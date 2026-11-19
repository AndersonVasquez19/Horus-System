<?php

declare(strict_types=1);

namespace Tests\Academic\Concerns;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Llamadas a `/api/panel/v1/*` desde las pruebas Pest de Horus Academic, igual que las haría el BFF de apps/web (token de
 * servicio + usuario fijado con `actingAs`). Reutiliza el contrato de las pruebas PHPUnit del panel sin duplicar su base.
 *
 * @mixin TestCase
 */
trait LlamaAlPanel
{
    protected const TOKEN_BFF = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    /** Usuario con un rol base y la matriz real del seeder. */
    protected function usuarioConRol(string $rol, ?string $email = null): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $usuario = User::factory()->create($email === null ? [] : ['email' => $email]);
        $usuario->assignRole($rol);

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    protected function panelComo(?User $usuario, string $metodo, string $ruta, array $datos = [], array $cabeceras = []): TestResponse
    {
        config(['horus-web.service_token' => self::TOKEN_BFF]);
        $peticion = $usuario === null ? $this : $this->actingAs($usuario);

        return $peticion->json(
            $metodo,
            '/api/panel/v1/'.ltrim($ruta, '/'),
            $datos,
            ['X-Horus-Web-Token' => self::TOKEN_BFF, ...$cabeceras],
        );
    }
}
