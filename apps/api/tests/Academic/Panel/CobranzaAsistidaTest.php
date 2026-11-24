<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use App\Models\SeguimientoCobranza;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Plan de mejora del panel, Fase 3.1: cobranza asistida (cola de deuda vencida por tramos, contactos y promesas de pago).
 * Cifras calculadas a mano. SQLite en memoria: comprueba reglas y exactitud, no el plan de ejecución de PostgreSQL.
 */

const COBRANZA_COLA = 'academico/cobranza/cola';

/** @param array<string, string> $filtros */
function cobCola(array $filtros): string
{
    return COBRANZA_COLA.'?'.http_build_query($filtros);
}

function cobDia(int $desdeHoy): string
{
    return now('America/Lima')->addDays($desdeHoy)->toDateString();
}

function cobObligacion(string $dni, string $estado = 'vigente'): int
{
    $curso = Curso::query()->firstOrCreate(['codigo' => 'COB-1'], ['nombre' => 'Curso cobranza', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'Nombre'.$dni, 'apellidos' => 'Apellido'.$dni, 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    return DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'fraccionado', 'monto_total' => '500.00', 'moneda' => 'PEN', 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);
}

function cobCuota(int $obligacion, int $numero, string $monto, string $vence): int
{
    return DB::table('cuotas_obligacion')->insertGetId(['obligacion_pago_id' => $obligacion, 'numero' => $numero, 'monto' => $monto, 'vence' => $vence,
        'created_at' => now(), 'updated_at' => now()]);
}

function cobAsignacion(int $cuota, string $monto): void
{
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    $obligacion = (int) DB::table('cuotas_obligacion')->where('id', $cuota)->value('obligacion_pago_id');
    $tx = DB::table('transacciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
        'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'), 'tipo' => 'pago', 'estado' => 'confirmada', 'monto' => '1.00',
        'fecha_pago' => cobDia(0), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('asignaciones_pago')->insert(['transaccion_pago_id' => $tx, 'cuota_obligacion_id' => $cuota, 'monto' => $monto, 'created_at' => now(), 'updated_at' => now()]);
}

function cobIdentificador(int $obligacion): string
{
    return (string) DB::table('obligaciones_pago')->where('id', $obligacion)->value('identificador');
}

function cobPromesa(int $obligacion, string $fecha, string $resultado = 'promesa_pago'): void
{
    DB::table('seguimientos_cobranza')->insert(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion, 'canal' => 'llamada',
        'resultado' => $resultado, 'promesa_fecha' => $fecha, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * A: cuotas vencidas hace 40 y 3 días (100 + 100) y una futura (100) → vencido 200.00, mora 40 (crítica).
 * B: cuota de 100 vencida hace 10 días con 40 pagados → vencido 60.00, mora 10 (media).
 * C: cuota vencida pero pagada completa → fuera.  E: obligación anulada → fuera.
 * F: cuota de 100 vencida hace 5 días, pagada y luego reversada (+100, −100) → debe 100.00, mora 5 (reciente).
 * D: cuota de 80 vencida hace 3 días con promesa de pago vigente (mañana) → 80.00, mora 3 (reciente), va al final.
 *
 * @return array<string, int>
 */
function cobEscenario(): array
{
    $a = cobObligacion('73000001');
    cobCuota($a, 1, '100.00', cobDia(-40));
    cobCuota($a, 2, '100.00', cobDia(-3));
    cobCuota($a, 3, '100.00', cobDia(30));

    $b = cobObligacion('73000002');
    cobAsignacion(cobCuota($b, 1, '100.00', cobDia(-10)), '40.00');

    $c = cobObligacion('73000003');
    cobAsignacion(cobCuota($c, 1, '100.00', cobDia(-2)), '100.00');

    $e = cobObligacion('73000004', 'anulada');
    cobCuota($e, 1, '100.00', cobDia(-20));

    $f = cobObligacion('73000005');
    $cuotaF = cobCuota($f, 1, '100.00', cobDia(-5));
    cobAsignacion($cuotaF, '100.00');
    cobAsignacion($cuotaF, '-100.00');

    $d = cobObligacion('73000006');
    cobCuota($d, 1, '80.00', cobDia(-3));
    cobPromesa($d, cobDia(1));

    return ['a' => $a, 'b' => $b, 'c' => $c, 'e' => $e, 'f' => $f, 'd' => $d];
}

it('la cola prioriza por antigüedad, calcula el vencido neto de reversos y deja la promesa vigente al final', function (): void {
    cobEscenario();

    $r = $this->panelComo($this->usuarioConRol('secretaria'), 'GET', COBRANZA_COLA)->assertOk()->json('data');

    expect(collect($r['rows'])->pluck('dni')->all())->toBe(['73000001', '73000002', '73000005', '73000006'])
        ->and(collect($r['rows'])->pluck('monto_vencido')->all())->toBe(['200.00', '60.00', '100.00', '80.00'])
        ->and(collect($r['rows'])->pluck('dias_mora')->all())->toBe([40, 10, 5, 3])
        ->and(collect($r['rows'])->pluck('tramo')->all())->toBe(['critica', 'media', 'reciente', 'reciente'])
        ->and($r['rows'][0]['saldo'])->toBe('300.00')
        ->and($r['rows'][0]['cuotas_vencidas'])->toBe(2);

    expect($r['resumen']['obligaciones'])->toBe(4)
        ->and($r['resumen']['monto_vencido'])->toBe('440.00')
        ->and($r['resumen']['tramos']['critica'])->toBe(['cantidad' => 1, 'monto' => '200.00'])
        ->and($r['resumen']['tramos']['media'])->toBe(['cantidad' => 1, 'monto' => '60.00'])
        ->and($r['resumen']['tramos']['reciente'])->toBe(['cantidad' => 2, 'monto' => '180.00'])
        ->and($r['resumen']['promesas_vigentes'])->toBe(1)
        ->and($r['resumen']['promesas_incumplidas'])->toBe(0)
        ->and($r['resumen']['sin_contacto'])->toBe(3)
        ->and($r['truncado'])->toBeFalse()
        ->and($r['rows'][3]['promesa']['estado'])->toBe('vigente');
});

it('filtra por tramo, por promesa y por búsqueda sin alterar el resumen', function (): void {
    $o = cobEscenario();
    cobPromesa($o['b'], cobDia(-1)); // promesa vencida y la deuda sigue → incumplida
    $u = $this->usuarioConRol('admin');

    $critica = $this->panelComo($u, 'GET', cobCola(['tramo' => 'critica']))->json('data');
    expect($critica['rows'])->toHaveCount(1)->and($critica['resumen']['obligaciones'])->toBe(4);

    expect(collect($this->panelComo($u, 'GET', cobCola(['promesa' => 'incumplida']))->json('data.rows'))->pluck('dni')->all())->toBe(['73000002'])
        ->and(collect($this->panelComo($u, 'GET', cobCola(['promesa' => 'vigente']))->json('data.rows'))->pluck('dni')->all())->toBe(['73000006'])
        ->and(collect($this->panelComo($u, 'GET', cobCola(['promesa' => 'ninguna']))->json('data.rows'))->pluck('dni')->all())->toBe(['73000001', '73000005'])
        ->and(collect($this->panelComo($u, 'GET', cobCola(['busqueda' => '73000005']))->json('data.rows'))->pluck('dni')->all())->toBe(['73000005'])
        ->and($this->panelComo($u, 'GET', COBRANZA_COLA)->json('data.resumen.promesas_incumplidas'))->toBe(1);
});

it('registra un contacto, lo muestra en el historial y como último contacto, y audita sin la nota', function (): void {
    $o = cobEscenario();
    $u = $this->usuarioConRol('secretaria');
    $id = cobIdentificador($o['a']);

    $r = $this->panelComo($u, 'POST', "academico/pagos/obligaciones/{$id}/seguimientos", ['canal' => 'whatsapp', 'resultado' => 'contactado', 'nota' => 'Dijo que paga el viernes en ventanilla'])
        ->assertCreated()->json('data');
    expect($r['canal'])->toBe('whatsapp')->and($r['registrado_por'])->toBe($u->name)->and($r['promesa_fecha'])->toBeNull();

    expect($this->panelComo($u, 'GET', "academico/pagos/obligaciones/{$id}/seguimientos")->json('data.0.nota'))->toBe('Dijo que paga el viernes en ventanilla');
    $fila = collect($this->panelComo($u, 'GET', COBRANZA_COLA)->json('data.rows'))->firstWhere('dni', '73000001');
    expect($fila['ultimo_contacto']['canal'])->toBe('whatsapp')->and($fila['ultimo_contacto']['resultado'])->toBe('contactado');

    $auditoria = AuditLog::query()->where('action', 'cobranza_contacto')->firstOrFail();
    expect($auditoria->user_id)->toBe($u->id)->and(json_encode($auditoria->changes))->not->toContain('ventanilla');
});

it('una promesa exige fecha entre hoy y 90 días, y solo aplica al resultado «promesa de pago»', function (): void {
    $o = cobEscenario();
    $u = $this->usuarioConRol('admin');
    $url = 'academico/pagos/obligaciones/'.cobIdentificador($o['a']).'/seguimientos';
    $base = ['canal' => 'llamada', 'resultado' => 'promesa_pago'];

    $this->panelComo($u, 'POST', $url, $base)->assertStatus(422)->assertJsonValidationErrors('promesa_fecha');
    $this->panelComo($u, 'POST', $url, $base + ['promesa_fecha' => cobDia(-1)])->assertStatus(422)->assertJsonValidationErrors('promesa_fecha');
    $this->panelComo($u, 'POST', $url, $base + ['promesa_fecha' => cobDia(91)])->assertStatus(422)->assertJsonValidationErrors('promesa_fecha');
    $this->panelComo($u, 'POST', $url, ['canal' => 'llamada', 'resultado' => 'contactado', 'promesa_fecha' => cobDia(2)])->assertStatus(422);
    $this->panelComo($u, 'POST', $url, $base + ['promesa_fecha' => cobDia(2), 'promesa_monto' => '12,5'])->assertStatus(422);
    expect(SeguimientoCobranza::query()->count())->toBe(1); // solo la promesa sembrada de D

    $r = $this->panelComo($u, 'POST', $url, $base + ['promesa_fecha' => cobDia(5), 'promesa_monto' => '150.00'])->assertCreated()->json('data');
    expect($r['promesa_fecha'])->toBe(cobDia(5))->and($r['promesa_monto'])->toBe('150.00');
    expect(collect($this->panelComo($u, 'GET', COBRANZA_COLA)->json('data.rows'))->firstWhere('dni', '73000001')['promesa'])
        ->toBe(['estado' => 'vigente', 'fecha' => cobDia(5), 'monto' => '150.00']);
});

it('rechaza canales o resultados desconocidos, obligaciones anuladas e identificadores mal formados', function (): void {
    $o = cobEscenario();
    $u = $this->usuarioConRol('admin');
    $ok = 'academico/pagos/obligaciones/'.cobIdentificador($o['a']).'/seguimientos';

    $this->panelComo($u, 'POST', $ok, ['canal' => 'telepatia', 'resultado' => 'contactado'])->assertStatus(422);
    $this->panelComo($u, 'POST', $ok, ['canal' => 'llamada', 'resultado' => 'amenaza'])->assertStatus(422);
    $this->panelComo($u, 'POST', 'academico/pagos/obligaciones/'.cobIdentificador($o['e']).'/seguimientos', ['canal' => 'llamada', 'resultado' => 'contactado'])->assertStatus(409);
    $this->panelComo($u, 'POST', 'academico/pagos/obligaciones/no-es-un-uuid/seguimientos', ['canal' => 'llamada', 'resultado' => 'contactado'])->assertNotFound();
});

it('quien tiene pagos.ver consulta y quien tiene pagos.registrar registra; un seguimiento no se modifica ni se borra', function (): void {
    $o = cobEscenario();
    $url = 'academico/pagos/obligaciones/'.cobIdentificador($o['a']).'/seguimientos';

    foreach (['secretaria', 'coordinador', 'admin'] as $rol) {
        $u = $this->usuarioConRol($rol);
        $this->panelComo($u, 'GET', COBRANZA_COLA)->assertOk();
        $this->panelComo($u, 'POST', $url, ['canal' => 'correo', 'resultado' => 'sin_respuesta'])->assertCreated();
    }

    $s = SeguimientoCobranza::query()->firstOrFail();
    $s->nota = 'cambiada';
    expect(fn () => $s->save())->toThrow(LogicException::class)->and(fn () => $s->delete())->toThrow(LogicException::class);
    expect(User::query()->count())->toBeGreaterThan(0);
});

it('el recordatorio diario avisa una sola vez al día de la cobranza vencida, sin nombres de estudiantes', function (): void {
    cobEscenario();
    $admin = $this->usuarioConRol('admin');
    $sinPermiso = $this->usuarioConRol('secretaria');
    $sinPermiso->forceFill(['notificacion_preferencias' => ['interno' => false]])->save();

    config(['recordatorios.internos_enabled' => true]);
    $this->artisan('horus:recordatorios-diarios --dry-run')->assertSuccessful();
    expect(NotificacionInterna::query()->where('tipo', 'recordatorio_mora')->count())->toBe(0);

    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    $avisos = NotificacionInterna::query()->where('tipo', 'recordatorio_mora')->get();
    expect($avisos->where('user_id', $admin->id))->toHaveCount(1)
        ->and($avisos->where('user_id', $sinPermiso->id))->toHaveCount(0)
        ->and($avisos->first()->mensaje)->toContain('4 obligación(es)')->toContain('S/ 440.00')
        ->and($avisos->first()->mensaje)->not->toContain('Nombre73');

    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    expect(NotificacionInterna::query()->where('tipo', 'recordatorio_mora')->where('user_id', $admin->id)->count())->toBe(1);
});
