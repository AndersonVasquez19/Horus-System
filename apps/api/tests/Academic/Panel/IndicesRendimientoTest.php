<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Migración 2026_11_23_000098 (índices del tablero, Pagos y Matrículas): instalación nueva, base ya poblada (idempotente) y reversible.
 * SQLite en memoria: comprueba que los índices existen y que la migración es segura, no el plan de ejecución de PostgreSQL.
 */

const INDICES_FASE_2 = [
    'transacciones_pago' => 'transacciones_pago_estado_fecha_pago_index',
    'cuotas_obligacion' => 'cuotas_obligacion_vence_index',
    'matriculas' => 'matriculas_estado_fecha_matricula_index',
];

function migracionIndices(): Migration
{
    return require database_path('migrations/2026_11_23_000098_add_dashboard_performance_indexes.php');
}

it('una instalación nueva crea los tres índices', function (): void {
    foreach (INDICES_FASE_2 as $tabla => $indice) {
        expect(Schema::hasIndex($tabla, $indice))->toBeTrue("falta {$indice}");
    }
});

it('es idempotente: repetirla sobre una base que ya los tiene no falla ni duplica', function (): void {
    migracionIndices()->up();
    migracionIndices()->up();

    foreach (INDICES_FASE_2 as $tabla => $indice) {
        $nombres = collect(Schema::getIndexes($tabla))->pluck('name');
        expect($nombres->filter(fn ($n): bool => $n === $indice))->toHaveCount(1);
    }
});

it('es reversible: down los quita y up los vuelve a crear', function (): void {
    migracionIndices()->down();
    foreach (INDICES_FASE_2 as $tabla => $indice) {
        expect(Schema::hasIndex($tabla, $indice))->toBeFalse();
    }

    migracionIndices()->up();
    foreach (INDICES_FASE_2 as $tabla => $indice) {
        expect(Schema::hasIndex($tabla, $indice))->toBeTrue();
    }
});

it('no toca los índices anteriores (únicos y de integridad)', function (): void {
    migracionIndices()->down();

    expect(Schema::hasIndex('matriculas', 'matriculas_vigente_unica'))->toBeTrue()
        ->and(Schema::hasIndex('transacciones_pago', 'transacciones_pago_obligacion_pago_id_estado_index'))->toBeTrue()
        ->and(Schema::hasIndex('obligaciones_pago', 'obligaciones_pago_una_vigente'))->toBeTrue();
});
