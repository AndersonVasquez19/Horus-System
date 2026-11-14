<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\SesionesActivas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * Perfil propio y sesiones activas por la API del panel (`/api/panel/v1/compartido/perfil`). Complementa a
 * `CompartidoCuentaPanelTest` con lo que verificaban `PerfilPropioTest` y `SesionesActivasTest` (Blade, retirados el
 * 14/11/2026): normalización del correo, revocación de JWT, sesión actual, límite de intentos y sesiones propias.
 * Las filas de `sessions` se insertan a mano para simular otros dispositivos. SQLite en memoria.
 */
final class PerfilPropioPanelTest extends PanelApiTestCase
{
    private const NUEVA = 'Nueva-Clave-2027!x';

    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)->json($metodo, '/api/panel/v1/compartido/perfil'.$ruta, $datos, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function sesion(?User $user, string $id, int $haceMinutos = 1): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user?->getKey(),
            'ip_address' => '10.0.0.'.random_int(1, 200),
            'user_agent' => 'Navegador de prueba',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->subMinutes($haceMinutos)->getTimestamp(),
        ]);
    }

    // ---------------------------------------------------------------- perfil

    public function test_cambiar_el_correo_con_la_clave_correcta_lo_normaliza_y_revoca_los_jwt(): void
    {
        $yo = $this->usuarioConRol('secretaria', 'antes@example.test');
        $token = app(JwtTokenService::class)->issue($yo)['access_token'];

        $this->como($yo, 'PUT', '', ['name' => $yo->name, 'email' => '  Nuevo@Example.INVALID ', 'current_password' => self::PASSWORD])->assertOk();

        $this->assertSame('nuevo@example.invalid', $yo->fresh()->email);
        $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_cambiar_la_clave_conserva_la_sesion_actual_y_no_toca_otros_campos(): void
    {
        $yo = $this->usuarioConRol('coordinador', 'sesion@example.test');
        $version = (int) $yo->jwt_version;

        $this->como($yo, 'PUT', '', [
            'name' => $yo->name, 'email' => $yo->email, 'current_password' => self::PASSWORD,
            'password' => self::NUEVA, 'password_confirmation' => self::NUEVA,
        ])->assertOk();

        $fresco = $yo->fresh();
        $this->assertTrue(Hash::check(self::NUEVA, $fresco->password));
        $this->assertSame($version + 1, (int) $fresco->jwt_version);
        $this->assertAuthenticatedAs($fresco);
    }

    public function test_los_intentos_con_la_clave_actual_incorrecta_se_limitan(): void
    {
        $yo = $this->usuarioConRol('secretaria', 'limite@example.test');
        $cuerpo = ['name' => $yo->name, 'email' => 'otro@example.invalid', 'current_password' => 'incorrecta'];

        for ($i = 0; $i < 5; $i++) {
            $this->como($yo, 'PUT', '', $cuerpo)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }

        $this->como($yo, 'PUT', '', $cuerpo)->assertTooManyRequests();
        $this->assertSame('limite@example.test', $yo->fresh()->email);
    }

    // ---------------------------------------------------------------- sesiones

    public function test_lista_solo_las_sesiones_vigentes_propias_sin_exponer_el_identificador_real(): void
    {
        config(['session.driver' => 'database']);
        $yo = $this->usuarioConRol('secretaria', 'lista@example.test');
        $otro = $this->usuarioConRol('coordinador', 'otro@example.test');
        $this->sesion($yo, 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->sesion($yo, 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 30);
        $this->sesion($yo, 'sesion-caducada-ccccccccccccccccccccccccccccccc', 60 * 24);
        $this->sesion($otro, 'sesion-ajena-dddddddddddddddddddddddddddddddddd');

        $respuesta = $this->como($yo, 'GET', '/sesiones')->assertOk()->assertJsonPath('data.disponible', true);

        $json = (string) $respuesta->getContent();
        $this->assertStringNotContainsString('sesion-movil', $json);
        $this->assertStringNotContainsString('sesion-ajena', $json);
        $this->assertStringNotContainsString('sesion-caducada', $json);
        $huellas = array_column($respuesta->json('data.sesiones'), 'identificador');
        $this->assertContains(SesionesActivas::huella('sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $huellas);
        $this->assertContains(SesionesActivas::huella('sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'), $huellas);
        $this->assertNotContains(SesionesActivas::huella('sesion-ajena-dddddddddddddddddddddddddddddddddd'), $huellas);
    }

    public function test_cerrar_las_demas_conserva_las_ajenas_y_audita_sin_identificadores(): void
    {
        config(['session.driver' => 'database']);
        $yo = $this->usuarioConRol('secretaria', 'cierre@example.test');
        $otro = $this->usuarioConRol('coordinador', 'ajeno@example.test');
        $propias = ['sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];
        foreach ($propias as $id) {
            $this->sesion($yo, $id);
        }
        $this->sesion($otro, 'sesion-ajena-dddddddddddddddddddddddddddddddddd');
        $version = (int) $yo->jwt_version;

        $this->como($yo, 'POST', '/sesiones/cerrar-otras', ['current_password' => 'incorrecta'])->assertUnprocessable();
        $this->assertSame(2, DB::table('sessions')->whereIn('id', $propias)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'sesiones_cerradas')->count());

        $respuesta = $this->como($yo, 'POST', '/sesiones/cerrar-otras', ['current_password' => self::PASSWORD])->assertOk();
        $this->assertGreaterThanOrEqual(2, $respuesta->json('data.cerradas'));

        $this->assertSame(0, DB::table('sessions')->whereIn('id', $propias)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $otro->id)->count());
        $this->assertSame($version + 1, (int) $yo->fresh()->jwt_version);

        $registro = AuditLog::query()->where('action', 'sesiones_cerradas')->sole();
        $this->assertSame($yo->id, $registro->user_id);
        $this->assertStringNotContainsString('sesion-movil', (string) json_encode($registro->getAttributes()));
    }

    public function test_cerrar_una_sesion_concreta_y_no_poder_cerrar_una_ajena(): void
    {
        config(['session.driver' => 'database']);
        $yo = $this->usuarioConRol('secretaria', 'una@example.test');
        $otro = $this->usuarioConRol('coordinador', 'ajena@example.test');
        $this->sesion($yo, 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->sesion($yo, 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->sesion($otro, 'sesion-ajena-dddddddddddddddddddddddddddddddddd');

        $this->como($yo, 'POST', '/sesiones/'.SesionesActivas::huella('sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa').'/cerrar', ['current_password' => self::PASSWORD])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']);

        // La huella de una sesión de otra persona no sirve.
        $this->como($yo, 'POST', '/sesiones/'.SesionesActivas::huella('sesion-ajena-dddddddddddddddddddddddddddddddddd').'/cerrar', ['current_password' => self::PASSWORD])->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-ajena-dddddddddddddddddddddddddddddddddd']);

        // Un identificador con formato inválido no llega al controlador.
        $this->como($yo, 'POST', '/sesiones/no-es-una-huella/cerrar', ['current_password' => self::PASSWORD])->assertNotFound();
    }

    public function test_sin_driver_database_informa_que_no_esta_disponible(): void
    {
        config(['session.driver' => 'array']);
        $yo = $this->usuarioConRol('secretaria', 'array@example.test');

        $this->como($yo, 'GET', '/sesiones')->assertOk()->assertJsonPath('data.disponible', false)->assertJsonCount(0, 'data.sesiones');
    }
}
