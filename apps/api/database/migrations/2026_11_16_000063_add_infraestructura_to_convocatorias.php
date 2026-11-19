<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 3: asignación de infraestructura a las ediciones (`convocatorias`).
 *
 * Columnas nulas (`sede_id`, `aula_id`, `plataforma_virtual_id`) más `exige_infraestructura` (false por defecto). Las
 * ediciones existentes quedan intactas (sin infraestructura y sin exigencia); el servicio marca `exige_infraestructura = true`
 * en las ediciones NUEVAS, que son las únicas obligadas a tener sede y aula (presencial) o plataforma (virtual) antes de abrirse.
 *
 * Por qué no un `CHECK … NOT VALID` general como proponía el diseño: en PostgreSQL un `CHECK` se evalúa en cada `UPDATE` de la
 * fila aunque sea `NOT VALID`, y las matrículas actualizan `cupos_disponibles` de ediciones heredadas sin infraestructura: esas
 * operaciones fallarían. Con el indicador, la regla de «completa» solo aplica a lo nuevo y los `CHECK` se añaden ya válidos.
 *
 * Reglas en PostgreSQL (el servicio las valida igual en SQLite):
 *  - presencial no tiene plataforma; virtual no tiene sede ni aula; un aula exige sede.
 *  - una edición que exige infraestructura y no está en borrador/cancelada tiene sede y aula (presencial) o plataforma (virtual).
 *  - clave foránea compuesta (sede_id, aula_id) → aulas(sede_id, id): el aula pertenece a la sede.
 *
 * SQLite: `ALTER TABLE … ADD COLUMN` (no reconstruye la tabla ni pierde disparadores; mismo criterio que 000057 y 000061).
 * Sin borrado en cascada (`RESTRICT`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table convocatorias add column sede_id integer null references sedes (id) on delete restrict');
            DB::statement('alter table convocatorias add column aula_id integer null references aulas (id) on delete restrict');
            DB::statement('alter table convocatorias add column plataforma_virtual_id integer null references plataformas_virtuales (id) on delete restrict');
            DB::statement('alter table convocatorias add column exige_infraestructura integer not null default 0');
            DB::statement('create index convocatorias_sede_id_index on convocatorias (sede_id)');
            DB::statement('create index convocatorias_aula_id_index on convocatorias (aula_id)');
            DB::statement('create index convocatorias_plataforma_virtual_id_index on convocatorias (plataforma_virtual_id)');

            return;
        }

        Schema::table('convocatorias', function (Blueprint $table): void {
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->restrictOnDelete();
            $table->foreignId('aula_id')->nullable()->constrained('aulas')->restrictOnDelete();
            $table->foreignId('plataforma_virtual_id')->nullable()->constrained('plataformas_virtuales')->restrictOnDelete();
            $table->boolean('exige_infraestructura')->default(false);
            $table->index('sede_id');
            $table->index('aula_id');
            $table->index('plataforma_virtual_id');
        });

        DB::statement('alter table convocatorias add constraint convocatorias_aula_de_la_sede foreign key (sede_id, aula_id) references aulas (sede_id, id) on delete restrict');
        DB::statement("alter table convocatorias add constraint convocatorias_infra_excluyente check (
            (modalidad = 'presencial' and plataforma_virtual_id is null)
            or (modalidad = 'virtual' and sede_id is null and aula_id is null)
            or modalidad in ('semipresencial', 'hibrido'))");
        DB::statement('alter table convocatorias add constraint convocatorias_aula_exige_sede check (aula_id is null or sede_id is not null)');
        DB::statement("alter table convocatorias add constraint convocatorias_infra_completa check (
            not exige_infraestructura
            or estado in ('borrador', 'cancelada')
            or (modalidad = 'presencial' and sede_id is not null and aula_id is not null)
            or (modalidad = 'virtual' and plataforma_virtual_id is not null))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite no puede quitar columnas con clave foránea sin reconstruir la tabla: solo es un entorno de pruebas,
            // donde la base se recrea entera.
            foreach (['sede_id', 'aula_id', 'plataforma_virtual_id'] as $columna) {
                DB::statement("drop index if exists convocatorias_{$columna}_index");
            }

            return;
        }

        DB::statement('alter table convocatorias drop constraint if exists convocatorias_infra_completa');
        DB::statement('alter table convocatorias drop constraint if exists convocatorias_aula_exige_sede');
        DB::statement('alter table convocatorias drop constraint if exists convocatorias_infra_excluyente');
        DB::statement('alter table convocatorias drop constraint if exists convocatorias_aula_de_la_sede');
        Schema::table('convocatorias', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sede_id');
            $table->dropConstrainedForeignId('aula_id');
            $table->dropConstrainedForeignId('plataforma_virtual_id');
            $table->dropColumn('exige_infraestructura');
        });
    }
};
