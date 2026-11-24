<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Calificacion;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Matricula;
use App\Models\User;
use App\Services\ActaExcel;
use App\Services\GestionCalificaciones;
use App\Services\ReaperturaActa;
use App\Support\Entrada;
use App\Support\Panel\PrecondicionesPanel;
use App\Support\TemporaryExportFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Evaluaciones, notas y acta de una convocatoria. Las notas son datos personales del estudiante: toda respuesta va con
 * `Cache-Control: no-store, private` y el acta exportada deja constancia de acceso. La regla vive en
 * {@see GestionCalificaciones}; aquí solo se validan los datos de entrada y se da forma a la respuesta.
 *
 * @phpstan-import-type Acta from ActaExcel
 */
final class CalificacionController extends Controller implements HasMiddleware
{
    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly GestionCalificaciones $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:calificaciones.ver', only: ['index']),
            // El permiso exacto (registrar una nota nueva o corregir una existente) lo decide el servicio.
            new Middleware('permission:calificaciones.registrar|calificaciones.corregir', only: ['nota']),
            new Middleware('permission:calificaciones.corregir', only: ['crearEvaluacion', 'actualizarEvaluacion', 'eliminarEvaluacion']),
            new Middleware('permission:convocatorias.cerrar', only: ['cerrarActa', 'solicitarReapertura', 'reabrirActa']),
            new Middleware('permission:calificaciones.exportar', only: ['exportarActa', 'exportarBorradorActa']),
        ];
    }

    public function index(string $identificador): JsonResponse
    {
        return $this->respuesta($this->convocatoria($identificador));
    }

    public function crearEvaluacion(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->crearEvaluacion($request, $this->convocatoria($identificador)->identificador, $this->datosEvaluacion($request));

        return $this->respuesta($this->convocatoria($identificador), 201, 'La evaluación se registró correctamente.');
    }

    public function actualizarEvaluacion(Request $request, string $identificador, string $evaluacion): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizarEvaluacion($request, $this->convocatoria($identificador)->identificador, $evaluacion, $this->datosEvaluacion($request));

        return $this->respuesta($this->convocatoria($identificador), 200, 'La evaluación se actualizó.');
    }

    public function eliminarEvaluacion(Request $request, string $identificador, string $evaluacion): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->eliminarEvaluacion($request, $this->convocatoria($identificador)->identificador, $evaluacion);

        return $this->respuesta($this->convocatoria($identificador), 200, 'La evaluación se eliminó.');
    }

    /** Registra o corrige una nota. Corregir exige `motivo` y la `nota_actual` que el usuario ve. */
    public function nota(Request $request, string $identificador): JsonResponse
    {
        $datos = $request->validate([
            'evaluacion' => ['required', 'uuid'],
            'matricula_id' => ['required', 'integer', 'min:1'],
            'nota' => ['required', 'numeric', 'between:0,20', 'decimal:0,2'],
            'nota_actual' => ['nullable', 'numeric', 'between:0,20'],
            'motivo' => ['nullable', 'string', 'max:200', 'not_regex:/[<>]/u'],
        ], [
            'nota.between' => 'La nota debe estar entre 0 y 20.',
            'nota.decimal' => 'La nota admite hasta dos decimales.',
            'nota.numeric' => 'La nota debe ser un número.',
        ]);

        $this->gestion->registrarNota(
            $request,
            $this->convocatoria($identificador)->identificador,
            $datos['evaluacion'],
            (int) $datos['matricula_id'],
            $datos['nota'],
            $datos['motivo'] ?? null,
            $datos['nota_actual'] ?? null,
        );

        return $this->respuesta($this->convocatoria($identificador), 200, 'La nota se guardó.');
    }

    public function cerrarActa(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->cerrarActa($request, $this->convocatoria($identificador)->identificador);

        return $this->respuesta($this->convocatoria($identificador), 200, 'El acta se cerró correctamente.');
    }

    /** Solicita a Gerencia la reapertura de un acta cerrada (motivo obligatorio). */
    public function solicitarReapertura(Request $request, string $identificador, ReaperturaActa $reapertura): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[<>]/u']]);
        /** @var User $usuario */
        $usuario = $request->user();
        $solicitud = $reapertura->solicitar($usuario, $this->convocatoria($identificador)->identificador, Entrada::texto($datos['motivo']));

        return response()->json(['message' => 'Solicitud enviada a Gerencia. Cuando la aprueben podrás reabrir el acta.', 'data' => ['aprobacion' => $solicitud->id]], 201);
    }

    /** Reabre el acta con una solicitud de reapertura aprobada (una sola vez por aprobación). */
    public function reabrirActa(Request $request, string $identificador, ReaperturaActa $reapertura): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $resultado = $reapertura->reabrir($usuario, $this->convocatoria($identificador)->identificador);
        $aviso = $resultado['certificados_emitidos'] > 0 ? " Hay {$resultado['certificados_emitidos']} certificado(s) emitido(s): anúlalos y reemítelos si cambian las notas." : '';

        return $this->respuesta($this->convocatoria($identificador), 200, 'El acta se reabrió: las notas finales se recalcularán al cerrarla de nuevo.'.$aviso);
    }

    /** Excel del acta de una convocatoria cerrada, con constancia de acceso (sin las notas). */
    public function exportarActa(Request $request, string $identificador): BinaryFileResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $convocatoria = $this->convocatoria($identificador);
        abort_unless($convocatoria->estado === 'cerrada', 409, 'El acta se exporta una vez cerrada.');

        return $this->generarExcelActa($convocatoria, $usuario, 'acta', 'acta_exportada');
    }

    /** Exporta el avance actual de notas en Excel sin requerir el cierre de acta. */
    public function exportarBorradorActa(Request $request, string $identificador): BinaryFileResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $convocatoria = $this->convocatoria($identificador);

        return $this->generarExcelActa($convocatoria, $usuario, 'borrador-acta', 'acta_borrador_exportada');
    }

    private function generarExcelActa(Convocatoria $convocatoria, User $usuario, string $prefijo, string $accionAuditoria): BinaryFileResponse
    {
        $acta = $this->matriz($convocatoria);

        $nombre = $prefijo.'-'.strtolower($convocatoria->codigo).'-'.now()->format('Ymd-His').'.xlsx';
        $disco = Storage::disk('local');
        if (! $disco->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }
        $archivo = $disco->path('exports/'.Str::uuid()->toString().'.xlsx');
        $temporal = new TemporaryExportFile($archivo, false);

        try {
            app(ActaExcel::class)->write($acta, $archivo, $prefijo === 'borrador-acta');
            clearstatcache(true, $archivo);
            if (! is_file($archivo) || filesize($archivo) === 0) {
                throw new RuntimeException('No se pudo completar el archivo de exportación.');
            }

            AuditLog::record($accionAuditoria, Convocatoria::class, $convocatoria->id, [
                'filename' => $nombre, 'matriculas' => count($acta['matriculas']),
            ], $usuario->id);

            return response()->download($temporal, $nombre, self::CABECERAS)->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($archivo)) {
                unlink($archivo);
            }

            throw $error;
        }
    }

    /** @return array{nombre: string, peso: string, fecha: string|null} */
    private function datosEvaluacion(Request $request): array
    {
        /** @var array{nombre: string, peso: int|float|string, fecha?: string|null} $datos */
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u'],
            'peso' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'peso.gt' => 'El peso debe ser mayor que 0.',
            'peso.max' => 'El peso no puede pasar de 100.',
            'peso.decimal' => 'El peso admite hasta dos decimales.',
        ]);

        return ['nombre' => $datos['nombre'], 'peso' => (string) $datos['peso'], 'fecha' => $datos['fecha'] ?? null];
    }

    private function convocatoria(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->with(['curso:id,codigo,nombre', 'evaluaciones'])
            ->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function respuesta(Convocatoria $convocatoria, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $cuerpo = array_filter(['message' => $mensaje, 'data' => $this->matriz($convocatoria)], fn ($valor): bool => $valor !== null);

        return response()->json($cuerpo, $estado, self::CABECERAS)->header('ETag', $convocatoria->etag());
    }

    /**
     * Evaluaciones y matriz de notas de las matrículas vigentes de la convocatoria. Sin paginar: una convocatoria no pasa de
     * sus cupos máximos.
     *
     * @return Acta
     */
    private function matriz(Convocatoria $convocatoria): array
    {
        $evaluaciones = $convocatoria->evaluaciones;
        $conNotas = Calificacion::query()->whereIn('evaluacion_id', $evaluaciones->pluck('id'))
            ->distinct()->pluck('evaluacion_id')->flip();
        $identificadores = $evaluaciones->pluck('identificador', 'id');

        $matriculas = Matricula::query()->with('estudiante:id,dni,nombres,apellidos')
            ->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'anulada')->orderBy('id')->get();
        $notas = Calificacion::query()->whereIn('matricula_id', $matriculas->pluck('id'))
            ->whereIn('evaluacion_id', $evaluaciones->pluck('id'))->get()->groupBy('matricula_id');

        $total = $evaluaciones->sum(fn (Evaluacion $e): int => GestionCalificaciones::centesimas($e->peso));
        // El porcentaje de asistencia solo se incluye para quien puede ver la asistencia (mismo cálculo que el resumen de asistencia).
        $asistencia = auth()->user()?->can('asistencias.ver') === true
            ? collect(app(\App\Services\GestionAsistencias::class)->resumen($convocatoria)['matriculas'])->keyBy('matricula_id')
            : null;

        return [
            'convocatoria' => [
                'identificador' => $convocatoria->identificador,
                'codigo' => $convocatoria->codigo,
                'curso' => $convocatoria->curso?->nombre,
                'estado' => $convocatoria->estado,
                'revision' => $convocatoria->etag(),
            ],
            'nota_aprobatoria' => Calificacion::NOTA_APROBATORIA,
            'peso_total' => number_format($total / 100, 2, '.', ''),
            'asistencia_visible' => $asistencia !== null,
            'evaluaciones' => array_values($evaluaciones->map(fn (Evaluacion $e): array => [
                'identificador' => $e->identificador,
                'nombre' => $e->nombre,
                'peso' => number_format((float) $e->peso, 2, '.', ''),
                'fecha' => $e->fecha?->toDateString(),
                'con_notas' => $conNotas->has($e->id),
            ])->all()),
            'matriculas' => array_values($matriculas->map(fn (Matricula $m): array => [
                'id' => $m->id,
                'estudiante' => ['dni' => $m->estudiante?->dni, 'nombre' => trim(($m->estudiante->nombres ?? '').' '.($m->estudiante->apellidos ?? ''))],
                'notas' => ($notas[$m->id] ?? collect())->mapWithKeys(
                    fn (Calificacion $c): array => [Entrada::texto($identificadores[$c->evaluacion_id] ?? '') => number_format((float) $c->nota, 2, '.', '')],
                )->all(),
                'nota_final' => $m->nota_final === null ? null : number_format((float) $m->nota_final, 2, '.', ''),
                'resultado' => $m->resultado,
                'asistencia' => $asistencia?->get($m->id)['porcentaje'] ?? null,
            ])->all()),
        ];
    }
}
