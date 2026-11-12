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
use Tests\TestCase;

/**
 * Lectura por usuario de las notificaciones generales, campana (recientes) y «marcar todas».
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

        $this->actingAs($this->a)->patch(route('admin.notificaciones.leida', $general))->assertRedirect();
        $this->actingAs($this->a)->patch(route('admin.notificaciones.leida', $general))->assertRedirect(); // idempotente

        $this->assertSame(1, DB::table('notificacion_lecturas')->count());
        $this->assertFalse($general->fresh()->leida);
        $this->actingAs($this->a)->get(route('admin.notificaciones.index'))->assertViewHas('pendientes', 0);
        $this->actingAs($this->b)->get(route('admin.notificaciones.index'))
            ->assertViewHas('pendientes', 1)
            ->assertSee('Sin leer:', false);
    }

    public function test_una_general_leida_antes_del_cambio_sigue_leida_para_todos(): void
    {
        $this->general('Aviso heredado', leida: true);

        foreach ([$this->a, $this->b] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.notificaciones.index'))->assertViewHas('pendientes', 0);
        }
    }

    public function test_marcar_todas_solo_afecta_a_lo_visible_del_usuario(): void
    {
        $propia = $this->personal($this->a, 'Propia de A');
        $general = $this->general('General');
        $ajena = $this->personal($this->b, 'Personal de B');
        $indeterminada = NotificacionInterna::create([
            'user_id' => null, 'audiencia' => NotificacionInterna::AUDIENCIA_INDETERMINADA,
            'titulo' => 'Indeterminada', 'mensaje' => 'M', 'tipo' => 'info', 'leida' => false,
        ]);

        $this->actingAs($this->a)
            ->from(route('admin.notificaciones.index'))
            ->patch(route('admin.notificaciones.leidas'))
            ->assertRedirect(route('admin.notificaciones.index'))
            ->assertSessionHas('success', 'Se marcaron 2 notificaciones como leídas.');

        $this->assertTrue($propia->fresh()->leida);
        $this->assertTrue($general->fresh()->estaLeidaPara($this->a));
        $this->assertFalse($general->fresh()->leida);
        $this->assertFalse($general->fresh()->estaLeidaPara($this->b));
        $this->assertFalse($ajena->fresh()->leida);
        $this->assertFalse($indeterminada->fresh()->leida);

        // Sin pendientes: no cambia nada y lo dice.
        $this->actingAs($this->a)
            ->patch(route('admin.notificaciones.leidas'))
            ->assertSessionHas('success', 'No había notificaciones pendientes.');
        $this->assertSame(1, DB::table('notificacion_lecturas')->count());
    }

    public function test_marcar_todas_por_json(): void
    {
        $this->personal($this->a, 'Propia');

        $this->actingAs($this->a)
            ->patchJson(route('admin.notificaciones.leidas'))
            ->assertOk()
            ->assertExactJson(['marcadas' => 1, 'pendientes' => 0]);
    }

    public function test_recientes_devuelve_las_ultimas_no_leidas_propias_y_generales_con_campos_de_presentacion(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 03:30:00', 'UTC'));
        for ($i = 1; $i <= 6; $i++) {
            $this->personal($this->a, "Propia {$i}", vinculo: '/admin/aprobaciones');
        }
        $this->personal($this->a, 'Ya leída', leida: true);
        $this->personal($this->b, 'Confidencial de B');
        $this->general('General con vínculo externo', vinculo: 'https://externo.example/x');
        $this->general('General de procesos', vinculo: '/admin/procesos/7');

        $respuesta = $this->actingAs($this->a)->getJson(route('admin.notificaciones.recientes'))->assertOk();

        $respuesta->assertJsonPath('pendientes', 8)
            ->assertJsonCount(5, 'notificaciones')
            ->assertJsonMissing(['titulo' => 'Confidencial de B'])
            ->assertJsonMissing(['titulo' => 'Ya leída']);

        foreach ($respuesta->json('notificaciones') as $item) {
            $this->assertSame(['id', 'titulo', 'mensaje', 'tipo', 'fecha', 'vinculo'], array_keys($item));
            $this->assertSame('10/11/2026 22:30', $item['fecha']); // hora de Lima
            $this->assertContains($item['vinculo'], [null, '/admin/aprobaciones']);
        }
    }

    public function test_recientes_y_marcar_todas_requieren_sesion_del_panel(): void
    {
        $this->getJson(route('admin.notificaciones.recientes'))->assertUnauthorized();
        $this->get(route('admin.notificaciones.recientes'))->assertRedirect(route('login'));
        $this->patch(route('admin.notificaciones.leidas'))->assertRedirect(route('login'));

        $sinRol = User::factory()->create();
        $this->actingAs($sinRol)->getJson(route('admin.notificaciones.recientes'))->assertForbidden();
        $this->actingAs($sinRol)->patchJson(route('admin.notificaciones.leidas'))->assertForbidden();
    }

    public function test_la_campana_muestra_el_contador_y_comparte_una_sola_consulta_con_el_inicio(): void
    {
        $this->personal($this->a, 'Propia');
        $this->general('General');

        $consultas = 0;
        DB::listen(function ($consulta) use (&$consultas): void {
            if (str_contains($consulta->sql, 'notificaciones_internas') && str_contains(strtolower($consulta->sql), 'count(')) {
                $consultas++;
            }
        });

        $this->actingAs($this->a)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Notificaciones, 2 sin leer"', false)
            ->assertSee('data-pendientes="2"', false)
            ->assertSee(route('admin.notificaciones.recientes'), false)
            ->assertViewHas('notificacionesPendientes', 2);

        $this->assertSame(1, $consultas);
    }

    public function test_la_bandeja_no_ofrece_vinculos_externos(): void
    {
        $this->personal($this->a, 'Con vínculo externo', vinculo: '//externo.example/x');
        $this->personal($this->a, 'Con vínculo interno', vinculo: '/admin/aprobaciones');

        $this->actingAs($this->a)->get(route('admin.notificaciones.index'))
            ->assertOk()
            ->assertDontSee('href="//externo.example/x"', false)
            ->assertSee('href="/admin/aprobaciones"', false);
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
