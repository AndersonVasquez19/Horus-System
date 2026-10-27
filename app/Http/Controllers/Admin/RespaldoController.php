<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use ZipArchive;

class RespaldoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'permission:respaldo.ver'])->only(['index']);
        $this->middleware(['auth', 'permission:respaldo.exportar'])->only(['exportar']);
    }

    public function index(): View
    {
        $archivos = collect(Storage::disk('local')->files('exports'))
            ->sortDesc()
            ->take(10)
            ->map(function (string $path): array {
                return [
                    'nombre' => basename($path),
                    'size' => Storage::disk('local')->size($path),
                    'fecha' => Storage::disk('local')->lastModified($path),
                ];
            });

        return view('admin.respaldo.index', [
            'archivos' => $archivos,
        ]);
    }

    public function exportar()
    {
        if (! class_exists(\ZipArchive::class)) {
            return back()->with('error', 'La extensión ZIP no está disponible en este servidor.');
        }

        $tables = [
            'users',
            'estudiantes',
            'cursos',
            'matriculas',
            'procesos_internos',
            'notificaciones_internas',
            'audit_logs',
            'system_settings',
            'aprobaciones_internas',
        ];

        $zipPath = storage_path('app/exports/respaldo_'.now()->format('Ymd_His').'.zip');
        $dir = dirname($zipPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            return back()->with('error', 'No se pudo crear el archivo de respaldo.');
        }

        foreach ($tables as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $filename = $table.'.csv';
            $handle = fopen('php://temp', 'r+');
            $rows = DB::table($table)->get()->toArray();

            if ($rows === []) {
                fputcsv($handle, []);
            } else {
                $columns = array_keys((array) $rows[0]);
                fputcsv($handle, $columns);
                foreach ($rows as $row) {
                    $values = array_map(static fn ($value): string => (string) ($value ?? ''), (array) $row);
                    fputcsv($handle, $values);
                }
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            fclose($handle);
            $zip->addFromString($filename, $contents);
        }

        $manifest = [
            'generado_en' => now()->toIso8601String(),
            'tablas' => $tables,
            'descripcion' => 'Copia de seguridad del sistema Horus Group SRL',
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        return response()->download($zipPath, 'respaldo_'.now()->format('Ymd_His').'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }
}
