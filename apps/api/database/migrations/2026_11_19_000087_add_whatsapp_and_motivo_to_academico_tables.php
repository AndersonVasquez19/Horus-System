<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas nuevas de la Ola 2:
 * - `cursos.enlace_grupo_whatsapp`: enlace privado de invitación al grupo del curso (`https://chat.whatsapp.com/...`).
 * - `matriculas.motivo_anulacion`: razón obligatoria al anular o eliminar una matrícula (sin exponerla en auditoría).
 *
 * SQLite: `ALTER TABLE` directo para evitar reconstrucción de tabla y pérdida de triggers `CHECK`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: si una columna ya existe (reintento tras un fallo parcial), no se vuelve a crear.
        $cursos = ! Schema::hasColumn('cursos', 'enlace_grupo_whatsapp');
        $matriculas = ! Schema::hasColumn('matriculas', 'motivo_anulacion');

        if (DB::getDriverName() === 'sqlite') {
            if ($cursos) {
                DB::statement('alter table cursos add column enlace_grupo_whatsapp varchar(255) null');
            }
            if ($matriculas) {
                DB::statement('alter table matriculas add column motivo_anulacion varchar(200) null');
            }

            return;
        }

        if ($cursos) {
            Schema::table('cursos', function (Blueprint $table): void {
                $table->string('enlace_grupo_whatsapp', 255)->nullable()->after('horario');
            });
        }

        if ($matriculas) {
            Schema::table('matriculas', function (Blueprint $table): void {
                $table->string('motivo_anulacion', 200)->nullable()->after('observaciones');
            });
        }
    }

    public function down(): void
    {
        $cursos = Schema::hasColumn('cursos', 'enlace_grupo_whatsapp');
        $matriculas = Schema::hasColumn('matriculas', 'motivo_anulacion');

        if (DB::getDriverName() === 'sqlite') {
            if ($cursos) {
                DB::statement('alter table cursos drop column enlace_grupo_whatsapp');
            }
            if ($matriculas) {
                DB::statement('alter table matriculas drop column motivo_anulacion');
            }

            return;
        }

        if ($cursos) {
            Schema::table('cursos', function (Blueprint $table): void {
                $table->dropColumn('enlace_grupo_whatsapp');
            });
        }

        if ($matriculas) {
            Schema::table('matriculas', function (Blueprint $table): void {
                $table->dropColumn('motivo_anulacion');
            });
        }
    }
};
