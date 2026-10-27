<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\SensitiveData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * Exportación ADMINISTRATIVA de tablas seleccionadas (CSV dentro de un ZIP).
 *
 * No es una copia de seguridad: no incluye todas las tablas, ni el esquema, ni los archivos,
 * ni se puede restaurar con ella la base de datos. Un respaldo integral y restaurable se
 * obtiene con `pg_dump` en el servidor (ver docs/seguridad-l1.1.md).
 *
 * Minimización: se omiten columnas de autenticación (contraseñas, tokens, versión JWT…), se
 * enmascaran los ajustes secretos y los cambios de auditoría, y el ZIP no se conserva en el
 * servidor: se genera como temporal, se entrega y se elimina.
 */
class RespaldoController extends Controller implements HasMiddleware
{
    /** Tablas incluidas en la exportación administrativa. */
    private const TABLES = [
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

    /** Directorio (en el disco `local`) donde antes se dejaban copias; solo se lista para revisión. */
    private const LEGACY_DIRECTORY = 'exports';

    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:respaldo.ver', only: ['index']),
            new Middleware('permission:respaldo.exportar', only: ['exportar']),
        ];
    }

    public function index(): View
    {
        $disk = Storage::disk('local');

        $archivos = collect($disk->files(self::LEGACY_DIRECTORY))
            ->map(fn (string $path): array => [
                'nombre' => basename($path),
                'size' => $disk->size($path),
                'fecha' => $disk->lastModified($path),
            ])
            ->sortByDesc('fecha')
            ->take(10)
            ->values();

        return view('admin.respaldo.index', [
            'archivos' => $archivos,
        ]);
    }

    public function exportar(): RedirectResponse|BinaryFileResponse
    {
        if (! class_exists(ZipArchive::class)) {
            return back()->with('error', 'La extensión ZIP no está disponible en este servidor.');
        }

        $nombre = 'exportacion_'.now()->format('Ymd_His').'.zip';
        $zipPath = $this->newTemporaryFile();

        if ($zipPath === false) {
            return back()->with('error', 'No se pudo crear el archivo de exportación.');
        }

        // Solo se conserva el temporal si la respuesta de descarga llegó a construirse; en cualquier
        // otro caso (excepción, ZIP incompleto, fallo de auditoría) el `finally` elimina ESTE archivo.
        $entregable = false;

        try {
            $incluidas = $this->buildArchive($zipPath);

            // La exportación no se entrega si no ha podido quedar registrada en auditoría.
            AuditLog::record('data_export_generated', null, null, [
                'filename' => $nombre,
                'tables' => array_keys($incluidas),
            ]);

            $respuesta = response()
                ->download($zipPath, $nombre, ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend(true);
            $entregable = true;

            return $respuesta;
        } catch (Throwable $e) {
            // Se informa al manejador de excepciones (registro); no se oculta el fallo ni se entrega nada.
            report($e);

            return back()->with('error', 'No se pudo generar la exportación. No se descargó ningún archivo.');
        } finally {
            if (! $entregable) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * Crea el archivo temporal que alojará el ZIP. Existe como punto de extensión para que las pruebas
     * conozcan la ruta exacta (en Windows `tempnam()` recorta el prefijo, no se puede buscar por nombre).
     */
    protected function newTemporaryFile(): string|false
    {
        return tempnam(sys_get_temp_dir(), 'horus_exp_');
    }

    /**
     * Crea un ZIP nuevo (punto de extensión para simular fallos de ZipArchive en pruebas).
     */
    protected function newArchive(): ZipArchive
    {
        return new ZipArchive;
    }

    /**
     * Escribe el ZIP completo en `$zipPath`. Lanza una excepción ante cualquier fallo, de modo que
     * nunca se devuelve como válido un archivo incompleto.
     *
     * @return array<string, list<string>> Columnas incluidas por tabla.
     */
    private function buildArchive(string $zipPath): array
    {
        $zip = $this->newArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo temporal de la exportación.');
        }

        $abierto = true;

        try {
            $incluidas = [];
            $omitidas = [];

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $columns = [];
                foreach (Schema::getColumnListing($table) as $column) {
                    if (SensitiveData::isSensitiveKey($column)) {
                        $omitidas[$table][] = $column;
                    } else {
                        $columns[] = $column;
                    }
                }

                $this->addEntry($zip, $table.'.csv', $this->tableToCsv($table, $columns));
                $incluidas[$table] = $columns;
            }

            $this->addEntry($zip, 'manifest.json', (string) json_encode([
                'generado_en' => now()->toIso8601String(),
                'tipo' => 'exportacion_administrativa',
                'es_respaldo_restaurable' => false,
                'descripcion' => 'Exportación minimizada de tablas seleccionadas de Horus Group SRL. '
                    .'No es una copia de seguridad completa de PostgreSQL ni permite restaurar el sistema.',
                'tablas' => array_keys($incluidas),
                'columnas_omitidas_por_seguridad' => $omitidas,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // `close()` escribe el archivo en disco; PHP descarta el manejador aunque falle.
            $abierto = false;
            if (! $zip->close()) {
                throw new RuntimeException('No se pudo finalizar el archivo ZIP de la exportación.');
            }

            return $incluidas;
        } finally {
            if ($abierto) {
                // Fallo a mitad de la generación: se descartan las entradas pendientes y se libera
                // el manejador (en Windows impediría borrar el temporal).
                $zip->unchangeAll();
                @$zip->close();
            }
        }
    }

    private function addEntry(ZipArchive $zip, string $name, string $contents): void
    {
        if (! $zip->addFromString($name, $contents)) {
            throw new RuntimeException("No se pudo añadir {$name} al archivo ZIP.");
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function tableToCsv(string $table, array $columns): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);

        if ($columns !== []) {
            foreach (DB::table($table)->select($columns)->cursor() as $row) {
                fputcsv($handle, $this->csvRow($table, $columns, $row));
            }
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function csvRow(string $table, array $columns, stdClass $row): array
    {
        $values = (array) $row;

        // Ajustes secretos que pudieran existir en la tabla (p. ej. la antigua `api_resend`).
        if ($table === 'system_settings' && SensitiveData::isSensitiveKey((string) ($values['key'] ?? ''))) {
            $values['value'] = SensitiveData::MASK;
        }

        // Los registros de auditoría históricos podrían contener secretos en `changes`.
        if ($table === 'audit_logs' && isset($values['changes']) && is_string($values['changes'])) {
            $decoded = json_decode($values['changes'], true);
            $values['changes'] = is_array($decoded)
                ? (string) json_encode(SensitiveData::redact($decoded), JSON_UNESCAPED_UNICODE)
                : SensitiveData::MASK;
        }

        return array_map(
            static fn (string $column): string => SensitiveData::csvSafe($values[$column] ?? null),
            $columns,
        );
    }
}
