<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificacionInterna;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lectura por usuario de las notificaciones generales, campana (recientes) y «marcar todas». La bandeja y «marcar una»
 * se retiraron de Blade el 14/11/2026: se prueban por la API del panel; la campana del Blade sigue en pie.
 * Decisión del propietario (10/11/2026). SQLite en memoria: no prueba PostgreSQL ni concurrencia.
 */
class NotificacionLecturaPorUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = '2026_11_10_000038_create_notificacion_lecturas_table';

    private User $a;

    private User $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->staff('secretaria');
        $this->b = $this->staff('coordinador');
    }

    public function test_marcar_una_general_solo_la_marca_para_quien_la_lee(): void
    {
        $general = $this->general('Aviso general');

        $this->panel($this->a, 'POST', 'notificaciones/'.$general->id.'/leida')->assertOk();
        $this->panel($this->a, 'POST', 'notificaciones/'.$general->id.'/leida')->assertOk(); // idempotente

        $this->assertSame(1, DB::table('notificacion_lecturas')->count());
        $this->assertFalse($general->fresh()->leida);
        $this->panel($this->a, 'GET', 'notificaciones')->assertJsonPath('meta.pendientes', 0);
        $this->panel($this->b, 'GET', 'notificaciones')->assertJsonPath('meta.pendientes', 1);
    }

    public function test_una_general_leida_antes_del_cambio_sigue_leida_para_todos(): void
    {
        $this->general('Aviso heredado', leida: true);

        foreach ([$this->a, $this->b] as $usuario) {
            $this->panel($usuario, 'GET', 'notificaciones')->assertJsonPath('meta.pendientes', 0);
        }
    }

    public function test_la_bandeja_no_ofrece_vinculos_externos(): void
    {
        $this->personal($this->a, 'Con vínculo externo', vinculo: '//externo.example/x');
        $this->personal($this->a, 'Con vínculo interno', vinculo: '/admin/aprobaciones');

        $vinculos = $this->panel($this->a, 'GET', 'notificaciones')->assertOk()->json('data.*.vinculo');

        $this->assertContains('/admin/aprobaciones', $vinculos);
        $this->assertNotContains('//externo.example/x', $vinculos);
    }

    public function test_la_migracion_funciona_en_base_sembrada_y_es_reversible(): void
    {
        $general = $this->general('General previa');
        $migracion = require base_path('database/migrations/'.self::MIGRACION.'.php');

        $migracion->down();
        $this->assertFalse(Schema::hasTable('notificacion_lecturas'));

        $migracion->up();
        $migracion->up(); // idempotente
        $this->assertTrue(Schema::hasTable('notificacion_lecturas'));
        $this->assertTrue(Schema::hasIndex('notificacion_lecturas', 'notificacion_lecturas_unica'));
        $this->assertSame(0, DB::table('notificacion_lecturas')->count());
        $this->assertFalse($general->fresh()->leida);

        // Borrar la notificación o el usuario elimina sus lecturas.
        $general->marcarLeidaPara($this->a);
        $this->assertSame(1, DB::table('notificacion_lecturas')->count());
        $this->a->delete();
        $this->assertSame(0, DB::table('notificacion_lecturas')->count());
    }

    /** @param array<string, mixed> $datos */
    private function panel(User $usuario, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        return $this->actingAs($usuario)->json($metodo, '/api/panel/v1/compartido/'.$ruta, $datos, ['X-Horus-Web-Token' => $token]);
    }

    private function staff(string $rol): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function personal(User $usuario, string $titulo, bool $leida = false, ?string $vinculo = null): NotificacionInterna
    {
        return NotificacionInterna::crearPersonal($usuario->id, [
            'titulo' => $titulo, 'mensaje' => 'Mensaje ficticio.', 'tipo' => 'info', 'leida' => $leida, 'vinculo' => $vinculo,
        ]);
    }

    private function general(string $titulo, bool $leida = false, ?string $vinculo = null): NotificacionInterna
    {
        return NotificacionInterna::crearGeneral([
            'titulo' => $titulo, 'mensaje' => 'Mensaje ficticio.', 'tipo' => 'info', 'leida' => $leida, 'vinculo' => $vinculo,
        ]);
    }
}
