<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranza asistida (Fase 3.1): bitácora de contactos de cobro y promesas de pago por obligación. Solo se agregan filas (no se
 * editan ni se borran: lo escrito es la evidencia del seguimiento). No usa permisos nuevos: ver = `pagos.ver`, registrar = `pagos.registrar`.
 * Aditiva y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seguimientos_cobranza')) {
            return;
        }

        Schema::create('seguimientos_cobranza', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('obligacion_pago_id')->constrained('obligaciones_pago')->restrictOnDelete();
            $table->string('canal', 20);
            $table->string('resultado', 20);
            $table->string('nota', 300)->nullable();
            $table->date('promesa_fecha')->nullable();
            $table->decimal('promesa_monto', 10, 2)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['obligacion_pago_id', 'id']);
            $table->index('promesa_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguimientos_cobranza');
    }
};
