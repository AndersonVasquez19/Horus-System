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
