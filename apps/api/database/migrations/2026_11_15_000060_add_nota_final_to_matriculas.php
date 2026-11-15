<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado de una matrícula tras el cierre de acta (Ola 3, docs/ola3-diseno-datos.md §4.2).
 *
 * `nota_final` y `resultado` (`aprobado` | `desaprobado`) solo los escribe el cierre de acta. Las matrículas existentes
 * quedan sin resultado. Los `CHECK` de PostgreSQL (rango, valores y coherencia entre ambas columnas) se añaden solo en ese
 * motor. SQLite: `ALTER TABLE` en bruto para no reconstruir la tabla y perder los triggers que hacen de `CHECK`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table matriculas add column nota_final numeric null');
            DB::statement('alter table matriculas add column resultado varchar(12) null');

            return;
        }

        Schema::table('matriculas', function (Blueprint $table): void {
            $table->decimal('nota_final', 5, 2)->nullable();
            $table->string('resultado', 12)->nullable();
        });
        DB::statement('alter table matriculas add constraint matriculas_nota_final_valida check (nota_final is null or (nota_final >= 0 and nota_final <= 20))');
        DB::statement("alter table matriculas add constraint matriculas_resultado_valido check (resultado is null or resultado in ('aprobado', 'desaprobado'))");
        DB::statement('alter table matriculas add constraint matriculas_resultado_coherente check ((nota_final is null) = (resultado is null))');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table matriculas drop column resultado');
            DB::statement('alter table matriculas drop column nota_final');

            return;
        }

        DB::statement('alter table matriculas drop constraint if exists matriculas_resultado_coherente');
        DB::statement('alter table matriculas drop constraint if exists matriculas_resultado_valido');
        DB::statement('alter table matriculas drop constraint if exists matriculas_nota_final_valida');
        Schema::table('matriculas', function (Blueprint $table): void {
            $table->dropColumn(['nota_final', 'resultado']);
        });
    }
};
