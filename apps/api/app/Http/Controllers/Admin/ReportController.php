<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportMatriculasRequest;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\MatriculasExcelExport;
use App\Services\ReportesMatriculas;
use App\Support\HoraLocal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller implements HasMiddleware
{
    public const PDF_BATCH_SIZE = ReportesMatriculas::TAMANO_LOTE;

    public function __construct(private readonly ReportesMatriculas $reportes) {}

    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reportes.ver', only: ['index']),
            new Middleware('permission:reportes.exportar.excel', only: ['exportarExcel']),
            new Middleware('permission:reportes.exportar.pdf', only: ['exportarPdf']),
        ];
    }

    public function index(ReportMatriculasRequest $request): View
    {
        $query = $this->filteredQuery($request);
        // El mismo agregado filtrado alimenta el resumen y el total del paginador.
        $resumen = $this->reportes->resumen($query);
        $matriculas = $this->batch($query, $request, (int) $resumen->total);
        $cursos = Curso::withTrashed()->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'deleted_at']);

        return view('admin.reportes.index', [
            'matriculas' => $matriculas,
            'filtros' => $request->filters(),
            'limitePdf' => self::PDF_BATCH_SIZE,
            'cursos' => $cursos,
            'cursoFiltro' => $cursos->firstWhere('id', $request->validated('curso_id')),
            'resumen' => [
                'estudiantes' => Estudiante::count(),
                'cursos' => Curso::count(),
                'matriculas' => $matriculas->total(),
                'ingresos' => $resumen->ingresos,
            ],
        ]);
    }

    public function exportarExcel(ReportMatriculasRequest $request): BinaryFileResponse
    {
        return $this->downloadTemporaryExport(
            'xlsx',
            'report_excel_exported',
            fn (string $file) => app(MatriculasExcelExport::class)->write($this->filteredQuery($request), $file),
            $this->filtrosAuditados($request),
        );
    }

    public function exportarPdf(ReportMatriculasRequest $request): BinaryFileResponse
    {
        $matriculas = $this->batch($this->filteredQuery($request), $request);

        $pdf = Pdf::loadView('admin.reportes.pdf', [
            'matriculas' => $matriculas,
            'fecha' => HoraLocal::ahora()->format('d/m/Y'),
            'filtros' => $request->filters(),
            'limitePdf' => self::PDF_BATCH_SIZE,
            'cursoFiltro' => $request->filled('curso_id')
                ? Curso::withTrashed()->find($request->validated('curso_id'), ['id', 'codigo', 'nombre'])
                : null,
        ]);

        return $this->downloadTemporaryExport(
            'pdf',
            'report_pdf_exported',
            fn (string $file) => $pdf->save($file),
            $this->filtrosAuditados($request, (int) $matriculas->currentPage()),
        );
    }

    /** @return Builder<Matricula> */
    private function filteredQuery(ReportMatriculasRequest $request): Builder
    {
        return $this->reportes->consulta($request);
    }

    /**
     * @param  Builder<Matricula>  $query
     * @return LengthAwarePaginator<int, Matricula>
     */
    private function batch(Builder $query, ReportMatriculasRequest $request, ?int $total = null): LengthAwarePaginator
    {
        return $this->reportes->lote($query, $request, $total);
    }

    /**
     * @param  callable(string): mixed  $write
     * @param  array<string, mixed>  $filtros
     */
    private function downloadTemporaryExport(string $extension, string $auditAction, callable $write, array $filtros = []): BinaryFileResponse
    {
        return $this->reportes->descargaTemporal($extension, $auditAction, $write, $filtros);
    }

    /** @return array{estado: string|null, curso_id: int|null, eliminados: bool, con_busqueda: bool, lote?: int} */
    private function filtrosAuditados(ReportMatriculasRequest $request, ?int $lote = null): array
    {
        return $this->reportes->filtrosAuditados($request, $lote);
    }
}
