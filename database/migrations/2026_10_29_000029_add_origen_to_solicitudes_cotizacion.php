<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19.2, Fase 2. Migración aditiva: solo identifica el origen del expediente.
 *
 * Las filas existentes y las altas del panel quedan como «administrativo» mediante el valor por defecto
 * (en PostgreSQL añadir una columna con valor constante no reescribe la tabla). No toca columnas,
 * índices, claves foráneas, triggers ni CHECK de 000027. No guarda datos personales, tokens ni secretos.
 * Ámbito anónimo, evidencia de aviso y reservas de idempotencia pública quedan pendientes de decisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('solicitudes_cotizacion', 'origen')) {
            return;
        }
        Schema::table('solicitudes_cotizacion', function (Blueprint $table): void {
            $table->enum('origen', ['administrativo', 'publico'])->default('administrativo');
        });
    }

    public function down(): void
    {
        // Destructivo solo para esta columna; no autorizado en bases reales.
        if (Schema::hasColumn('solicitudes_cotizacion', 'origen')) {
            Schema::table('solicitudes_cotizacion', function (Blueprint $table): void {
                $table->dropColumn('origen');
            });
        }
    }
};
