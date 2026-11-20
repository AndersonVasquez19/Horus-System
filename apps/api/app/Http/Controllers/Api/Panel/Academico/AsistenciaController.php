<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\SesionClase;
use App\Models\User;
use App\Services\AsistenciaExcel;
use App\Services\GestionAsistencias;
use App\Support\TemporaryExportFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Asistencia (`asistencias.ver|registrar|corregir|exportar`). Toda escritura pasa por {@see GestionAsistencias}.
 */
final class AsistenciaController extends Controller implements HasMiddleware
{
    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly GestionAsistencias $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:asistencias.ver', only: ['lista', 'resumen']),
            new Middleware('permission:asistencias.registrar', only: ['registrar']),
            new Middleware('permission:asistencias.corregir', only: ['corregir']),
            new Middleware('permission:asistencias.exportar', only: ['exportar']),
        ];
    }

    public function lista(string $sesion): JsonResponse
    {
        $modelo = $this->sesion($sesion);

        return $this->json(['data' => ['sesion' => $this->cabecera($modelo), 'matriculas' => $this->gestion->lista($modelo)]]);
    }

    public function registrar(Request $request, string $sesion): JsonResponse
    {
        $datos = $request->validate([
            'asistencias' => ['required', 'array', 'min:1', 'max:'.GestionAsistencias::MAX_LOTE],
            'asistencias.*' => ['array:matricula_id,estado,observacion'],
            'asistencias.*.matricula_id' => ['required', 'integer', 'min:1'],
            'asistencias.*.estado' => ['required', 'string', Rule::in(Asistencia::ESTADOS)],
            'asistencias.*.observacion' => ['nullable', 'string', 'max:200'],
        ], [
            'asistencias.required' => 'Envía la asistencia de al menos una matrícula.',
            'asistencias.*.estado.in' => 'Estado de asistencia no válido.',
            'asistencias.*.observacion.max' => 'La observación admite hasta 200 caracteres.',
        ]);
        $r = $this->gestion->registrar($request, $this->sesion($sesion)->identificador, array_values($datos['asistencias']));

        return $this->json(['message' => $r['registradas'].' asistencia(s) registrada(s).', 'data' => $r]);
    }

    public function corregir(Request $request, string $sesion, int $matricula): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['required', 'string', Rule::in(Asistencia::ESTADOS)],
            'motivo' => ['required', 'string', 'max:200'],
        ], ['motivo.required' => 'Indica el motivo de la corrección.', 'motivo.max' => 'El motivo admite hasta 200 caracteres.']);
        $this->gestion->corregir($request, $this->sesion($sesion)->identificador, $matricula, $datos['estado'], trim($datos['motivo']));

        return $this->json(['message' => 'La asistencia se corrigió.', 'data' => ['matricula_id' => $matricula, 'estado' => $datos['estado']]]);
    }

    public function resumen(string $identificador): JsonResponse
    {
        return $this->json(['data' => $this->gestion->resumen($this->edicion($identificador))]);
    }

    /** Excel de la asistencia de la edición, con constancia de acceso (sin datos de personas). */
    public function exportar(Request $request, string $identificador): BinaryFileResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $convocatoria = $this->edicion($identificador);
        $matriz = $this->gestion->matriz($convocatoria);

        $nombre = 'asistencia-'.strtolower($convocatoria->codigo).'-'.now()->format('Ymd-His').'.xlsx';
        $disco = Storage::disk('local');
        if (! $disco->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }
        $archivo = $disco->path('exports/'.Str::uuid()->toString().'.xlsx');
        $temporal = new TemporaryExportFile($archivo, false);

        try {
            app(AsistenciaExcel::class)->write($convocatoria->codigo, (string) $convocatoria->curso?->nombre, $matriz, $archivo);
            clearstatcache(true, $archivo);
            if (! is_file($archivo) || filesize($archivo) === 0) {
                throw new RuntimeException('No se pudo completar el archivo de exportación.');
            }
            AuditLog::record('asistencia_exportada', Convocatoria::class, $convocatoria->id, ['filename' => $nombre, 'matriculas' => count($matriz['filas'])], $usuario->id);

            return response()->download($temporal, $nombre, self::CABECERAS)->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($archivo)) {
                unlink($archivo);
            }

            throw $error;
        }
    }

    private function edicion(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->with('curso')->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function sesion(string $identificador): SesionClase
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return SesionClase::query()->with('convocatoria')->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function cabecera(SesionClase $s): array
    {
        return [
            'identificador' => $s->identificador, 'edicion' => $s->convocatoria->identificador ?? '', 'numero' => $s->numero, 'fecha' => $s->fecha,
            'hora_inicio' => substr($s->hora_inicio, 0, 5), 'hora_fin' => substr($s->hora_fin, 0, 5), 'estado' => $s->estado,
        ];
    }

    /** @param array<string, mixed> $cuerpo */
    private function json(array $cuerpo): JsonResponse
    {
        return response()->json($cuerpo)->header('Cache-Control', 'no-store, private');
    }
}
