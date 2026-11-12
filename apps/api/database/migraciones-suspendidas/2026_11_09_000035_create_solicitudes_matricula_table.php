<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_matricula', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->char('huella', 64);
            $table->string('curso_titulo', 120);
            $table->string('modalidad', 30);
            // Contacto y texto libre cifrados por el modelo; nunca se copian a auditoría.
            $table->text('nombres');
            $table->text('apellidos');
            $table->text('email');
            $table->text('telefono');
            $table->text('mensaje')->nullable();
            // Participante menor de edad: datos del representante, cifrados por el modelo (nunca a auditoría).
            $table->boolean('es_menor')->default(false);
            $table->text('representante_nombre')->nullable();
            $table->text('representante_email')->nullable();
            $table->text('representante_telefono')->nullable();
            // HMAC de curso + correo + nombre: evita duplicar una solicitud abierta de la misma persona para el mismo curso.
            $table->char('huella_duplicado', 64)->nullable()->index();
            $table->timestamp('consentimiento_at');
            $table->string('estado', 20)->default('pendiente')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->text('nota_revision')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_matricula');
    }
};
