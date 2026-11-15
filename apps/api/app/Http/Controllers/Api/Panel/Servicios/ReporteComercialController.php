<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReporteComercialRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ReporteComercialExcel;
use App\Services\ReportesComerciales;
use App\Support\TemporaryExportFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Reportes comerciales del panel de servicios. Conserva la restricción comercial vigente: rol `admin` y permisos
 * `reportes-comerciales.*` (docs/restriccion-acceso-comercial-admin.md). Pantalla y exportación salen de
 * {@see ReportesComerciales}; cada exportación deja constancia de acceso (quién, cuándo, periodo y filtro) sin cifras.
 * Toda respuesta va con `Cache-Control: no-store, private`.
 */
final class ReporteComercialController extends Controller implements HasMiddleware
{
    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly ReportesComerciales $reportes) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
            new Middleware('permission:reportes-comerciales.ver', only: ['index', 'opcionesResponsables']),
            new Middleware('permission:reportes-comerciales.exportar', only: ['exportar']),
        ];
    }

    public function index(ReporteComercialRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $periodo = $request->periodo();

        return response()->json([
            'data' => $this->reportes->generar($usuario, $periodo['desde'], $periodo['hasta'], $request->responsable()),
            'meta' => ['filtros' => ['responsable_id' => $request->responsable()], 'max_dias' => ReportesComerciales::MAX_DIAS],
        ], 200, self::CABECERAS);
    }

    public function exportar(ReporteComercialRequest $request): BinaryFileResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $periodo = $request->periodo();
        $reporte = $this->reportes->generar($usuario, $periodo['desde'], $periodo['hasta'], $request->responsable());

        $nombre = 'reporte-comercial-'.now()->format('Ymd-His').'.xlsx';
        $disco = Storage::disk('local');
        if (! $disco->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }
        $archivo = $disco->path('exports/'.Str::uuid()->toString().'.xlsx');
        $temporal = new TemporaryExportFile($archivo, false);

        try {
            app(ReporteComercialExcel::class)->write($reporte, $archivo);
            clearstatcache(true, $archivo);
            if (! is_file($archivo) || filesize($archivo) === 0) {
                throw new RuntimeException('No se pudo completar el archivo de exportación.');
            }

            // Constancia de acceso: quién exportó, qué periodo y filtro, sin las cifras.
            AuditLog::record('reporte_comercial_exportado', User::class, $usuario->id, [
                'filename' => $nombre,
                'desde' => $periodo['desde']->toDateString(),
                'hasta' => $periodo['hasta']->toDateString(),
                'responsable_id' => $request->responsable(),
                'bloques' => array_keys($reporte['bloques']),
            ], $usuario->id);

            return response()->download($temporal, $nombre, ['Cache-Control' => 'no-store, private'])->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($archivo)) {
                unlink($archivo);
            }

            throw $error;
        }
    }

    /** Administradores que se pueden elegir como filtro. */
    public function opcionesResponsables(): JsonResponse
    {
        $opciones = User::query()->role('admin')->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'texto' => $usuario->name])->values()->all();

        return response()->json(['data' => $opciones], 200, self::CABECERAS);
    }
}
