<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicarCursoRequest;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Services\ImagenesCursos;
use App\Services\PublicacionCursos;
use App\Support\Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ficha pública de un curso (publicar y retirar) para el panel de apps/web. Mismas reglas, Form Request y servicio
 * ({@see PublicacionCursos}) que el editor Blade, con una diferencia: aquí la precondición de revisión es
 * obligatoria. La revisión viaja en `If-Match` o en el campo `_revision` (el BFF reenvía solo el campo) y se compara
 * bajo el bloqueo del curso y de la ficha; si la ficha cambió, 412 sin escribir nada.
 */
final class CursoPublicacionController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PublicacionCursos $publicacion,
        private readonly ImagenesCursos $imagenes,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:cursos.publicar')];
    }

    public function show(int $curso): JsonResponse
    {
        $modelo = Curso::withTrashed()->findOrFail($curso);
        $ficha = CursoPublicacion::query()->where('curso_id', $modelo->id)->first();
        $revision = PublicacionCursos::revision($modelo, $ficha);

        return response()->json(['data' => [
            'revision' => $revision,
            'publicable' => ! $modelo->trashed() && $modelo->estado === 'activo',
            'curso' => ['id' => $modelo->id, 'codigo' => $modelo->codigo, 'nombre' => $modelo->nombre, 'modalidad' => $modelo->modalidad],
            'ficha' => $ficha === null ? null : self::ficha($ficha),
        ]])->header('ETag', $revision)->header('Cache-Control', 'no-store, private');
    }

    public function update(PublicarCursoRequest $request, int $curso): JsonResponse
    {
        $modelo = Curso::withTrashed()->findOrFail($curso);
        $actual = $request->user();
        abort_unless($actual !== null, 403);

        $this->publicacion->publicar(
            $modelo,
            $request->safe()->except('confirmacion_publica'),
            $actual,
            fn (Curso $bloqueado, ?CursoPublicacion $ficha) => $this->comprobarRevision($request, $bloqueado, $ficha),
        );

        return response()->json([
            'message' => 'La ficha pública fue autorizada. Su visibilidad depende de la vigencia indicada y del estado del curso.',
        ] + Entrada::lista($this->show($curso)->getData(true)));
    }

    public function retirar(Request $request, int $curso): JsonResponse
    {
        $modelo = Curso::withTrashed()->findOrFail($curso);
        $actual = $request->user();
        abort_unless($actual !== null, 403);

        $this->publicacion->despublicar(
            $modelo,
            $actual,
            fn (Curso $bloqueado, ?CursoPublicacion $ficha) => $this->comprobarRevision($request, $bloqueado, $ficha),
        );

        return response()->json(['message' => 'La ficha dejó de estar publicada.'] + Entrada::lista($this->show($curso)->getData(true)));
    }

    public function subirImagen(Request $request, int $curso): JsonResponse
    {
        $modelo = Curso::withTrashed()->findOrFail($curso);
        $actual = $request->user();
        abort_unless($actual !== null, 403);

        $request->validate([
            'imagen' => ImagenesCursos::reglas(true),
        ]);

        $archivo = $request->file('imagen');
        if (! $archivo instanceof UploadedFile) {
            throw ValidationException::withMessages(['imagen' => 'Adjunta una imagen válida.']);
        }

        $ruta = $this->imagenes->guardar($archivo);

        try {
            $anterior = $this->publicacion->actualizarImagen(
                $modelo,
                $ruta,
                $actual,
                fn (Curso $bloqueado, ?CursoPublicacion $ficha) => $this->comprobarRevision($request, $bloqueado, $ficha),
            );
        } catch (Throwable $e) {
            $this->imagenes->liberarSiHuerfana($ruta);
            throw $e;
        }

        if ($anterior !== null && $anterior !== $ruta) {
            $this->imagenes->liberarSiHuerfana($anterior);
        }

        return response()->json([
            'message' => 'La imagen referencial del curso fue actualizada.',
        ] + Entrada::lista($this->show($curso)->getData(true)));
    }

    public function eliminarImagen(Request $request, int $curso): JsonResponse
    {
        $modelo = Curso::withTrashed()->findOrFail($curso);
        $actual = $request->user();
        abort_unless($actual !== null, 403);

        $anterior = $this->publicacion->eliminarImagen(
            $modelo,
            $actual,
            fn (Curso $bloqueado, ?CursoPublicacion $ficha) => $this->comprobarRevision($request, $bloqueado, $ficha),
        );

        $this->imagenes->liberarSiHuerfana($anterior);

        return response()->json([
            'message' => 'La imagen referencial fue retirada de la ficha.',
        ] + Entrada::lista($this->show($curso)->getData(true)));
    }

    /** @return array<string, mixed> */
    private static function ficha(CursoPublicacion $ficha): array
    {
        return [
            'titulo' => $ficha->titulo,
            'resumen' => $ficha->resumen,
            'imagen_url' => ImagenesCursos::url($ficha->imagen_ruta),
            'modalidad' => $ficha->modalidad,
            'horario' => $ficha->horario,
            'fecha_inicio' => $ficha->fecha_inicio === null ? null : Carbon::parse($ficha->fecha_inicio)->toDateString(),
            'duracion' => $ficha->duracion,
            'certificacion' => $ficha->certificacion,
            'temario' => $ficha->temario,
            'inscripcion' => $ficha->inscripcion,
            'vigente_desde' => Carbon::parse($ficha->vigente_desde)->toDateString(),
            'vigente_hasta' => Carbon::parse($ficha->vigente_hasta)->toDateString(),
            'publicada' => $ficha->publicada,
            'autorizado_en' => Carbon::parse($ficha->autorizado_at)->toIso8601String(),
        ];
    }

    private function comprobarRevision(Request $request, Curso $curso, ?CursoPublicacion $ficha): void
    {
        $cabecera = $request->header('If-Match');
        $campo = $request->input('_revision');
        $revision = $cabecera ?? $campo;

        if (! is_string($revision) || ! preg_match('/\A"[a-f0-9]{64}"\z/D', $revision)
            || ($cabecera !== null && is_string($campo) && $cabecera !== $campo)) {
            throw ValidationException::withMessages(['revision' => 'Abre la ficha de nuevo para obtener su revisión actual.']);
        }

        if (! hash_equals(PublicacionCursos::revision($curso, $ficha), $revision)) {
            abort(412, 'La ficha cambió. Vuelve a cargarla antes de continuar.');
        }
    }
}
