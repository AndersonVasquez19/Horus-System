<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion');
            $table->string('categoria');
            $table->unsignedInteger('duracion_horas');
            $table->decimal('precio', 10, 2);
            $table->string('modalidad');
            $table->string('horario', 100);
            $table->unsignedInteger('cupos_maximos')->default(30);
            $table->unsignedInteger('cupos_disponibles')->default(30);
            $table->string('estado')->default('activo');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursos');
    }
};
