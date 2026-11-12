<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aprobaciones_internas', function (Blueprint $table): void {
            $table->id();
            $table->string('titulo');
            $table->string('tipo')->default('solicitud');
            $table->text('descripcion')->nullable();
            $table->string('estado')->default('pendiente');
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprobador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comentario')->nullable();
            $table->string('referencia')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aprobaciones_internas');
    }
};
