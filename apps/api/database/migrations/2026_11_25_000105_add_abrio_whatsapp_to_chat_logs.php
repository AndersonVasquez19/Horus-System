<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clic en un botón de WhatsApp del chat: un sí o no en la fila de la respuesta (ni número ni mensaje).
 * Aditiva: una columna nula; no toca filas existentes. Se aplica junto con `000104`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table): void {
            $table->boolean('abrio_whatsapp')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_logs', function (Blueprint $table): void {
            $table->dropColumn('abrio_whatsapp');
        });
    }
};
