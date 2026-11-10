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

    public function test_un_inicio_de_sesion_correcto_se_audita_con_el_actor(): void
    {
        $user = $this->usuario();

        $this->post(route('login.post'), ['email' => 'persona@example.test', 'password' => self::CLAVE])
            ->assertRedirect();

        $registro = AuditLog::where('action', 'login')->sole();
        $this->assertSame($user->id, $registro->user_id);
        $this->assertSame($user->id, (int) $registro->model_id);
        $this->assertTrue($registro->changes['personal_panel']);
    }

    public function test_un_intento_fallido_no_guarda_correo_ni_contrasena_en_claro(): void
    {
        $this->usuario();

        $this->post(route('login.post'), ['email' => 'persona@example.test', 'password' => 'incorrecta-123'])
            ->assertSessionHasErrors('email');
        $this->post(route('login.post'), ['email' => 'nadie@example.test', 'password' => 'incorrecta-123'])
            ->assertSessionHasErrors('email');

        $registros = AuditLog::where('action', 'login_failed')->orderBy('id')->get();
        $this->assertCount(2, $registros);
        $this->assertNull($registros[0]->user_id);
        $this->assertTrue($registros[0]->changes['cuenta_existente']);
        $this->assertFalse($registros[1]->changes['cuenta_existente']);
        $this->assertNotSame($registros[0]->changes['huella_correo'], $registros[1]->changes['huella_correo']);

        $crudo = json_encode(AuditLog::all()->map->getAttributes()->all());
        $this->assertStringNotContainsString('persona@example.test', (string) $crudo);
        $this->assertStringNotContainsString('nadie@example.test', (string) $crudo);
        $this->assertStringNotContainsString('incorrecta-123', (string) $crudo);
    }

    public function test_el_cierre_de_sesion_se_audita(): void
    {
        $user = $this->usuario();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $registro = AuditLog::where('action', 'logout')->sole();
        $this->assertSame($user->id, $registro->user_id);
    }

    public function test_el_restablecimiento_de_contrasena_se_audita_sin_secretos(): void
    {
        $user = $this->usuario();
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'persona@example.test',
            'password' => 'Otra-Clave-Segura-2026!y',
            'password_confirmation' => 'Otra-Clave-Segura-2026!y',
        ])->assertRedirect(route('login'));

        $registro = AuditLog::where('action', 'password_reset')->sole();
        $this->assertSame($user->id, $registro->user_id);

        $crudo = json_encode($registro->getAttributes());
        $this->assertStringNotContainsString($token, (string) $crudo);
        $this->assertStringNotContainsString('Otra-Clave', (string) $crudo);
    }
}
