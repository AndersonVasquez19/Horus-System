<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluaciones y calificaciones (Ola 3, docs/ola3-diseno-datos.md §4.2).
 *
 * Una evaluación pertenece a una convocatoria y tiene un peso (la suma de la convocatoria no pasa de 100: lo valida el
 * servicio). Una calificación es la nota (escala vigesimal) de una matrícula en una evaluación; una sola por pareja. Sin
 * borrados en cascada. Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluaciones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->decimal('peso', 5, 2);
            $table->date('fecha')->nullable();
            $table->unsignedSmallInteger('orden')->default(1);
            $table->timestamps();

            $table->index(['convocatoria_id', 'orden']);
        });

        Schema::create('calificaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('evaluacion_id')->constrained('evaluaciones')->restrictOnDelete();
            $table->decimal('nota', 5, 2);
            $table->string('observacion', 200)->nullable();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['matricula_id', 'evaluacion_id']);
            $table->index('evaluacion_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table evaluaciones add constraint evaluaciones_peso_valido check (peso > 0 and peso <= 100)');
            DB::statement('alter table calificaciones add constraint calificaciones_nota_valida check (nota >= 0 and nota <= 20)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calificaciones');
        Schema::dropIfExists('evaluaciones');
    }
};
