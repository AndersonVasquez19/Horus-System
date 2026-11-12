<?php

declare(strict_types=1);

namespace App\Support\Panel;

use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use Illuminate\Http\JsonResponse;

/**
 * Detalle de un servicio para el panel (datos, estado editorial calculado y ficha con sus tarjetas). Lo comparten
 * el controlador de servicios y el del contenido de la ficha para devolver siempre la revisión vigente (`ETag`).
 * Nunca expone rutas de almacenamiento: las imágenes se piden por las rutas autenticadas del panel.
 */
final class DetalleServicio
{
    public static function responder(ServicioTecnologico $servicio, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $servicio = ServicioTecnologico::query()->findOrFail($servicio->id);
        $ficha = $servicio->publicacion;
        $editorial = $ficha === null ? 'sin_ficha' : $ficha->estado;
        if ($editorial === 'publicada') {
            $hoy = now('America/Lima')->toDateString();
            $editorial = $ficha->vigente_hasta->toDateString() < $hoy ? 'caducada'
                : ($ficha->vigente_desde->toDateString() > $hoy ? 'programada' : 'publicada');
        }

        $datos = [
            'id' => $servicio->id,
            'codigo' => $servicio->codigo,
            'nombre' => $servicio->nombre,
            'categoria' => $servicio->categoria,
            'estado' => $servicio->estado,
            'identificador_publico' => $servicio->identificador_publico,
            'revision' => $servicio->etag(),
            'estado_editorial' => $editorial,
            'actualizado_en' => $servicio->updated_at?->toIso8601String(),
            'ficha' => $ficha === null ? null : self::ficha($ficha),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $servicio->etag());
    }

    /** @return array<string, mixed> */
    private static function ficha(ServicioPublicacion $ficha): array
    {
        $elementos = $ficha->elementos()->get();

        return [
            'estado' => $ficha->estado,
            'titulo' => $ficha->titulo,
            'resumen' => $ficha->resumen,
            'descripcion' => $ficha->descripcion,
            'categoria' => $ficha->categoria,
            'vigente_desde' => $ficha->vigente_desde->toDateString(),
            'vigente_hasta' => $ficha->vigente_hasta->toDateString(),
            'titulo_tarjetas' => $ficha->titulo_tarjetas,
            'imagen_principal_alt' => $ficha->imagen_principal_alt,
            'con_imagen' => $ficha->imagen_principal_ruta !== null,
            'tarjetas' => $elementos->count(),
            'elementos' => $elementos->map(fn ($elemento): array => [
                'id' => $elemento->id,
                'titulo' => $elemento->titulo,
                'descripcion' => $elemento->descripcion,
                'caracteristicas' => $elemento->caracteristicas ?? [],
                'variante_imagen' => $elemento->variante_imagen,
                'imagen_alt' => $elemento->imagen_alt,
                'activo' => $elemento->activo,
                'orden' => $elemento->orden,
                'con_imagen' => $elemento->imagen_ruta !== null,
            ])->values()->all(),
            'autorizado_en' => $ficha->autorizado_at?->toIso8601String(),
        ];
    }
}
