<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 7: finanzas (libro de pagos inmutable). Solo PEN, monto fijo, sin descuentos ni mora.
 *
 * - `metodos_pago`: catálogo; hoy solo `yape`.
 * - `obligaciones_pago`: lo que debe una matrícula (pago completo o fraccionado). Una vigente por matrícula (índice parcial).
 * - `cuotas_obligacion`: siempre existe al menos una (el pago completo es una sola cuota).
 * - `transacciones_pago`: libro. Un pago nace `pendiente` y pasa a `confirmada` o `rechazada`; un REVERSO es otra fila (`tipo =
 *   reverso`, `confirmada`) enlazada al pago original (uno por pago). Nunca se editan montos ni se borran filas (sin cascada).
 * - `asignaciones_pago`: reparto con signo de cada transacción confirmada entre las cuotas (el reverso las niega).
 * - La idempotencia es única por (obligación, clave). Los CHECK de PostgreSQL y los disparadores de inmutabilidad se añaden solo en
 *   ese motor y NO se prueban en SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_pago', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 60);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
        DB::table('metodos_pago')->insert(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('obligaciones_pago', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->string('concepto', 120);
            $table->string('plan', 12);
            $table->decimal('monto_total', 12, 2);
            $table->string('moneda', 3)->default('PEN');
            $table->string('estado', 10)->default('vigente');
            $table->string('motivo_anulacion', 200)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('matricula_id');
        });
        DB::statement("create unique index obligaciones_pago_una_vigente on obligaciones_pago (matricula_id) where estado = 'vigente'");

        Schema::create('cuotas_obligacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('obligacion_pago_id')->constrained('obligaciones_pago')->restrictOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->decimal('monto', 12, 2);
            $table->date('vence');
            $table->timestamps();

            $table->unique(['obligacion_pago_id', 'numero']);
        });

        Schema::create('transacciones_pago', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('obligacion_pago_id')->constrained('obligaciones_pago')->restrictOnDelete();
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago')->restrictOnDelete();
            $table->string('tipo', 8);
            $table->string('estado', 10);
            $table->decimal('monto', 12, 2);
            $table->date('fecha_pago');
            $table->string('referencia', 80)->nullable();
            $table->string('motivo', 200)->nullable();
            $table->foreignId('reversa_de_id')->nullable()->unique()->constrained('transacciones_pago')->restrictOnDelete();
            $table->string('clave_idempotencia', 64)->nullable();
            $table->string('huella', 64)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_en')->nullable();
            $table->timestamps();

            $table->unique(['obligacion_pago_id', 'clave_idempotencia']);
            $table->index(['obligacion_pago_id', 'estado']);
        });

        Schema::create('asignaciones_pago', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaccion_pago_id')->constrained('transacciones_pago')->restrictOnDelete();
            $table->foreignId('cuota_obligacion_id')->constrained('cuotas_obligacion')->restrictOnDelete();
            $table->decimal('monto', 12, 2);
            $table->timestamps();

            $table->index('cuota_obligacion_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table obligaciones_pago add constraint obligaciones_pago_valida check (plan in ('completo', 'fraccionado') and estado in ('vigente', 'anulada') and moneda = 'PEN' and monto_total > 0)");
            DB::statement('alter table cuotas_obligacion add constraint cuotas_obligacion_monto_positivo check (monto > 0)');
            DB::statement("alter table transacciones_pago add constraint transacciones_pago_valida check (tipo in ('pago', 'reverso') and estado in ('pendiente', 'confirmada', 'rechazada') and monto > 0 and (tipo = 'pago' or (reversa_de_id is not null and estado = 'confirmada')))");
            // Inmutabilidad: el libro solo admite pasar un pago de `pendiente` a `confirmada`/`rechazada`; nada se borra.
            DB::unprepared(<<<'SQL'
create or replace function transacciones_pago_inmutable() returns trigger language plpgsql as $fn$
begin
    if tg_op = 'DELETE' then raise exception 'El libro de pagos no admite borrados'; end if;
    if old.estado <> 'pendiente' or new.id <> old.id or new.obligacion_pago_id <> old.obligacion_pago_id or new.monto <> old.monto
       or new.tipo <> old.tipo or new.metodo_pago_id <> old.metodo_pago_id or new.fecha_pago <> old.fecha_pago then
        raise exception 'El libro de pagos es inmutable';
    end if;
    return new;
end $fn$
SQL);
            DB::statement('create trigger transacciones_pago_inmutable before update or delete on transacciones_pago for each row execute function transacciones_pago_inmutable()');
            DB::unprepared(<<<'SQL'
create or replace function asignaciones_pago_inmutable() returns trigger language plpgsql as $fn$
begin raise exception 'Las asignaciones de pago son inmutables'; end $fn$
SQL);
            DB::statement('create trigger asignaciones_pago_inmutable before update or delete on asignaciones_pago for each row execute function asignaciones_pago_inmutable()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop trigger if exists asignaciones_pago_inmutable on asignaciones_pago');
            DB::statement('drop trigger if exists transacciones_pago_inmutable on transacciones_pago');
            DB::statement('drop function if exists asignaciones_pago_inmutable()');
            DB::statement('drop function if exists transacciones_pago_inmutable()');
        }
        Schema::dropIfExists('asignaciones_pago');
        Schema::dropIfExists('transacciones_pago');
        Schema::dropIfExists('cuotas_obligacion');
        Schema::dropIfExists('obligaciones_pago');
        Schema::dropIfExists('metodos_pago');
    }
};
