<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table): void {
            $table->string('etiqueta', 24)->nullable()->after('estado');
        });

        Schema::create('encuestas_curso', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('matricula_id')->unique()->constrained('matriculas')->cascadeOnDelete();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->cascadeOnDelete();
            $table->unsignedTinyInteger('calificacion')->nullable();
            $table->string('sugerencia_curso', 120)->nullable();
            $table->timestamp('respondida_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encuestas_curso');

        Schema::table('matriculas', function (Blueprint $table): void {
            $table->dropColumn('etiqueta');
        });
    }
};
