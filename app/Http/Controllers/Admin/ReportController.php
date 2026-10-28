<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportMatriculasRequest;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\MatriculasExcelExport;
use App\Support\TemporaryExportFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReportController extends Controller implements HasMiddleware
{
    public const PDF_BATCH_SIZE = 50;

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
        $matriculas = $this->batch($query, $request);
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
                'ingresos' => (clone $query)->where('estado', 'pagada')->sum('monto_pagado'),
            ],
        ]);
    }

    public function exportarExcel(ReportMatriculasRequest $request): BinaryFileResponse
    {
        return $this->downloadTemporaryExport(
            'xlsx',
            'report_excel_exported',
            fn (string $file) => app(MatriculasExcelExport::class)->write($this->filteredQuery($request), $file),
        );
    }

    public function exportarPdf(ReportMatriculasRequest $request): BinaryFileResponse
    {
        $matriculas = $this->batch($this->filteredQuery($request), $request);

        $pdf = Pdf::loadView('admin.reportes.pdf', [
            'matriculas' => $matriculas,
            'fecha' => now()->format('d/m/Y'),
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
        );
    }

    /** @return Builder<Matricula> */
    private function filteredQuery(ReportMatriculasRequest $request): Builder
    {
        // No hidratar observaciones ni datos personales que el reporte no utiliza.
        $query = Matricula::query()
            ->select(['id', 'estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado'])
            ->with(['estudiante:id,nombres,apellidos', 'curso:id,codigo,nombre']);
        $filtros = $request->filters();

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }
        if (isset($filtros['estado'])) {
            $query->where('estado', $filtros['estado']);
        }
        if (isset($filtros['curso_id'])) {
            $query->where('curso_id', $filtros['curso_id']);
        }
        if (isset($filtros['busqueda'])) {
            $busqueda = '%'.$filtros['busqueda'].'%';
            $query->where(function (Builder $query) use ($busqueda): void {
                $query->whereHas('estudiante', function (Builder $estudiante) use ($busqueda): void {
                    $estudiante->whereLike('dni', $busqueda)
                        ->orWhereLike('nombres', $busqueda)->orWhereLike('apellidos', $busqueda);
                })->orWhereHas('curso', function (Builder $curso) use ($busqueda): void {
                    $curso->whereLike('codigo', $busqueda)->orWhereLike('nombre', $busqueda);
                });
            });
        }

        return $query;
    }

    /** @return LengthAwarePaginator<int, Matricula> */
    private function batch(Builder $query, ReportMatriculasRequest $request): LengthAwarePaginator
    {
        $matriculas = (clone $query)->orderByDesc('fecha_matricula')->orderByDesc('id')
            ->paginate(self::PDF_BATCH_SIZE, ['*'], 'lote', (int) ($request->validated('lote') ?? 1))
            ->appends($request->filters());

        abort_if($matriculas->currentPage() > $matriculas->lastPage(), 404, 'El lote solicitado no existe. Vuelve a aplicar los filtros.');

        return $matriculas;
    }

    /**
     * Escribe el reporte en un temporal privado de nombre único, audita y lo entrega para descarga.
     *
     * El nombre interno es un UUID para que dos exportaciones simultáneas no compartan archivo.
     * Si la escritura o la auditoría fallan, el temporal (con datos personales) se borra antes
     * de propagar el error; en el camino feliz lo elimina deleteFileAfterSend().
     *
     * @param  callable(string): mixed  $write
     */
    private function downloadTemporaryExport(string $extension, string $auditAction, callable $write): BinaryFileResponse
    {
        $filename = 'reporte-matriculas-'.now()->format('Ymd-His').'.'.$extension;
        $disk = Storage::disk('local');
        if (! $disk->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }

        $file = $disk->path('exports/'.Str::uuid()->toString().'.'.$extension);
        $temporary = new TemporaryExportFile($file, false);

        try {
            $write($file);

            clearstatcache(true, $file);
            if (! is_file($file) || filesize($file) === 0) {
                throw new RuntimeException('No se pudo completar el archivo de exportación.');
            }

            AuditLog::record($auditAction, Matricula::class, null, ['filename' => $filename]);

            return response()->download($temporary, $filename)->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($file)) {
                unlink($file);
            }

            throw $error;
        }
    }
}
