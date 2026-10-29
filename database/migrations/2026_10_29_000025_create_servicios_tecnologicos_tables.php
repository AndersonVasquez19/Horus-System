<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios_tecnologicos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador_publico')->unique();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 120);
            $table->string('categoria', 80)->nullable();
            $table->enum('estado', ['activo', 'inactivo', 'archivado'])->default('inactivo');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['estado', 'id']);
        });
        Schema::create('servicio_publicaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('servicio_tecnologico_id')->unique()->constrained('servicios_tecnologicos')->restrictOnDelete();
            $table->string('titulo', 120);
            $table->string('resumen', 500);
            $table->text('descripcion');
            $table->string('categoria', 80)->nullable();
            $table->enum('estado', ['borrador', 'publicada', 'retirada'])->default('borrador');
            $table->date('vigente_desde');
            $table->date('vigente_hasta');
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('autorizado_at')->nullable();
            $table->timestamps();
            $table->index(['estado', 'vigente_hasta', 'vigente_desde'], 'servicio_publicaciones_vigencia');
        });
        // SQLite no permite ADD CHECK; triggers equivalentes verifican estas reglas en la suite.
        $checks = [
            'servicios_tecnologicos' => ['servicios_revision_positiva' => 'revision >= 1'],
            'servicio_publicaciones' => ['servicios_vigencia_ordenada' => 'vigente_hasta >= vigente_desde',
                'servicios_autorizacion_fecha' => "estado <> 'publicada' OR autorizado_at IS NOT NULL"],
        ];
        foreach ($checks as $table => $rules) {
            foreach ($rules as $name => $condition) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($condition)");
                } elseif (DB::getDriverName() === 'sqlite') {
                    $newCondition = preg_replace('/\b(revision|vigente_hasta|vigente_desde|estado|autorizado_at)\b/', 'NEW.$1', $condition);
                    foreach (['INSERT', 'UPDATE'] as $operation) {
                        DB::unprepared("CREATE TRIGGER {$name}_{$operation} BEFORE $operation ON $table WHEN NOT ($newCondition) BEGIN SELECT RAISE(ABORT, '$name'); END");
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_publicaciones');
        Schema::dropIfExists('servicios_tecnologicos');
    }
};
