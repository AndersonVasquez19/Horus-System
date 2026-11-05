<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\Reclamacion;
use App\Models\ReclamacionHistorial;
use App\Models\User;
use App\Services\GestionReclamaciones;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/**
 * Sprint 22, Fase 2: avisos internos del plazo legal (3 días hábiles antes y el día del vencimiento).
 * Una reclamación registrada el lunes 26/10/2026 vence el 16/11/2026; el primer aviso corresponde al 11/11.
 */
class AvisosPlazoReclamacionesTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    private const COMANDO = 'horus:reclamaciones-vencimientos';

    private const ACCION = 'reclamacion_aviso_plazo';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones();
    }

    private function viajarA(string $fechaLima): void
    {
        $this->travelTo(Carbon::parse($fechaLima, 'America/Lima'));
    }

    private function servicio(): GestionReclamaciones
    {
        return app(GestionReclamaciones::class);
    }

    private function reclamacion(): Reclamacion
    {
        return $this->registrar()['reclamacion'];
    }

    private function avisos(): int
    {
        return NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->count();
    }

    private function auditorias(): int
    {
        return AuditLog::query()->where('action', self::ACCION)->count();
    }

    private function ejecutar(string $fechaLima): void
    {
        $this->viajarA($fechaLima);
        $this->artisan(self::COMANDO)->assertSuccessful();
    }

    /** @return list<int> */
    private function conteos(): array
    {
        return array_map(fn (string $t): int => DB::table($t)->count(), ['reclamaciones', 'reclamacion_historial', 'reclamacion_envios', 'audit_logs', 'notificaciones_internas']);
    }

    public function test_no_avisa_antes_de_los_tres_dias_habiles(): void
    {
        $this->reclamacion();

        $this->ejecutar('2026-11-10 09:00:00');

        $this->assertSame(0, $this->avisos());
        $this->assertSame(0, $this->auditorias());
    }

    public function test_avisa_tres_dias_habiles_antes_solo_al_responsable_y_sin_datos_personales(): void
    {
        $r = $this->reclamacion();
        $responsable = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);
        $this->servicio()->asignarResponsable($this->admin, $r->identificador, $responsable->id);
        $otro = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);
        $previas = NotificacionInterna::query()->count();

        $this->ejecutar('2026-11-11 09:00:00');

        $nuevas = NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->get();
        $this->assertSame($previas + 1, NotificacionInterna::query()->count());
        $this->assertCount(1, $nuevas);
        $this->assertSame($responsable->id, $nuevas->first()->user_id);
        $this->assertNotSame($otro->id, $nuevas->first()->user_id);
        $this->assertStringContainsString($r->numero, $nuevas->first()->titulo);
        $this->assertStringContainsString('16/11/2026', $nuevas->first()->mensaje);
        $texto = $nuevas->first()->titulo.' '.$nuevas->first()->mensaje;
        foreach (['Persona', 'Ficticia', '12345678', 'persona.ficticia', 'Calle Ficticia', 'Detalle sintético'] as $dato) {
            $this->assertStringNotContainsString($dato, $texto);
        }
        $auditoria = AuditLog::query()->where('action', self::ACCION)->sole();
        $this->assertNull($auditoria->user_id);
        $this->assertSame($r->id, $auditoria->model_id);
        $this->assertSame(['por_vencer', '2026-11-16'], [$auditoria->changes['resultado'], $auditoria->changes['fecha_limite']]);
    }

    public function test_sin_responsable_avisa_a_quienes_tienen_reclamaciones_ver(): void
    {
        $this->reclamacion();
        $conPermiso = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);
        $sinPermiso = $this->usuarioConPermisos('coordinador');

        $this->ejecutar('2026-11-11 09:00:00');

        $destinatarios = NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$this->admin->id, $conPermiso->id], $destinatarios);
        $this->assertNotContains($sinPermiso->id, $destinatarios);
        // Una fila personal por destinatario; nunca una general compartida.
        $this->assertSame(['personal', 'personal'], NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->pluck('audiencia')->all());
        $this->assertSame(1, $this->auditorias());
    }

    public function test_si_falla_un_aviso_con_varios_destinatarios_no_queda_ninguno_ni_la_marca(): void
    {
        $r = $this->reclamacion();
        $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);
        $this->viajarA('2026-11-11 09:00:00');
        $antes = $this->conteos();
        $inserciones = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inserciones): void {
            if (str_starts_with($sql, 'insert into "notificaciones_internas"') && ++$inserciones === 2) {
                throw new RuntimeException('Fallo simulado en el segundo aviso');
            }
        });

        try {
            $this->servicio()->avisarPlazo($r->identificador);
            $this->fail('Se esperaba el fallo simulado.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo simulado en el segundo aviso', $e->getMessage());
        }

        $this->assertSame(2, $inserciones);
        $this->assertSame($antes, $this->conteos(), 'Ni avisos parciales ni marca de auditoría');
    }

    public function test_un_responsable_que_ya_no_puede_ver_equivale_a_no_tener_responsable(): void
    {
        $r = $this->reclamacion();
        $responsable = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);
        $this->servicio()->asignarResponsable($this->admin, $r->identificador, $responsable->id);
        $responsable->revokePermissionTo('reclamaciones.ver');

        $this->ejecutar('2026-11-11 09:00:00');

        $destinatarios = NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->pluck('user_id')->all();
        $this->assertSame([$this->admin->id], $destinatarios);
    }

    public function test_no_duplica_avisos_el_mismo_dia_ni_los_dias_siguientes(): void
    {
        $this->reclamacion();

        $this->ejecutar('2026-11-11 09:00:00');
        $this->ejecutar('2026-11-11 18:00:00');
        $this->ejecutar('2026-11-12 09:00:00');
        $this->ejecutar('2026-11-13 09:00:00');

        $this->assertSame(1, $this->avisos());
        $this->assertSame(1, $this->auditorias());
    }

    public function test_avisa_el_dia_del_vencimiento_una_sola_vez_ademas_del_aviso_previo(): void
    {
        $this->reclamacion();
        $this->ejecutar('2026-11-11 09:00:00');

        $this->ejecutar('2026-11-16 09:00:00');
        $this->ejecutar('2026-11-16 15:00:00');

        $this->assertSame(2, $this->avisos());
        $resultados = AuditLog::query()->where('action', self::ACCION)->orderBy('id')->get()->map(fn (AuditLog $a) => $a->changes['resultado'])->all();
        $this->assertSame(['por_vencer', 'vence'], $resultados);
    }

    public function test_si_se_perdieron_ejecuciones_el_aviso_previo_se_envia_igual_dentro_del_plazo(): void
    {
        $this->reclamacion();

        $this->ejecutar('2026-11-13 09:00:00');

        $this->assertSame(1, $this->avisos());
    }

    public function test_un_caso_vencido_sin_aviso_previo_recibe_el_aviso_de_vencimiento_una_vez(): void
    {
        $this->reclamacion();

        $this->ejecutar('2026-11-18 09:00:00');
        $this->ejecutar('2026-11-19 09:00:00');

        $this->assertSame(1, $this->avisos());
        $this->assertSame('vence', AuditLog::query()->where('action', self::ACCION)->sole()->changes['resultado']);
    }

    public function test_no_avisa_con_propuesta_pendiente_porque_el_plazo_esta_suspendido(): void
    {
        $r = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame(0, $this->avisos());
    }

    public function test_el_servicio_no_avisa_un_plazo_suspendido_aunque_se_le_pida_directamente(): void
    {
        $r = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        $antes = $this->conteos();

        $this->assertNull($this->servicio()->avisarPlazo($r->identificador));
        $this->assertNull($this->servicio()->umbralAviso($r->refresh()));

        $this->assertSame($antes, $this->conteos());
    }

    public function test_los_textos_distinguen_el_aviso_previo_del_de_vencimiento(): void
    {
        $this->reclamacion();
        $this->ejecutar('2026-11-11 09:00:00');
        $this->ejecutar('2026-11-16 09:00:00');

        [$previo, $vence] = NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->orderBy('id')->get()->all();

        $this->assertStringStartsWith('Plazo por vencer: reclamación ', $previo->titulo);
        $this->assertSame('El plazo de respuesta vence el 16/11/2026.', $previo->mensaje);
        $this->assertStringStartsWith('Plazo vence hoy: reclamación ', $vence->titulo);
        $this->assertSame('El plazo de respuesta vence hoy, 16/11/2026.', $vence->mensaje);
        $this->assertStringNotContainsString('vencido', $vence->titulo.$vence->mensaje);
    }

    public function test_un_plazo_ya_pasado_se_avisa_como_vencido_y_no_como_vence_hoy(): void
    {
        $this->reclamacion();

        $this->ejecutar('2026-11-18 09:00:00');

        $aviso = NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->sole();
        $this->assertStringStartsWith('Plazo vencido: reclamación ', $aviso->titulo);
        $this->assertSame('El plazo de respuesta venció el 16/11/2026.', $aviso->mensaje);
        $this->assertStringNotContainsString('hoy', $aviso->titulo.$aviso->mensaje);
        $this->assertSame('vence', AuditLog::query()->where('action', self::ACCION)->sole()->changes['resultado']);
    }

    public function test_el_dia_del_vencimiento_se_juzga_en_hora_de_lima(): void
    {
        $this->reclamacion();

        // 23:30 del 16/11 en Lima ya es 17/11 en UTC: sigue siendo «vence hoy».
        $this->ejecutar('2026-11-16 23:30:00');

        $aviso = NotificacionInterna::query()->where('tipo', 'reclamacion_plazo')->sole();
        $this->assertStringStartsWith('Plazo vence hoy: ', $aviso->titulo);
    }

    public function test_al_vencer_la_propuesta_se_reanuda_y_el_aviso_usa_la_nueva_fecha_limite(): void
    {
        $r = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');

        $this->ejecutar('2026-11-19 09:00:00'); // propuesta vencida el 18/11; nueva fecha límite 23/11

        $r->refresh();
        $this->assertSame('2026-11-23', $r->fecha_limite->toDateString());
        $this->assertSame(1, $this->avisos());
        $this->assertSame('2026-11-23', AuditLog::query()->where('action', self::ACCION)->sole()->changes['fecha_limite']);
    }

    public function test_un_plazo_desplazado_permite_un_aviso_nuevo_aunque_hubiera_uno_previo(): void
    {
        $r = $this->reclamacion();
        $this->ejecutar('2026-11-11 09:00:00'); // aviso previo sobre 16/11
        $this->viajarA('2026-11-12 09:00:00');
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');

        $this->ejecutar('2026-11-20 09:00:00'); // vence la propuesta (19/11); nueva fecha límite 23/11, a 1 día hábil

        $r->refresh();
        $this->assertSame('2026-11-23', $r->fecha_limite->toDateString());
        $this->assertSame(2, $this->avisos());
        $fechas = AuditLog::query()->where('action', self::ACCION)->orderBy('id')->get()->map(fn (AuditLog $a) => $a->changes['fecha_limite'])->all();
        $this->assertSame(['2026-11-16', '2026-11-23'], $fechas);
    }

    public function test_no_avisa_casos_respondidos_ni_concluidos_por_acuerdo(): void
    {
        $respondido = $this->reclamacion();
        $aceptado = $this->reclamacion();
        $this->servicio()->registrarRespuesta($this->admin, $respondido->identificador, 'Respuesta formal.', 'carta', 'Carta 12/10, guía 99');
        $this->servicio()->enviarPropuesta($this->admin, $aceptado->identificador, 'P', 'Texto');
        $this->servicio()->registrarAceptacion($this->admin, $aceptado->identificador, '2026-10-28', 'correo', 'Acepto');

        $this->ejecutar('2026-11-16 09:00:00');

        $this->assertSame(0, $this->avisos());
    }

    public function test_el_fallo_de_la_auditoria_revierte_las_notificaciones_y_no_deja_marca(): void
    {
        $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $antes = $this->conteos();
        AuditLog::creating(function (): void {
            throw new RuntimeException('fallo de auditoría simulado');
        });

        $this->artisan(self::COMANDO)->assertFailed();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_tras_un_fallo_el_siguiente_intento_si_avisa(): void
    {
        $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $falla = true;
        AuditLog::creating(function () use (&$falla): void {
            if ($falla) {
                throw new RuntimeException('fallo de auditoría simulado');
            }
        });
        $this->artisan(self::COMANDO)->assertFailed();
        $falla = false;

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame(1, $this->avisos());
        $this->assertSame(1, $this->auditorias());
    }

    public function test_el_servicio_es_idempotente_y_devuelve_el_resultado(): void
    {
        $r = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');

        $this->assertSame('por_vencer', $this->servicio()->avisarPlazo($r->identificador));
        $this->assertNull($this->servicio()->avisarPlazo($r->identificador));
        $this->assertSame(1, $this->auditorias());
    }

    public function test_sin_destinatarios_resuelve_antes_la_propuesta_vencida_pero_no_crea_avisos_ni_marca(): void
    {
        $r = $this->reclamacion();
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto'); // ventana hasta el 02/11
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->viajarA('2026-11-19 09:00:00');
        $avisosAntes = NotificacionInterna::query()->count();

        // Llamada directa al servicio con la propuesta vencida aún pendiente: se resuelve (y se confirma) antes del aviso.
        $this->assertSame('sin_destinatarios', $this->servicio()->avisarPlazo($r->identificador));

        $r->refresh();
        $this->assertNull($r->suspension_desde, 'La propuesta vencida se resolvió');
        $this->assertSame('2026-11-23', $r->fecha_limite->toDateString());
        $this->assertSame(1, ReclamacionHistorial::query()->where('evento', 'propuesta_vencida')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'reclamacion_propuesta_vencida')->count());
        $this->assertSame($avisosAntes, NotificacionInterna::query()->count(), 'Sin notificaciones');
        $this->assertSame(0, $this->auditorias(), 'Sin marca de aviso');
    }

    public function test_sin_destinatarios_no_crea_notificaciones_ni_marca_el_aviso(): void
    {
        $r = $this->reclamacion();
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->viajarA('2026-11-11 09:00:00');
        $antes = $this->conteos();

        $this->assertSame('sin_destinatarios', $this->servicio()->avisarPlazo($r->identificador));

        $this->assertSame($antes, $this->conteos());
    }

    public function test_dry_run_cuenta_los_avisos_sin_escribir(): void
    {
        $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $antes = $this->conteos();

        $this->artisan(self::COMANDO, ['--dry-run' => true])
            ->expectsOutputToContain('Avisos de plazo pendientes: 1 (dry-run')
            ->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_dry_run_no_cuenta_lo_ya_avisado(): void
    {
        $this->reclamacion();
        $this->ejecutar('2026-11-11 09:00:00');

        $this->artisan(self::COMANDO, ['--dry-run' => true])
            ->expectsOutputToContain('Avisos de plazo pendientes: 0 (dry-run')
            ->assertSuccessful();
    }

    public function test_modulo_desactivado_no_avisa(): void
    {
        $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        config(['reclamaciones.enabled' => false]);
        $antes = $this->conteos();

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_el_fallo_de_un_aviso_no_impide_los_demas_y_el_log_no_lleva_datos(): void
    {
        $a = $this->reclamacion();
        $b = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        Log::spy();
        $llamadas = [];
        $this->partialMock(GestionReclamaciones::class, function (MockInterface $m) use ($a, &$llamadas): void {
            $m->shouldReceive('avisarPlazo')->twice()->andReturnUsing(function (string $uuid) use ($a, &$llamadas): string {
                $llamadas[] = $uuid;
                if ($uuid === $a->identificador) {
                    throw new RuntimeException('detalle con persona.ficticia@example.test');
                }

                return 'por_vencer';
            });
        });

        $this->artisan(self::COMANDO)->expectsOutputToContain('1 con error')->assertFailed();

        $this->assertEqualsCanonicalizing([$a->identificador, $b->identificador], $llamadas);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $c): bool => $c['identificador'] === $a->identificador
            && $c['excepcion'] === RuntimeException::class
            && ! str_contains(json_encode($c), 'persona.ficticia'));
    }

    public function test_el_limite_acota_los_avisos_por_ejecucion(): void
    {
        $this->reclamacion();
        $this->reclamacion();
        $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');

        $this->artisan(self::COMANDO, ['--limit' => 2])->assertSuccessful();
        $this->assertSame(2, $this->auditorias());

        $this->artisan(self::COMANDO, ['--limit' => 2])->assertSuccessful();
        $this->assertSame(3, $this->auditorias());
    }

    // --- Auditoría independiente (H1, H2, H3, H4) ------------------------------------------------------------

    /** Hace fallar, mientras `$falla` sea true, el aviso del caso indicado (la auditoría revierte la transacción). */
    private function fallarAvisoDe(Reclamacion $r, bool &$falla): void
    {
        AuditLog::creating(function (AuditLog $log) use ($r, &$falla): void {
            if ($falla && $log->action === self::ACCION && (int) $log->model_id === $r->id) {
                throw new RuntimeException('fallo persistente simulado');
            }
        });
    }

    public function test_h1_un_aviso_que_falla_siempre_no_impide_avanzar_a_los_posteriores_y_se_reintenta(): void
    {
        $a = $this->reclamacion();
        $b = $this->reclamacion();
        $c = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $falla = true;
        $this->fallarAvisoDe($a, $falla);
        $avisados = fn (): array => AuditLog::query()->where('action', self::ACCION)->orderBy('model_id')->pluck('model_id')->map(fn ($id): int => (int) $id)->all();

        $this->artisan(self::COMANDO, ['--limit' => 1])->assertFailed();
        $this->assertSame([$b->id], $avisados(), 'A falló sin consumir el tope: B se avisó');

        $this->artisan(self::COMANDO, ['--limit' => 1])->assertFailed();
        $this->assertSame([$b->id, $c->id], $avisados(), 'A se reintenta y C avanza');

        $falla = false;
        $this->artisan(self::COMANDO, ['--limit' => 1])->assertSuccessful();
        $this->assertSame([$a->id, $b->id, $c->id], $avisados(), 'Al desaparecer la causa A se avisa');
    }

    public function test_h1_los_casos_sin_destinatarios_no_consumen_el_tope(): void
    {
        $this->reclamacion();
        $this->reclamacion();
        $this->reclamacion();
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->viajarA('2026-11-11 09:00:00');

        // Los tres se intentan aunque el tope sea 1: ninguno escribe, así que ninguno agota el presupuesto.
        $this->artisan(self::COMANDO, ['--limit' => 1])
            ->expectsOutputToContain('Sin destinatarios: 3.')
            ->assertSuccessful();
        $this->assertSame(0, $this->auditorias());
    }

    public function test_h2_un_error_en_la_evaluacion_previa_de_un_caso_no_interrumpe_los_siguientes(): void
    {
        $a = $this->reclamacion();
        $b = $this->reclamacion();
        $c = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        Log::spy();
        // Error de consulta solo al evaluar si A ya fue avisado (avisoPendiente, fuera de la transacción).
        DB::listen(function ($consulta) use ($a): void {
            if (str_contains($consulta->sql, 'audit_logs') && str_starts_with(strtolower($consulta->sql), 'select') && in_array($a->id, array_map('intval', array_filter($consulta->bindings, 'is_numeric')), true)) {
                throw new RuntimeException('error de consulta con persona.ficticia@example.test');
            }
        });

        $this->artisan(self::COMANDO)->expectsOutputToContain('1 con error')->assertFailed();

        $this->assertSame([$b->id, $c->id], AuditLog::query()->where('action', self::ACCION)->orderBy('model_id')->pluck('model_id')->map(fn ($id): int => (int) $id)->all());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $ctx): bool => $m === 'reclamaciones.aviso_fallido'
            && $ctx['identificador'] === $a->identificador
            && $ctx['excepcion'] === RuntimeException::class
            && ! str_contains(json_encode($ctx), 'persona.ficticia'));
    }

    public function test_h3_la_deduplicacion_usa_una_sola_consulta_de_existencia_sin_cargar_el_historial(): void
    {
        $r = $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        // Historial previo: muchos avisos de otros plazos que no deben cargarse ni confundirse con el vigente.
        for ($i = 1; $i <= 30; $i++) {
            AuditLog::create(['action' => self::ACCION, 'model_type' => Reclamacion::class, 'model_id' => $r->id, 'changes' => ['esquema' => 1, 'numero' => $r->numero, 'resultado' => 'por_vencer', 'fecha_limite' => '2026-01-'.sprintf('%02d', $i)], 'created_at' => now()]);
        }
        $consultas = [];
        DB::listen(function ($c) use (&$consultas): void {
            if (str_contains($c->sql, 'audit_logs')) {
                $consultas[] = strtolower($c->sql);
            }
        });

        $this->assertSame('por_vencer', $this->servicio()->avisoPendiente($r->fresh()), 'Los avisos de otros plazos no cuentan');

        $this->assertCount(1, $consultas, 'Una sola consulta sobre audit_logs');
        $this->assertStringContainsString('exists', $consultas[0], 'Consulta de existencia, no una carga de filas');
    }

    public function test_h3_la_deduplicacion_distingue_umbral_y_fecha_con_la_consulta_de_existencia(): void
    {
        $r = $this->reclamacion();
        $this->ejecutar('2026-11-11 09:00:00'); // por_vencer sobre 16/11

        $this->assertNull($this->servicio()->avisoPendiente($r->fresh()), 'Mismo umbral y plazo: ya avisado');
        $this->viajarA('2026-11-16 09:00:00');
        $this->assertSame('vence', $this->servicio()->avisoPendiente($r->fresh()), 'Otro umbral: pendiente');
    }

    public function test_h4_dry_run_de_avisos_aclara_alcance_y_truncamiento(): void
    {
        $this->reclamacion();
        $this->reclamacion();
        $this->reclamacion();
        $this->viajarA('2026-11-11 09:00:00');
        $antes = $this->conteos();

        $this->artisan(self::COMANDO, ['--dry-run' => true, '--limit' => 2])
            ->expectsOutputToContain('Avisos de plazo pendientes: 2 (dry-run')
            ->expectsOutputToContain('Simulación (dry-run): no se realizó ninguna escritura.')
            ->expectsOutputToContain('No se simula la reanudación')
            ->expectsOutputToContain('Límite alcanzado (--limit=2): puede haber más casos de los mostrados.')
            ->assertSuccessful();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_la_configuracion_declara_los_tres_dias_habiles(): void
    {
        $this->assertSame(3, (require base_path('config/reclamaciones.php'))['aviso_dias_habiles']);
        $this->assertInstanceOf(User::class, $this->admin);
    }
}
