<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\EncuestaCurso;
use App\Models\SesionClase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints públicos de solo lectura / respuesta única para Horus Academic (vía BFF):
 *  - Cronograma público de una edición (sin login, cero PII, sin enlaces virtuales).
 *  - Encuesta post-clase con token único por matrícula.
 */
final class AcademicoPublicoController extends Controller
{
    public function cronograma(string $codigo): JsonResponse
    {
        $convocatoria = Convocatoria::query()
            ->where('codigo', strtoupper(trim($codigo)))
            ->whereIn('estado', ['abierta', 'en_curso', 'cerrada'])
            ->with(['curso:id,codigo,nombre', 'horarios'])
            ->firstOrFail();

        $sesiones = SesionClase::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', '!=', 'cancelada')
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn (SesionClase $s): array => [
                'numero' => (int) $s->numero,
                'fecha' => substr((string) $s->fecha, 0, 10),
                'hora_inicio' => substr((string) $s->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $s->hora_fin, 0, 5),
                'tema' => $s->tema,
                'estado' => (string) $s->estado,
            ])
            ->all();

        $horarios = $convocatoria->horarios
            ->sortBy(['dia_semana', 'hora_inicio'])
            ->values()
            ->map(fn ($h): array => [
                'dia_semana' => (int) $h->dia_semana,
                'hora_inicio' => substr((string) $h->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $h->hora_fin, 0, 5),
            ])
            ->all();

        return response()->json([
            'data' => [
                'codigo' => $convocatoria->codigo,
                'nombre' => $convocatoria->nombre ?? $convocatoria->curso?->nombre,
                'curso' => $convocatoria->curso?->nombre,
                'modalidad' => $convocatoria->modalidad,
                'estado' => $convocatoria->estado,
                'fecha_inicio' => $convocatoria->fecha_inicio->toDateString(),
                'fecha_fin' => $convocatoria->fecha_fin->toDateString(),
                'horarios' => $horarios,
                'sesiones' => $sesiones,
            ],
        ]);
    }

    public function verEncuesta(string $identificador): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $encuesta = EncuestaCurso::query()
            ->where('identificador', strtolower($identificador))
            ->with(['convocatoria:id,codigo,nombre,curso_id', 'convocatoria.curso:id,nombre'])
            ->firstOrFail();

        return response()->json([
            'data' => [
                'identificador' => $encuesta->identificador,
                'curso' => $encuesta->convocatoria?->curso->nombre ?? 'Curso Horus',
                'edicion' => $encuesta->convocatoria?->codigo,
                'respondida' => $encuesta->respondida_at !== null,
            ],
        ]);
    }

    public function responderEncuesta(Request $request, string $identificador): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $encuesta = EncuestaCurso::query()
            ->where('identificador', strtolower($identificador))
            ->firstOrFail();

        if ($encuesta->respondida_at !== null) {
            return response()->json(['message' => 'Esta encuesta ya fue respondida.'], 409);
        }

        $datos = $request->validate([
            'calificacion' => ['required', 'integer', 'between:1,3'],
            'sugerencia_curso' => ['nullable', 'string', 'max:120'],
        ], [
            'calificacion.required' => 'Selecciona una calificación.',
            'calificacion.between' => 'Calificación no válida.',
        ]);

        $encuesta->calificacion = (int) $datos['calificacion'];
        $sugerencia = isset($datos['sugerencia_curso']) ? trim((string) $datos['sugerencia_curso']) : '';
        $encuesta->sugerencia_curso = $sugerencia !== '' ? $sugerencia : null;
        $encuesta->respondida_at = now();
        $encuesta->save();

        return response()->json(['message' => '¡Gracias por tu opinión!']);
    }
}
