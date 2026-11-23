<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificados académicos emitidos por Horus Group SRL.
 *
 * Aditiva y reversible: cada matrícula puede tener historial de certificados anulados,
 * pero el servicio garantiza a lo sumo un certificado en estado `emitido` por matrícula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificados', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('codigo', 32)->unique();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('convocatoria_id')->nullable()->constrained('convocatorias')->nullOnDelete();
            $table->foreignId('estudiante_id')->constrained('estudiantes')->restrictOnDelete();
            $table->foreignId('curso_id')->constrained('cursos')->restrictOnDelete();
            $table->string('estado', 20)->default('emitido');
            $table->decimal('porcentaje_asistencia', 5, 2)->nullable();
            $table->decimal('nota_final', 5, 2)->nullable();
            $table->timestamp('emitido_en');
            $table->foreignId('emitido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_excepcion', 300)->nullable();
            $table->timestamp('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index(['matricula_id', 'estado']);
            $table->index(['convocatoria_id', 'estado']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificados');
    }
};
