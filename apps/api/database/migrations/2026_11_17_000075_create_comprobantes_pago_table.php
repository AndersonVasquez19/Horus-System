<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 7: captura del comprobante de pago (Yape) de cada pago.
 *
 * - Una por transacción (única). Vive en una tabla aparte porque las filas confirmadas del libro (`transacciones_pago`) son
 *   inmutables en PostgreSQL. Solo se adjunta o reemplaza mientras el pago está `pendiente` (lo impone `GestionPagos`).
 * - La imagen NO está en la base: `ruta` apunta a un archivo privado (`storage/app/private/comprobantes/…`); aquí solo quedan
 *   tipo, tamaño y huella SHA-256 de los bytes guardados.
 * - Sin borrado en cascada (`RESTRICT`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_pago', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaccion_pago_id')->unique()->constrained('transacciones_pago')->restrictOnDelete();
            $table->string('ruta', 80)->unique();
            $table->string('mime', 20);
            $table->unsignedInteger('tamano');
            $table->string('sha256', 64);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_pago');
    }
};
