<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía la ficha editorial (portada + título de tarjetas) y añade las tarjetas relacionadas.
 * Solo agrega estructuras: no altera 000025/000026 ni toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicio_publicaciones', function (Blueprint $table): void {
            $table->string('titulo_tarjetas', 120)->nullable();
            $table->string('imagen_principal_ruta', 255)->nullable();
            $table->string('imagen_principal_alt', 160)->nullable();
        });

        Schema::create('servicio_publicacion_elementos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('servicio_publicacion_id')->constrained('servicio_publicaciones')->restrictOnDelete();
            $table->string('titulo', 120);
            $table->text('descripcion');
            $table->json('caracteristicas')->nullable();
            $table->string('icono', 60)->nullable();
            $table->string('variante_imagen', 20)->nullable();
            $table->string('imagen_ruta', 255)->nullable();
            $table->string('imagen_alt', 160)->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['servicio_publicacion_id', 'activo', 'orden', 'id'], 'servicio_elementos_visualizacion');
        });

        // SQLite no admite ADD CHECK; en la suite se aplican triggers equivalentes (como en 000025).
        $checks = [
            'servicio_elementos_orden_rango' => 'orden >= 0 AND orden <= 9999',
            'servicio_elementos_variante' => "variante_imagen IS NULL OR variante_imagen IN ('producto','escena')",
        ];
        foreach ($checks as $name => $condition) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE servicio_publicacion_elementos ADD CONSTRAINT $name CHECK ($condition)");
            } elseif (DB::getDriverName() === 'sqlite') {
                $nueva = preg_replace('/\b(orden|variante_imagen)\b/', 'NEW.$1', $condition);
                foreach (['INSERT', 'UPDATE'] as $operacion) {
                    DB::unprepared("CREATE TRIGGER {$name}_{$operacion} BEFORE $operacion ON servicio_publicacion_elementos WHEN NOT ($nueva) BEGIN SELECT RAISE(ABORT, '$name'); END");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_publicacion_elementos');
        Schema::table('servicio_publicaciones', function (Blueprint $table): void {
            $table->dropColumn(['titulo_tarjetas', 'imagen_principal_ruta', 'imagen_principal_alt']);
        });
    }
};
