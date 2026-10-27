<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $sheet->fromArray([
            ['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto'],
        ], null, 'A1');

        $fila = 2;
        foreach ($matriculas as $matricula) {
            $sheet->fromArray([
                [
                    $matricula->id,
                    $matricula->estudiante?->nombres.' '.$matricula->estudiante?->apellidos,
                    $matricula->curso?->nombre,
                    $matricula->fecha_matricula?->format('d/m/Y'),
                    $matricula->estado,
                    (float) $matricula->monto_pagado,
                ],
            ], null, 'A'.$fila);

            $fila++;
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'reporte-matriculas-'.now()->format('Ymd-His').'.xlsx';
        $path = 'exports/'.$filename;
        Storage::disk('local')->makeDirectory('exports');
        $file = Storage::disk('local')->path($path);
        $writer->save($file);

        AuditLog::record('report_excel_exported', Matricula::class, null, ['filename' => $filename]);

        return response()->download($file, $filename)->deleteFileAfterSend(true);
    }

    public function exportarPdf(): BinaryFileResponse
    {
        $matriculas = Matricula::query()->with(['estudiante', 'curso'])->orderByDesc('fecha_matricula')->limit(50)->get();

        $pdf = Pdf::loadView('admin.reportes.pdf', [
            'matriculas' => $matriculas,
            'fecha' => now()->format('d/m/Y'),
        ]);
        $filename = 'reporte-matriculas-'.now()->format('Ymd-His').'.pdf';
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir.DIRECTORY_SEPARATOR.$filename;
        $pdf->save($file);

        AuditLog::record('report_pdf_exported', Matricula::class, null, ['filename' => $filename]);

        return response()->download($file, $filename)->deleteFileAfterSend(true);
    }
}
