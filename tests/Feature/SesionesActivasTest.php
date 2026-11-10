<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SesionesActivas;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sesiones activas del propio usuario: listar, cerrar las demás y cerrar una. Las filas de `sessions` se
 * insertan a mano para simular otros dispositivos. SQLite en memoria; no prueba sesiones Redis/archivo ni
 * PostgreSQL.
 */
class SesionesActivasTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'Clave-Segura-2026!x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['session.driver' => 'database']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function personal(): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::CLAVE)]);
        $user->assignRole('secretaria');

        return $user;
    }

    private function sesion(?User $user, string $id, int $haceMinutos = 1, string $agente = 'Navegador de prueba'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user?->getKey(),
            'ip_address' => '10.0.0.'.random_int(1, 200),
            'user_agent' => $agente,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->subMinutes($haceMinutos)->getTimestamp(),
        ]);
    }

    public function test_lista_solo_las_sesiones_vigentes_propias_sin_exponer_el_identificador_real(): void
    {
        $user = $this->personal();
        $otro = $this->personal();
        $this->sesion($user, 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->sesion($user, 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 30);
        $this->sesion($user, 'sesion-caducada-ccccccccccccccccccccccccccccccc', 60 * 24);
        $this->sesion($otro, 'sesion-ajena-dddddddddddddddddddddddddddddddddd');

        $respuesta = $this->actingAs($user)->getJson(route('admin.perfil.sesiones.index'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('disponible', true)
            ->assertJsonCount(2, 'sesiones');

        $json = (string) $respuesta->getContent();
        $this->assertStringNotContainsString('sesion-movil', $json);
        $this->assertStringNotContainsString('sesion-ajena', $json);
        $this->assertSame(SesionesActivas::huella('sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $respuesta->json('sesiones.0.identificador'));
    }

    public function test_cerrar_las_demas_exige_contrasena_y_conserva_las_ajenas(): void
    {
        $user = $this->personal();
        $otro = $this->personal();
        $this->sesion($user, 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->sesion($user, 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->sesion($otro, 'sesion-ajena-dddddddddddddddddddddddddddddddddd');
        $versionAntes = (int) $user->jwt_version;

        $simuladas = ['sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];

        $this->actingAs($user)->delete(route('admin.perfil.sesiones.destroy-otras'), ['current_password' => 'incorrecta'])
            ->assertSessionHasErrorsIn('sesiones', 'current_password');
        // (La propia petición guarda además su fila de sesión al terminar.)
        $this->assertSame(2, DB::table('sessions')->whereIn('id', $simuladas)->count());
        $this->assertSame(0, AuditLog::where('action', 'sesiones_cerradas')->count());

        $respuesta = $this->actingAs($user)->deleteJson(route('admin.perfil.sesiones.destroy-otras'), ['current_password' => self::CLAVE])
            ->assertOk();
        $this->assertGreaterThanOrEqual(2, $respuesta->json('cerradas'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->whereIn('id', [
            'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ])->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $otro->id)->count());
        $this->assertSame($versionAntes + 1, (int) $user->fresh()->jwt_version);

        $registro = AuditLog::where('action', 'sesiones_cerradas')->sole();
        $this->assertSame($user->id, $registro->user_id);
        $this->assertSame($respuesta->json('cerradas'), $registro->changes['sesiones']);
        $this->assertStringNotContainsString('sesion-movil', (string) json_encode($registro->getAttributes()));
    }

    public function test_cerrar_una_sesion_concreta_y_no_poder_cerrar_una_ajena(): void
    {
        $user = $this->personal();
        $otro = $this->personal();
        $this->sesion($user, 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->sesion($user, 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->sesion($otro, 'sesion-ajena-dddddddddddddddddddddddddddddddddd');

        $this->actingAs($user)->delete(
            route('admin.perfil.sesiones.destroy', SesionesActivas::huella('sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')),
            ['current_password' => self::CLAVE],
        )->assertRedirect(route('admin.perfil.edit'));

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-portatil-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']);

        // La huella de una sesión de otra persona no sirve.
        $this->actingAs($user)->deleteJson(
            route('admin.perfil.sesiones.destroy', SesionesActivas::huella('sesion-ajena-dddddddddddddddddddddddddddddddddd')),
            ['current_password' => self::CLAVE],
        )->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-ajena-dddddddddddddddddddddddddddddddddd']);

        // Un identificador con formato inválido no llega al controlador.
        $this->actingAs($user)->deleteJson('/admin/perfil/sesiones/no-es-una-huella', ['current_password' => self::CLAVE])
            ->assertNotFound();
    }

    public function test_el_perfil_muestra_las_sesiones_con_su_formulario_de_cierre(): void
    {
        $user = $this->personal();
        $this->sesion($user, 'sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 5, 'Mozilla/5.0 (Android) Movil de prueba');

        $html = (string) $this->actingAs($user)->get(route('admin.perfil.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Sesiones activas', $html);
        $this->assertStringContainsString('Movil de prueba', $html);
        $this->assertStringContainsString(
            route('admin.perfil.sesiones.destroy', SesionesActivas::huella('sesion-movil-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')),
            $html,
        );
        $this->assertStringContainsString(route('admin.perfil.sesiones.destroy-otras'), $html);
        $this->assertStringNotContainsString('sesion-movil-aaaa', $html);
    }

    public function test_el_error_de_contrasena_se_muestra_en_la_seccion_de_sesiones_y_no_en_el_perfil(): void
    {
        $user = $this->personal();

        $this->actingAs($user)->from(route('admin.perfil.edit'))
            ->delete(route('admin.perfil.sesiones.destroy-otras'), ['current_password' => 'incorrecta'])
            ->assertRedirect(route('admin.perfil.edit'))
            ->assertSessionHasErrorsIn('sesiones', 'current_password')
            ->assertSessionDoesntHaveErrors('current_password');

        $html = (string) $this->actingAs($user)->get(route('admin.perfil.edit'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'La contraseña actual no es correcta.'));
    }

    public function test_sin_driver_database_informa_que_no_esta_disponible(): void
    {
        config(['session.driver' => 'array']);
        $user = $this->personal();

        $this->actingAs($user)->getJson(route('admin.perfil.sesiones.index'))
            ->assertOk()
            ->assertJsonPath('disponible', false)
            ->assertJsonCount(0, 'sesiones');
    }

    public function test_requiere_sesion_iniciada(): void
    {
        $this->getJson(route('admin.perfil.sesiones.index'))->assertUnauthorized();
    }
}
