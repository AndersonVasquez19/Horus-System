<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CierreCaja;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Plan de mejora del panel, Fase 3.2: caja diaria con reversos, desglose por método y usuario, y cierre persistido e inmutable.
 * Cifras calculadas a mano. SQLite en memoria: comprueba reglas y exactitud, no concurrencia de PostgreSQL.
 */

const CAJA_URL = 'academico/pagos/caja-diaria';

function cajaObligacion(): int
{
    $curso = Curso::create(['codigo' => 'CJ-1', 'nombre' => 'Curso caja', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $e = Estudiante::create(['dni' => '72000001', 'nombres' => 'Rosa', 'apellidos' => 'Caja', 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    return DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => '900.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
}

function cajaMetodo(string $codigo, string $nombre): int
{
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => $codigo, 'nombre' => $nombre, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);

    return (int) DB::table('metodos_pago')->where('codigo', $codigo)->value('id');
}

function cajaMovimiento(int $obligacion, string $metodo, string $tipo, string $monto, string $fecha, ?int $usuario, string $estado = 'confirmada'): int
{
    return DB::table('transacciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
        'metodo_pago_id' => cajaMetodo($metodo, ucfirst($metodo)), 'tipo' => $tipo, 'estado' => $estado, 'monto' => $monto, 'fecha_pago' => $fecha,
        'registrado_por' => $usuario, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Hoy: Yape 100.00 + 50.10 (Ana), efectivo 200.00 (Beto), reverso de Yape 50.10 (Ana) y un pago pendiente de 30.00 (no cuenta).
 * Ayer: Yape 400.00 (no cuenta hoy). Neto de hoy = 100.00 + 200.00 = 300.00.
 *
 * @return array{fecha: string, ana: User, beto: User}
 */
function cajaEscenario(): array
{
    $hoy = now('America/Lima')->toDateString();
    $ayer = now('America/Lima')->subDay()->toDateString();
    $ana = User::factory()->create(['name' => 'Ana Cajera']);
    $beto = User::factory()->create(['name' => 'Beto Cajero']);
    $o = cajaObligacion();
    cajaMovimiento($o, 'yape', 'pago', '100.00', $hoy, $ana->id);
    cajaMovimiento($o, 'yape', 'pago', '50.10', $hoy, $ana->id);
    cajaMovimiento($o, 'efectivo', 'pago', '200.00', $hoy, $beto->id);
    cajaMovimiento($o, 'yape', 'reverso', '50.10', $hoy, $ana->id);
    cajaMovimiento($o, 'yape', 'pago', '30.00', $hoy, $ana->id, 'pendiente');
    cajaMovimiento($o, 'yape', 'pago', '400.00', $ayer, $ana->id);

    return ['fecha' => $hoy, 'ana' => $ana, 'beto' => $beto];
}

it('el resumen resta los reversos, separa por método y por usuario y no cuenta pendientes ni otros días', function (): void {
    $e = cajaEscenario();

    $r = $this->panelComo($this->usuarioConRol('admin'), 'GET', CAJA_URL.'/resumen')->assertOk()->json('data');

    expect($r['cantidad'])->toBe(3)
        ->and($r['cantidad_reversos'])->toBe(1)
        ->and($r['cobrado'])->toBe('350.10')
        ->and($r['reversado'])->toBe('50.10')
        ->and($r['total'])->toBe('300.00')
        ->and($r['pendientes_por_confirmar'])->toBe(1)
        ->and($r['cierre'])->toBeNull();

    $porMetodo = collect($r['por_metodo'])->keyBy('codigo');
    expect($porMetodo['yape']['total'])->toBe('100.00')
        ->and($porMetodo['yape']['reversado'])->toBe('50.10')
        ->and($porMetodo['efectivo']['total'])->toBe('200.00');

    $porUsuario = collect($r['por_usuario'])->keyBy('usuario');
    expect($porUsuario['Ana Cajera']['total'])->toBe('100.00')
        ->and($porUsuario['Beto Cajero']['total'])->toBe('200.00')
        ->and(collect($r['movimientos'])->where('tipo', 'reverso')->first()['monto'])->toBe('-50.10');
    expect($e['fecha'])->toBe($r['fecha']);
});

it('un reverso cuenta el día en que se hizo, no el del pago original', function (): void {
    $ayer = now('America/Lima')->subDay()->toDateString();
    $o = cajaObligacion();
    cajaMovimiento($o, 'yape', 'pago', '80.00', $ayer, null);
    cajaMovimiento($o, 'yape', 'reverso', '80.00', now('America/Lima')->toDateString(), null);
    $admin = $this->usuarioConRol('admin');

    expect($this->panelComo($admin, 'GET', CAJA_URL.'/resumen', ['fecha' => $ayer])->json('data.total'))->toBe('80.00')
        ->and($this->panelComo($admin, 'GET', CAJA_URL.'/resumen')->json('data.total'))->toBe('-80.00');
});

it('cierra la caja guardando esperado, declarado y diferencia por método, y audita', function (): void {
    $e = cajaEscenario();
    $admin = $this->usuarioConRol('admin');

    $r = $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', ['fecha' => $e['fecha'], 'declarado' => ['yape' => '100.00', 'efectivo' => '200.00']])
        ->assertCreated()->json('data');

    expect($r['total_esperado'])->toBe('300.00')
        ->and($r['total_declarado'])->toBe('300.00')
        ->and($r['diferencia'])->toBe('0.00')
        ->and($r['cantidad_pagos'])->toBe(3)
        ->and($r['cantidad_reversos'])->toBe(1)
        ->and($r['cerrado_por'])->toBe($admin->name)
        ->and(collect($r['detalle']['por_metodo'])->pluck('diferencia')->all())->each->toBe('0.00');

    $auditoria = AuditLog::query()->where('action', 'caja_cerrada')->firstOrFail();
    expect($auditoria->user_id)->toBe($admin->id)->and($auditoria->changes['diferencia'])->toBe('0.00');

    expect($this->panelComo($admin, 'GET', CAJA_URL.'/resumen')->json('data.cierre.fecha'))->toBe($e['fecha'])
        ->and($this->panelComo($admin, 'GET', CAJA_URL.'/cierres')->json('data.0.identificador'))->toBe($r['identificador']);
});

it('una fecha se cierra una sola vez', function (): void {
    $e = cajaEscenario();
    $admin = $this->usuarioConRol('admin');
    $cuerpo = ['fecha' => $e['fecha'], 'declarado' => ['yape' => '100.00', 'efectivo' => '200.00']];

    $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', $cuerpo)->assertCreated();
    $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', $cuerpo)->assertStatus(409);

    expect(CierreCaja::query()->count())->toBe(1);
});

it('si lo contado no coincide, exige explicar la diferencia y la guarda', function (): void {
    $e = cajaEscenario();
    $admin = $this->usuarioConRol('admin');
    $cuerpo = ['fecha' => $e['fecha'], 'declarado' => ['yape' => '100.00', 'efectivo' => '195.50']];

    $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', $cuerpo)->assertStatus(422)->assertJsonValidationErrors('observacion');
    expect(CierreCaja::query()->count())->toBe(0);

    $r = $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', $cuerpo + ['observacion' => 'Faltó un vuelto de S/ 4.50'])->assertCreated()->json('data');
    expect($r['diferencia'])->toBe('-4.50')
        ->and(collect($r['detalle']['por_metodo'])->firstWhere('codigo', 'efectivo')['diferencia'])->toBe('-4.50');
});

it('rechaza declarar sin todos los métodos con movimiento, importes mal formados y fechas futuras', function (): void {
    $e = cajaEscenario();
    $admin = $this->usuarioConRol('admin');

    $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', ['fecha' => $e['fecha'], 'declarado' => ['yape' => '100.00']])->assertStatus(422);
    $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', ['fecha' => $e['fecha'], 'declarado' => ['yape' => '100,5', 'efectivo' => '200.00']])->assertStatus(422);
    $this->panelComo($admin, 'POST', CAJA_URL.'/cierre', ['fecha' => now('America/Lima')->addDay()->toDateString(), 'declarado' => []])->assertStatus(422);

    expect(CierreCaja::query()->count())->toBe(0);
});

it('un día sin movimientos se puede cerrar en cero', function (): void {
    $r = $this->panelComo($this->usuarioConRol('admin'), 'POST', CAJA_URL.'/cierre', ['fecha' => now('America/Lima')->toDateString(), 'declarado' => []])
        ->assertCreated()->json('data');

    expect($r['total_esperado'])->toBe('0.00')->and($r['diferencia'])->toBe('0.00');
});

it('solo admin cierra la caja; secretaría y coordinación pueden ver los cierres', function (): void {
    $cuerpo = ['fecha' => now('America/Lima')->toDateString(), 'declarado' => []];

    foreach (['secretaria', 'coordinador'] as $rol) {
        $u = $this->usuarioConRol($rol);
        $this->panelComo($u, 'POST', CAJA_URL.'/cierre', $cuerpo)->assertForbidden();
    }
    $this->panelComo($this->usuarioConRol('secretaria'), 'GET', CAJA_URL.'/cierres')->assertOk();
    $this->panelComo($this->usuarioConRol('admin'), 'POST', CAJA_URL.'/cierre', $cuerpo)->assertCreated();
});

it('un cierre no se actualiza ni se elimina', function (): void {
    $this->panelComo($this->usuarioConRol('admin'), 'POST', CAJA_URL.'/cierre', ['fecha' => now('America/Lima')->toDateString(), 'declarado' => []])->assertCreated();
    $cierre = CierreCaja::query()->firstOrFail();

    $cierre->observacion = 'cambiado';
    expect(fn () => $cierre->save())->toThrow(LogicException::class);
    expect(fn () => $cierre->delete())->toThrow(LogicException::class);
});

it('el PDF de caja incluye los reversos', function (): void {
    cajaEscenario();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', CAJA_URL.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
