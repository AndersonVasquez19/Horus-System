<?php

declare(strict_types=1);

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Aviso diario de pagos vencidos (antes «cobranza vencida»). Cifras calculadas a mano.
 * SQLite en memoria: comprueba reglas y exactitud, no el plan de ejecución de PostgreSQL.
 */

function pvDia(int $desdeHoy): string
{
    return now('America/Lima')->addDays($desdeHoy)->toDateString();
}

function pvObligacion(string $dni, string $estado = 'vigente', string $estadoMatricula = 'pendiente'): int
{
    $curso = Curso::query()->firstOrCreate(['codigo' => 'PV-1'], ['nombre' => 'Curso pagos', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'Nombre'.$dni, 'apellidos' => 'Apellido'.$dni, 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => $estadoMatricula, 'monto_pagado' => 0]);

    return DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'fraccionado', 'monto_total' => '500.00', 'moneda' => 'PEN', 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);
}

function pvCuota(int $obligacion, int $numero, string $monto, string $vence): int
{
    return DB::table('cuotas_obligacion')->insertGetId(['obligacion_pago_id' => $obligacion, 'numero' => $numero, 'monto' => $monto, 'vence' => $vence,
        'created_at' => now(), 'updated_at' => now()]);
}

/** Pago confirmado (o reverso, si el importe es negativo) asignado a una cuota. */
function pvPago(int $cuota, string $monto): void
{
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    $obligacion = (int) DB::table('cuotas_obligacion')->where('id', $cuota)->value('obligacion_pago_id');
    $tx = DB::table('transacciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
        'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'),
        'tipo' => str_starts_with($monto, '-') ? 'reverso' : 'pago', 'estado' => 'confirmada', 'monto' => ltrim($monto, '-'),
        'fecha_pago' => pvDia(0), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('asignaciones_pago')->insert(['transaccion_pago_id' => $tx, 'cuota_obligacion_id' => $cuota, 'monto' => $monto, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * A: cuotas vencidas hace 40 y 3 días (100 + 100) y una futura → vencido 200.00.
 * B: cuota de 100 vencida con 40 pagados → 60.00.
 * C: cuota vencida pagada completa → fuera.  E: obligación anulada → fuera.
 * F: cuota de 100 vencida, pagada y reversada → 100.00.
 * D: cuota de 80 vencida → 80.00.
 * Total: 4 obligaciones, S/ 440.00.
 */
function pvEscenario(): void
{
    $a = pvObligacion('73000001');
    pvCuota($a, 1, '100.00', pvDia(-40));
    pvCuota($a, 2, '100.00', pvDia(-3));
    pvCuota($a, 3, '100.00', pvDia(30));

    pvPago(pvCuota(pvObligacion('73000002'), 1, '100.00', pvDia(-10)), '40.00');

    pvPago(pvCuota(pvObligacion('73000003'), 1, '100.00', pvDia(-2)), '100.00');

    pvCuota(pvObligacion('73000004', 'anulada'), 1, '100.00', pvDia(-20));

    $cuotaF = pvCuota(pvObligacion('73000005'), 1, '100.00', pvDia(-5));
    pvPago($cuotaF, '100.00');
    pvPago($cuotaF, '-100.00');

    pvCuota(pvObligacion('73000006'), 1, '80.00', pvDia(-3));
}

it('avisa una sola vez al día de los pagos vencidos, sin nombres, y enlaza a Pagos filtrado por cuotas vencidas', function (): void {
    pvEscenario();
    $admin = $this->usuarioConRol('admin');
    $sinAvisos = $this->usuarioConRol('secretaria');
    $sinAvisos->forceFill(['notificacion_preferencias' => ['interno' => false]])->save();
    config(['recordatorios.internos_enabled' => true]);

    $this->artisan('horus:recordatorios-diarios --dry-run')->expectsOutputToContain('Pagos vencidos: 4')->assertSuccessful();
    expect(NotificacionInterna::query()->where('tipo', 'recordatorio_mora')->count())->toBe(0);

    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    $avisos = NotificacionInterna::query()->where('tipo', 'recordatorio_mora')->get();
    $delAdmin = $avisos->where('user_id', $admin->id);

    expect($delAdmin)->toHaveCount(1)
        ->and($avisos->where('user_id', $sinAvisos->id))->toHaveCount(0)
        ->and($delAdmin->first()->titulo)->toBe('Pagos vencidos: 4 obligación(es)')
        ->and($delAdmin->first()->mensaje)->toBe('4 obligación(es) con cuotas vencidas por S/ 440.00.')
        ->and($delAdmin->first()->vinculo)->toBe('/admin/academico/pagos?estado=vencidas');
});

it('la cantidad del aviso coincide con el listado de Pagos filtrado por cuotas vencidas', function (): void {
    pvEscenario();
    $admin = $this->usuarioConRol('admin');
    config(['recordatorios.internos_enabled' => true]);

    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();

    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?estado=vencidas')->assertOk()->assertJsonPath('meta.total', 4);
});

it('no cuenta matrículas anuladas y no avisa si no hay nada vencido', function (): void {
    pvCuota(pvObligacion('73000007', 'vigente', 'anulada'), 1, '100.00', pvDia(-4));
    pvCuota(pvObligacion('73000008'), 1, '100.00', pvDia(0));
    $this->usuarioConRol('admin');
    config(['recordatorios.internos_enabled' => true]);

    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();

    expect(NotificacionInterna::query()->where('tipo', 'recordatorio_mora')->count())->toBe(0);
});

it('ya no genera el aviso de riesgo de deserción ni su contador', function (): void {
    pvEscenario();
    $this->usuarioConRol('coordinador');
    config(['recordatorios.internos_enabled' => true]);

    $this->artisan('horus:recordatorios-diarios')->doesntExpectOutputToContain('Riesgo')->assertSuccessful();

    expect(NotificacionInterna::query()->where('tipo', 'recordatorio_riesgo')->count())->toBe(0);
});
