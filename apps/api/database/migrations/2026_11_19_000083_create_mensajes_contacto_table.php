<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes del formulario público «Contáctanos». Solo guardan lo que la persona escribió para que el equipo la responda:
 * nombre, al menos un medio de contacto, asunto y mensaje. No se guarda la IP (solo un identificador opaco del visitante para
 * detectar abuso). Se atienden desde el panel con el permiso de reclamaciones; no crean ni cambian ninguna otra tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mensajes_contacto')) {
            return;
        }

        Schema::create('mensajes_contacto', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('nombre', 120);
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 160)->nullable();
            $table->string('asunto', 40);
            $table->text('mensaje');
            $table->string('estado', 12)->default('nuevo');
            $table->foreignId('atendido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('atendido_at')->nullable();
            $table->string('visitante', 64)->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensajes_contacto');
    }
};
