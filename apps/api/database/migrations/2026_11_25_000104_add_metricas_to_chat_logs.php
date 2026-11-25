<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas del asistente: de dónde vino la consulta (`web` o `admin`, el probador) y el voto 👍/👎 del visitante.
 * Es aditiva: dos columnas nulas, sin tocar filas existentes ni guardar texto del visitante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table): void {
            $table->string('canal', 8)->nullable();
            $table->string('voto', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_logs', function (Blueprint $table): void {
            $table->dropColumn(['canal', 'voto']);
        });
    }
};
