<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Fase 1 de la auditoría del panel: inicio de sesión, intento fallido, cierre de sesión y restablecimiento
 * de contraseña quedan en `audit_logs` sin contraseñas, tokens ni correos en claro.
 *
 * SQLite en memoria; no se envía correo real.
 */
class AuditoriaAccesosTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'Clave-Segura-2026!x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    private function usuario(string $correo = 'persona@example.test'): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['email' => $correo, 'password' => Hash::make(self::CLAVE)]);
        $user->assignRole('secretaria');

        return $user;
    }

}
