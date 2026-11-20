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
            $table->string('imagen_ruta', 255)->nullable()->after('resumen');
        });
    }

    public function down(): void
    {
        Schema::table('curso_publicaciones', function (Blueprint $table): void {
            $table->dropColumn('imagen_ruta');
        });
    }
};
