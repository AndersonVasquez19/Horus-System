<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Models\ReclamacionHistorial;
use App\Services\GestionReclamaciones;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/** Sprint 22, Fase 1: comando `horus:reclamaciones-vencimientos` (vencimiento de propuestas pendientes). */
class ProcesarVencimientosReclamacionesTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    private const COMANDO = 'horus:reclamaciones-vencimientos';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones();
    }

    private function servicio(): GestionReclamaciones
    {
        return app(GestionReclamaciones::class);
    }

    /** Reclamación con propuesta pendiente: suspensión 26/10 → 02/11/2026. */
    private function conPropuesta(): string
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'Propuesta', 'Texto');

        return $r->identificador;
    }

    private function viajarA(string $fechaLima): void
    {
        $this->travelTo(Carbon::parse($fechaLima, 'America/Lima'));
    }

    /** @return list<int> */
    private function conteos(): array
    {
        return array_map(fn (string $t): int => DB::table($t)->count(), ['reclamaciones', 'reclamacion_historial', 'reclamacion_envios', 'audit_logs', 'notificaciones_internas']);
    }

    public function test_resuelve_la_propuesta_vencida_con_historial_y_auditoria(): void
    {
        $uuid = $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');

        $this->artisan(self::COMANDO)->assertSuccessful();

        $r = Reclamacion::query()->where('identificador', $uuid)->sole();
        $this->assertNull($r->suspension_desde);
        $this->assertNull($r->suspension_hasta);
        $this->assertSame('2026-11-23', $r->fecha_limite->toDateString());
        $evento = ReclamacionHistorial::query()->where('evento', 'propuesta_vencida')->sole();
        $this->assertSame('Sistema', $evento->actor_nombre);
        $this->assertSame(1, AuditLog::query()->where('action', 'reclamacion_propuesta_vencida')->count());
    }

    public function test_una_segunda_ejecucion_no_duplica_nada(): void
    {
        $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');
        $this->artisan(self::COMANDO)->assertSuccessful();
        $despues = $this->conteos();

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame($despues, $this->conteos());
    }

    public function test_no_toca_propuestas_dentro_de_la_ventana(): void
    {
        $this->conPropuesta();
        $this->viajarA('2026-11-02 23:30:00'); // último día de la ventana en Lima (en UTC ya es 03/11)
        $antes = $this->conteos();

        $this->artisan(self::COMANDO)->expectsOutputToContain('Candidatas: 0.')->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
        $this->assertNotNull(Reclamacion::query()->sole()->suspension_hasta);
    }

    public function test_ignora_casos_sin_propuesta_rechazados_aceptados_y_respondidos(): void
    {
        $sinPropuesta = $this->registrar()['reclamacion'];
        $rechazada = $this->registrar()['reclamacion'];
        $aceptada = $this->registrar()['reclamacion'];
        foreach ([$rechazada, $aceptada] as $r) {
            $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        }
        $this->servicio()->registrarRechazo($this->admin, $rechazada->identificador, '2026-10-28');
        $this->servicio()->registrarAceptacion($this->admin, $aceptada->identificador, '2026-10-28', 'correo', 'Acepto');
        $this->viajarA('2026-11-10 09:00:00');
        $antes = $this->conteos();

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
        $this->assertSame('abierto', $sinPropuesta->fresh()->estado);
        $this->assertSame('concluido_por_acuerdo', $aceptada->fresh()->estado);
    }

    public function test_dry_run_informa_sin_escribir_ni_resolver(): void
    {
        $this->conPropuesta();
        $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');
        $antes = $this->conteos();
        $this->mock(GestionReclamaciones::class, function (MockInterface $m): void {
            $m->shouldNotReceive('resolverVencimiento');
        });

        $this->artisan(self::COMANDO, ['--dry-run' => true])
            ->expectsOutputToContain('Propuestas vencidas: 2 (dry-run')
            ->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
        $this->assertNotNull(Reclamacion::query()->first()->suspension_hasta);
    }

    public function test_modulo_desactivado_termina_bien_sin_escribir_ni_consultar_casos(): void
    {
        $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');
        config(['reclamaciones.enabled' => false]);
        $antes = $this->conteos();
        $this->mock(GestionReclamaciones::class, function (MockInterface $m): void {
            $m->shouldNotReceive('resolverVencimiento');
        });

        $this->artisan(self::COMANDO)->expectsOutputToContain('desactivado')->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_el_fallo_de_un_caso_no_impide_procesar_los_demas_y_se_registra_sin_datos(): void
    {
        $a = $this->conPropuesta();
        $b = $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');
        Log::spy();
        $llamadas = [];
        $this->mock(GestionReclamaciones::class, function (MockInterface $m) use ($a, &$llamadas): void {
            $m->shouldReceive('resolverVencimiento')->twice()->andReturnUsing(function (string $uuid) use ($a, &$llamadas): bool {
                $llamadas[] = $uuid;
                if ($uuid === $a) {
                    throw new RuntimeException('detalle con persona.ficticia@example.test');
                }

                return true;
            });
        });

        $this->artisan(self::COMANDO)->expectsOutputToContain('1 con error')->assertFailed();

        $this->assertEqualsCanonicalizing([$a, $b], $llamadas);
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $mensaje, array $contexto) use ($a): bool {
            return $contexto['identificador'] === $a
                && $contexto['excepcion'] === RuntimeException::class
                && ! str_contains(json_encode($contexto), 'persona.ficticia');
        });
    }

    public function test_no_genera_notificaciones_internas(): void
    {
        $this->conPropuesta();
        $previas = DB::table('notificaciones_internas')->count();
        $this->viajarA('2026-11-03 09:00:00');

        $this->artisan(self::COMANDO)->assertSuccessful();
        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame($previas, DB::table('notificaciones_internas')->count());
    }

    public function test_el_limite_acota_el_lote_y_se_valida(): void
    {
        $this->conPropuesta();
        $this->conPropuesta();
        $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');

        $this->artisan(self::COMANDO, ['--limit' => 0])->assertFailed();
        $this->artisan(self::COMANDO, ['--limit' => 1001])->assertFailed();
        $this->assertSame(3, Reclamacion::query()->whereNotNull('suspension_hasta')->count());

        $this->artisan(self::COMANDO, ['--limit' => 2])->assertSuccessful();
        $this->assertSame(1, Reclamacion::query()->whereNotNull('suspension_hasta')->count());

        $this->artisan(self::COMANDO, ['--limit' => 2])->assertSuccessful();
        $this->assertSame(0, Reclamacion::query()->whereNotNull('suspension_hasta')->count());
    }

    // --- Auditoría independiente (H1, H4) ------------------------------------------------------------------

    /** Hace fallar, mientras `$falla` sea true, el vencimiento del caso indicado (la auditoría revierte la transacción). */
    private function fallarVencimientoDe(string $uuid, bool &$falla): void
    {
        $id = Reclamacion::query()->where('identificador', $uuid)->value('id');
        AuditLog::creating(function (AuditLog $log) use ($id, &$falla): void {
            if ($falla && $log->action === 'reclamacion_propuesta_vencida' && (int) $log->model_id === (int) $id) {
                throw new RuntimeException('fallo persistente simulado');
            }
        });
    }

    private function pendientes(): int
    {
        return Reclamacion::query()->whereNotNull('suspension_hasta')->count();
    }

    public function test_h1_un_caso_que_falla_siempre_no_impide_avanzar_a_los_posteriores_y_se_reintenta(): void
    {
        $a = $this->conPropuesta();
        $b = $this->conPropuesta();
        $c = $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');
        $falla = true;
        $this->fallarVencimientoDe($a, $falla);

        // Con --limit=1, A (el primero) falla pero no consume el tope: B se resuelve en la misma ejecución.
        $this->artisan(self::COMANDO, ['--limit' => 1])->assertFailed();
        $this->assertNotNull(Reclamacion::query()->where('identificador', $a)->sole()->suspension_hasta, 'A sigue pendiente (reintentable)');
        $this->assertNull(Reclamacion::query()->where('identificador', $b)->sole()->suspension_hasta, 'B avanzó');
        $this->assertNotNull(Reclamacion::query()->where('identificador', $c)->sole()->suspension_hasta, 'C espera su turno por el tope');

        // Siguiente ejecución: A se reintenta (y falla de nuevo), C avanza.
        $this->artisan(self::COMANDO, ['--limit' => 1])->assertFailed();
        $this->assertNull(Reclamacion::query()->where('identificador', $c)->sole()->suspension_hasta, 'C avanzó');
        $this->assertSame(1, $this->pendientes(), 'Solo A queda pendiente');

        // Cuando la causa desaparece, A se resuelve: el fallo no se ignoró ni se marcó como definitivo.
        $falla = false;
        $this->artisan(self::COMANDO, ['--limit' => 1])->assertSuccessful();
        $this->assertSame(0, $this->pendientes());
    }

    public function test_h1_varios_casos_que_fallan_no_bloquean_a_los_siguientes(): void
    {
        $a = $this->conPropuesta();
        $b = $this->conPropuesta();
        $c = $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');
        $falla = true;
        $this->fallarVencimientoDe($a, $falla);
        $this->fallarVencimientoDe($b, $falla);

        $this->artisan(self::COMANDO, ['--limit' => 1])->assertFailed();

        $this->assertNull(Reclamacion::query()->where('identificador', $c)->sole()->suspension_hasta, 'C avanzó pese a dos fallos previos');
        $this->assertSame(2, $this->pendientes());
    }

    public function test_h4_dry_run_aclara_que_no_escribe_que_es_el_estado_actual_y_que_el_limite_trunca(): void
    {
        $this->conPropuesta();
        $this->conPropuesta();
        $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');
        $antes = $this->conteos();

        $this->artisan(self::COMANDO, ['--dry-run' => true, '--limit' => 2])
            ->expectsOutputToContain('Propuestas vencidas: 2 (dry-run')
            ->expectsOutputToContain('Simulación (dry-run): no se realizó ninguna escritura.')
            ->expectsOutputToContain('estado actual')
            ->expectsOutputToContain('No se simula la reanudación')
            ->expectsOutputToContain('Límite alcanzado (--limit=2): puede haber más casos de los mostrados.')
            ->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_h4_dry_run_no_avisa_truncamiento_si_el_limite_no_se_alcanza(): void
    {
        $this->conPropuesta();
        $this->viajarA('2026-11-03 09:00:00');

        $this->artisan(self::COMANDO, ['--dry-run' => true, '--limit' => 5])
            ->expectsOutputToContain('Propuestas vencidas: 1 (dry-run')
            ->doesntExpectOutputToContain('Límite alcanzado')
            ->assertSuccessful();
    }
}
