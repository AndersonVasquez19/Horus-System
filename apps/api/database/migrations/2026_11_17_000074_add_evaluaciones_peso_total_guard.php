<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Horus Academic, Fase 8 (S4): la suma de pesos de las evaluaciones de una edición no pasa de 100, también en la base.
 *
 * Solo PostgreSQL (disparador `BEFORE INSERT OR UPDATE`): serializa por edición con un bloqueo consultivo y rechaza una fila que
 * haría pasar la suma de 100. En SQLite no se crea: allí lo impone `GestionCalificaciones` y esta restricción NO se prueba.
 * Aditiva y reversible; no toca datos existentes (solo valida escrituras futuras).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
create or replace function evaluaciones_peso_total() returns trigger language plpgsql as $fn$
declare suma numeric;
begin
    -- Un peso individual fuera de rango lo rechaza el CHECK de la columna (23514), no este disparador.
    if new.peso <= 0 or new.peso > 100 then return new; end if;
    perform pg_advisory_xact_lock(hashtext('evaluaciones-peso-' || new.convocatoria_id::text));
    select coalesce(sum(peso), 0) into suma from evaluaciones where convocatoria_id = new.convocatoria_id and id <> coalesce(new.id, -1);
    if suma + new.peso > 100 then
        raise exception 'La suma de pesos de las evaluaciones no puede pasar de 100';
    end if;
    return new;
end $fn$
SQL);
        DB::statement('create trigger evaluaciones_peso_total before insert or update of peso, convocatoria_id on evaluaciones for each row execute function evaluaciones_peso_total()');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('drop trigger if exists evaluaciones_peso_total on evaluaciones');
        DB::statement('drop function if exists evaluaciones_peso_total()');
    }
};
