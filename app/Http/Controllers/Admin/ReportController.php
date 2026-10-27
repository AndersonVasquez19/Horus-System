<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Support\SafeSpreadsheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReportController extends Controller implements HasMiddleware
{
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

    public function index(): View
    {
        return view('admin.reportes.index', [
            'resumen' => [
                'estudiantes' => Estudiante::count(),
                'cursos' => Curso::count(),
                'matriculas' => Matricula::count(),
                'ingresos' => Matricula::where('estado', 'pagada')->sum('monto_pagado'),
            ],
        ]);
    }

    public function exportarExcel(): BinaryFileResponse
    {
        $matriculas = Matricula::query()->with(['estudiante', 'curso'])->orderByDesc('fecha_matricula')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Matrículas');
        SafeSpreadsheet::writeRow($sheet, 1, ['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto']);

        // Nombres y cursos provienen de formularios: se escriben como texto literal, nunca como fórmula.
        $fila = 2;
        foreach ($matriculas as $matricula) {
            SafeSpreadsheet::writeRow($sheet, $fila, [
                $matricula->id,
                trim(($matricula->estudiante?->nombres ?? '').' '.($matricula->estudiante?->apellidos ?? '')),
                $matricula->curso?->nombre,
                $matricula->fecha_matricula?->format('d/m/Y'),
                $matricula->estado,
                (float) $matricula->monto_pagado,
            ]);

            $fila++;
        }

        return $this->downloadTemporaryExport(
            'xlsx',
            'report_excel_exported',
            fn (string $file) => (new Xlsx($spreadsheet))->save($file),
        );
    }

    public function exportarPdf(): BinaryFileResponse
    {
        $matriculas = Matricula::query()->with(['estudiante', 'curso'])->orderByDesc('fecha_matricula')->limit(50)->get();

        $pdf = Pdf::loadView('admin.reportes.pdf', [
            'matriculas' => $matriculas,
            'fecha' => now()->format('d/m/Y'),
        ]);

        return $this->downloadTemporaryExport(
            'pdf',
            'report_pdf_exported',
            fn (string $file) => $pdf->save($file),
        );
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
        $disk->makeDirectory('exports');

        $file = $disk->path('exports/'.Str::uuid()->toString().'.'.$extension);

        try {
            $write($file);

            AuditLog::record($auditAction, Matricula::class, null, ['filename' => $filename]);

            return response()->download($file, $filename)->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($file)) {
                unlink($file);
            }

            throw $error;
        }
    }
}
