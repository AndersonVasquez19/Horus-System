<?php

declare(strict_types=1);

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Plan de mejora del panel, Fase 2.1: el tablero (`academico/resumen`) calcula en SQL y no repite consultas. Las cifras del escenario se
 * calcularon a mano y deben ser IDÉNTICAS antes y después de optimizar; además se acota el número de consultas.
 * SQLite en memoria: mide consultas y exactitud, no el plan de ejecución de PostgreSQL.
 */

function trCurso(string $codigo): Curso
{
    return Curso::create(['codigo' => $codigo, 'nombre' => 'Curso '.$codigo, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
}

function trEdicion(Curso $curso, string $codigo, string $estado): Convocatoria
{
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(20)->toDateString(), 'cupos_maximos' => 20]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = $codigo;
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = 15;
    $c->revision = 1;
    $c->save();

    return $c;
}

function trMatricula(Convocatoria $c, string $dni, string $estado = 'pendiente'): Matricula
{
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'Nombre'.$dni, 'apellidos' => 'Apellido'.$dni, 'estado' => 'activo']);

    return Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id,
        'fecha_matricula' => now()->toDateString(), 'estado' => $estado, 'monto_pagado' => 0]);
}

function trObligacion(Matricula $m, string $total, string $estado = 'vigente'): int
{
    return DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => $total, 'moneda' => 'PEN', 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);
}

function trCuota(int $obligacion, int $numero, string $monto, string $vence): int
{
    return DB::table('cuotas_obligacion')->insertGetId(['obligacion_pago_id' => $obligacion, 'numero' => $numero, 'monto' => $monto, 'vence' => $vence,
        'created_at' => now(), 'updated_at' => now()]);
}

function trTransaccion(int $obligacion, string $tipo, string $estado, string $monto, ?int $reversaDe = null): int
{
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('transacciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
        'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'), 'tipo' => $tipo, 'estado' => $estado, 'monto' => $monto,
        'fecha_pago' => now('America/Lima')->toDateString(), 'reversa_de_id' => $reversaDe, 'created_at' => now(), 'updated_at' => now()]);
}

function trAsignacion(int $transaccion, int $cuota, string $monto): void
{
    DB::table('asignaciones_pago')->insert(['transaccion_pago_id' => $transaccion, 'cuota_obligacion_id' => $cuota, 'monto' => $monto,
        'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Cifras esperadas (S/):
 *  - cobrado del mes = 100 + 200 + 100 (pagos confirmados) − 100 (reverso) = 300.00
 *  - por confirmar = 1 pago de 50.00
 *  - saldo por cobrar: O1 200 + O3 150 + O4 80 = 430.00 en 3 matrículas (O2 saldada y O5 anulada no cuentan)
 *  - cuotas: 1 vencida de 200.00 (O1 #2) y 1 por vencer de 80.00 (O4); la de O3 vence en 30 días y queda fuera
 */
function trEscenario(): void
{
    $curso = trCurso('TR-1');
    $a = trEdicion($curso, 'CNV-2026-7001', 'en_curso');
    $b = trEdicion($curso, 'CNV-2026-7002', 'en_curso');
    trEdicion($curso, 'CNV-2026-7003', 'abierta');

    $ana = trMatricula($a, '71000001');
    $luis = trMatricula($a, '71000002');
    $eva = trMatricula($a, '71000003');
    $m4 = trMatricula($b, '71000004');
    $m5 = trMatricula($b, '71000005', 'pagada');
    $m6 = trMatricula($b, '71000006');

    // O1: total 300, confirmado 100, pendiente 50 → debe 200. Cuota 1 (100, pagada) y cuota 2 (200, vencida).
    $o1 = trObligacion($ana, '300.00');
    $c11 = trCuota($o1, 1, '100.00', now('America/Lima')->subDays(5)->toDateString());
    trCuota($o1, 2, '200.00', now('America/Lima')->subDay()->toDateString());
    trAsignacion(trTransaccion($o1, 'pago', 'confirmada', '100.00'), $c11, '100.00');
    trTransaccion($o1, 'pago', 'pendiente', '50.00');

    // O2: total 200, saldada.
    $o2 = trObligacion($luis, '200.00');
    $c21 = trCuota($o2, 1, '200.00', now('America/Lima')->addDays(2)->toDateString());
    trAsignacion(trTransaccion($o2, 'pago', 'confirmada', '200.00'), $c21, '200.00');

    // O3: total 150, un pago de 100 reversado → neto 0 → debe 150. Su cuota vence en 30 días.
    $o3 = trObligacion($eva, '150.00');
    $c31 = trCuota($o3, 1, '150.00', now('America/Lima')->addDays(30)->toDateString());
    $pago = trTransaccion($o3, 'pago', 'confirmada', '100.00');
    trAsignacion($pago, $c31, '100.00');
    trAsignacion(trTransaccion($o3, 'reverso', 'confirmada', '100.00', $pago), $c31, '-100.00');

    // O4: total 80 sin movimientos; cuota por vencer en 3 días.
    $o4 = trObligacion($m4, '80.00');
    trCuota($o4, 1, '80.00', now('America/Lima')->addDays(3)->toDateString());

    // O5: anulada, no cuenta.
    trCuota(trObligacion($m5, '500.00', 'anulada'), 1, '500.00', now('America/Lima')->subDays(9)->toDateString());
    unset($m6);
}

it('el tablero mantiene exactas las cifras de dinero, cuotas y saldos', function (): void {
    trEscenario();

    $d = $this->panelComo($this->usuarioConRol('admin'), 'GET', 'academico/resumen')->assertOk()->json('data');

    expect($d['indicadores']['ingresos_mes'])->toBe('300.00')
        ->and($d['negocio']['cobrado_mes'])->toBe('300.00')
        ->and($d['negocio']['saldo_por_cobrar'])->toBe('430.00')
        ->and($d['acciones']['pagos_por_confirmar'])->toBe(['cantidad' => 1, 'monto' => '50.00'])
        ->and($d['acciones']['matriculas_con_saldo'])->toBe(['cantidad' => 3, 'monto' => '430.00'])
        ->and($d['acciones']['cuotas'])->toBe(['vencidas' => 1, 'monto_vencido' => '200.00', 'por_vencer' => 1, 'monto_por_vencer' => '80.00'])
        ->and($d['indicadores']['matriculas_mes'])->toBe(6)
        ->and($d['indicadores']['matriculas_pendientes'])->toBe(5);
});

/** Cuenta las consultas que dispara una petición al tablero. */
function trConsultas(Closure $peticion): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $peticion()->assertOk();
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $consultas;
}

it('sin caché el tablero hace menos consultas que antes (36) y no crece con las ediciones (sin N+1)', function (): void {
    config(['panel.resumen_cache_segundos' => 0]);
    trEscenario();
    $admin = $this->usuarioConRol('admin');
    $this->panelComo($admin, 'GET', 'academico/resumen')->assertOk(); // calienta permisos/roles
    $con3Ediciones = trConsultas(fn () => $this->panelComo($admin, 'GET', 'academico/resumen'));

    // Más ediciones en curso con matrículas: el número de consultas no debe subir.
    $curso = trCurso('TR-2');
    foreach (['CNV-2026-7101', 'CNV-2026-7102', 'CNV-2026-7103', 'CNV-2026-7104'] as $i => $codigo) {
        trMatricula(trEdicion($curso, $codigo, 'en_curso'), '7200000'.$i);
    }
    $con7Ediciones = trConsultas(fn () => $this->panelComo($admin, 'GET', 'academico/resumen'));

    expect($con3Ediciones)->toBeLessThanOrEqual(LIMITE_CONSULTAS_TABLERO)
        ->and($con7Ediciones)->toBe($con3Ediciones);
});

describe('caché del tablero', function (): void {
    it('la segunda carga usa la caché (menos consultas) con las mismas cifras', function (): void {
        config(['panel.resumen_cache_segundos' => 60]);
        trEscenario();
        $admin = $this->usuarioConRol('admin');

        $primera = trConsultas(fn () => $this->panelComo($admin, 'GET', 'academico/resumen'));
        $segunda = trConsultas(fn () => $this->panelComo($admin, 'GET', 'academico/resumen'));

        expect($segunda)->toBeLessThan($primera);
        $d = $this->panelComo($admin, 'GET', 'academico/resumen')->json('data');
        expect($d['negocio']['saldo_por_cobrar'])->toBe('430.00')->and($d['acciones']['pagos_por_confirmar']['cantidad'])->toBe(1);
    });

    it('un pago nuevo se ve en la siguiente carga, sin esperar a que caduque la caché', function (): void {
        config(['panel.resumen_cache_segundos' => 600]);
        trEscenario();
        $admin = $this->usuarioConRol('admin');
        expect($this->panelComo($admin, 'GET', 'academico/resumen')->json('data.acciones.pagos_por_confirmar.cantidad'))->toBe(1);

        $o1 = (int) DB::table('obligaciones_pago')->where('monto_total', 300)->value('id');
        $t = new TransaccionPago;
        $t->identificador = (string) Str::uuid();
        $t->obligacion_pago_id = $o1;
        $t->metodo_pago_id = (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id');
        $t->tipo = 'pago';
        $t->estado = 'pendiente';
        $t->monto = '25.00';
        $t->fecha_pago = now('America/Lima')->toDateString();
        $t->save();

        $d = $this->panelComo($admin, 'GET', 'academico/resumen')->json('data');
        expect($d['acciones']['pagos_por_confirmar'])->toBe(['cantidad' => 2, 'monto' => '75.00']);
    });

    it('con 0 segundos no guarda nada en caché', function (): void {
        config(['panel.resumen_cache_segundos' => 0]);
        trEscenario();
        $admin = $this->usuarioConRol('admin');
        $this->panelComo($admin, 'GET', 'academico/resumen')->assertOk(); // calienta permisos/roles

        $primera = trConsultas(fn () => $this->panelComo($admin, 'GET', 'academico/resumen'));
        $segunda = trConsultas(fn () => $this->panelComo($admin, 'GET', 'academico/resumen'));

        expect($segunda)->toBe($primera);
    });
});

/** Línea base antes de la Fase 2.1: 36 consultas (≈10 son de sesión, usuario y permisos). +2 en la Fase 3 (semáforo de reclamaciones 3.12 y satisfacción 3.11). */
const LIMITE_CONSULTAS_TABLERO = 32;
