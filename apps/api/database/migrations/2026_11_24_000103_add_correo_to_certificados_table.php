<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Constancia del envío por correo del certificado (Fase «correo de certificados»). Solo guarda cuándo se envió y un código de error
 * corto, nunca el correo del estudiante. Aditiva y reversible; sin permisos nuevos (se envía con `certificados.emitir`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('certificados', 'correo_enviado_at')) {
            return;
        }

        Schema::table('certificados', function (Blueprint $table): void {
            $table->timestamp('correo_enviado_at')->nullable();
            $table->string('correo_error', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificados', function (Blueprint $table): void {
            $table->dropColumn(['correo_enviado_at', 'correo_error']);
        });
    }
};
