<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curso_publicaciones', function (Blueprint $table): void {
            $table->date('fecha_inicio')->nullable()->after('horario');
            $table->string('duracion', 120)->nullable()->after('fecha_inicio');
            $table->string('certificacion', 200)->nullable()->after('duracion');
            $table->text('temario')->nullable()->after('certificacion');
        });
    }

    public function down(): void
    {
        Schema::table('curso_publicaciones', function (Blueprint $table): void {
            $table->dropColumn(['fecha_inicio', 'duracion', 'certificacion', 'temario']);
        });
    }
};
