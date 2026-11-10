<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lectura por usuario de las notificaciones generales (decisión del propietario, 10/11/2026).
 *
 * Antes, marcar una general como leída cambiaba `leida` en la fila y quedaba leída para todo el personal.
 * Desde ahora cada usuario registra aquí su propia lectura de una general; las personales siguen usando
 * `leida` en su fila. No se rellenan datos: una general con `leida=true` heredada se sigue considerando leída
 * para todos (semántica anterior), para no reabrir avisos antiguos.
 *
 * Solo crea una tabla nueva: no altera filas ni restricciones existentes. Probada solo en SQLite en memoria;
 * en PostgreSQL aplicar con `--path` mientras la migración 000022 siga suspendida.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notificacion_lecturas')) {
            return;
        }

        Schema::create('notificacion_lecturas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notificacion_id')->constrained('notificaciones_internas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('leida_at')->useCurrent();
            $table->unique(['notificacion_id', 'user_id'], 'notificacion_lecturas_unica');
            $table->index('user_id', 'notificacion_lecturas_user_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacion_lecturas');
    }
};
