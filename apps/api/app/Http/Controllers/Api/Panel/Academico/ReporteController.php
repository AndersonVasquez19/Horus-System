<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportMatriculasRequest;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\MatriculasExcelExport;
use App\Services\ReportesMatriculas;
use App\Support\HoraLocal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reporte de matrículas del panel académico (único: el reporte Blade se retiró el 14/11/2026). Consulta, lotes,
 * exportaciones y auditoría viven en {@see ReportesMatriculas}; los permisos son los de siempre: `reportes.ver`,
 * `reportes.exportar.excel` y `reportes.exportar.pdf`. Los ingresos agregados solo se entregan al rol `admin`
 * (mismo criterio que el dashboard).
 */
final class ReporteController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ReportesMatriculas $reportes) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reportes.ver', only: ['index']),
            new Middleware('permission:reportes.exportar.excel', only: ['exportarExcel']),
            new Middleware('permission:reportes.exportar.pdf', only: ['exportarPdf']),
        ];
    }

    public function index(ReportMatriculasRequest $request): JsonResponse
    {
        $consulta = $this->reportes->consulta($request);
        $resumen = $this->reportes->resumen($consulta);
        $lote = $this->reportes->lote($consulta, $request, (int) $resumen->total);

        $totales = [
            'estudiantes' => Estudiante::count(),
            'cursos' => Curso::count(),
            'matriculas' => $lote->total(),
        ];
        if ($request->user()?->hasRole('admin') === true) {
            $totales['ingresos'] = number_format((float) $resumen->ingresos, 2, '.', '');
        }

        return response()->json([
            'data' => $lote->getCollection()->map(fn (Matricula $matricula): array => [
                'id' => $matricula->id,
                'estudiante' => $matricula->estudiante === null ? null : trim($matricula->estudiante->nombres.' '.$matricula->estudiante->apellidos),
                'curso' => $matricula->curso === null ? null : ['codigo' => $matricula->curso->codigo, 'nombre' => $matricula->curso->nombre],
                'fecha' => $matricula->fecha_matricula->toDateString(),
                'estado' => $matricula->estado,
                'monto_pagado' => $matricula->monto_pagado === null ? null : number_format((float) $matricula->monto_pagado, 2, '.', ''),
            ])->values()->all(),
            'resumen' => $totales,
            'meta' => [
                'lote' => $lote->currentPage(),
                'lotes' => $lote->lastPage(),
                'tamano_lote' => ReportesMatriculas::TAMANO_LOTE,
                'total' => $lote->total(),
                'filtros' => $request->filters(),
            ],
        ]);
    }

    public function exportarExcel(ReportMatriculasRequest $request): BinaryFileResponse
    {
        return $this->reportes->descargaTemporal(
            'xlsx',
            'report_excel_exported',
            fn (string $archivo) => app(MatriculasExcelExport::class)->write($this->reportes->consulta($request), $archivo),
            $this->reportes->filtrosAuditados($request),
        );
    }

    public function exportarPdf(ReportMatriculasRequest $request): BinaryFileResponse
    {
        $matriculas = $this->reportes->lote($this->reportes->consulta($request), $request);

        // La vista solo es la plantilla del PDF; la interfaz del panel es la de apps/web.
        $pdf = Pdf::loadView('pdf.reporte-matriculas', [
            'matriculas' => $matriculas,
            'fecha' => HoraLocal::ahora()->format('d/m/Y'),
            'filtros' => $request->filters(),
            'limitePdf' => ReportesMatriculas::TAMANO_LOTE,
            'cursoFiltro' => $request->filled('curso_id')
                ? Curso::withTrashed()->find($request->validated('curso_id'), ['id', 'codigo', 'nombre'])
                : null,
        ]);

        return $this->reportes->descargaTemporal(
            'pdf',
            'report_pdf_exported',
            fn (string $archivo) => $pdf->save($archivo),
            $this->reportes->filtrosAuditados($request, $matriculas->currentPage()),
        );
    }
}
