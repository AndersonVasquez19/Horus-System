<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de respaldo para alinear `matriculas.monto_pagado` y `matriculas.estado` con el libro de pagos.
 *
 * **Esta migración NO modifica ningún dato.** La alineación la hace, a propósito y con confirmación, el comando
 * `horus:reconciliar-pagos` (simulación por defecto; `--aplicar --confirmo` guarda aquí el valor anterior de cada matrícula y
 * audita el lote; `--revertir=<lote>` lo restaura). Así una base con datos reales (p. ej. `horus_group`) nunca se reescribe sin un
 * informe previo, respaldo y orden expresa.
 *
 * Es repetible y su `down()` elimina solo esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('matriculas_monto_respaldo')) {
            return;
        }

        Schema::create('matriculas_monto_respaldo', function (Blueprint $table): void {
            $table->id();
            $table->uuid('lote')->index();
            $table->unsignedBigInteger('matricula_id')->index();
            $table->decimal('monto_pagado_anterior', 10, 2)->nullable();
            $table->string('estado_anterior', 20);
            $table->decimal('monto_pagado_nuevo', 10, 2);
            $table->string('estado_nuevo', 20);
            $table->timestamp('revertido_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matriculas_monto_respaldo');
    }
};
