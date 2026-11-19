<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use stdClass;
use Throwable;
use ZipArchive;

/**
 * Generación de la exportación ADMINISTRATIVA (ZIP con un CSV por tabla y `manifest.json`), compartida por el módulo Blade
 * y por la API del panel. No es una copia de seguridad restaurable. Minimización: omite columnas sensibles, enmascara
 * ajustes secretos y cambios de auditoría, protege contra inyección de fórmulas y no conserva el ZIP.
 *
 * Los métodos `newTemporaryFile()` y `newArchive()` son puntos de extensión para simular fallos en pruebas.
 */
trait GeneraExportacionAdministrativa
{
    /** @return list<string> */
    abstract protected function tablasExportables(): array;

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
        // ZipArchive lee los archivos añadidos con addFile() al cerrar: cada CSV temporal debe existir hasta
        // entonces y se elimina siempre después (éxito o fallo).
        $temporales = [];

        try {
            $incluidas = [];
            $omitidas = [];

            foreach ($this->tablasExportables() as $table) {
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

                $csv = $this->tableToCsvFile($table, $columns);
                $temporales[] = $csv;
                if (! $zip->addFile($csv, $table.'.csv')) {
                    throw new RuntimeException("No se pudo añadir {$table}.csv al archivo ZIP.");
                }
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

            foreach ($temporales as $temporal) {
                @unlink($temporal);
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
    private function tableToCsvFile(string $table, array $columns): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'horus_csv_');
        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del CSV de '.$table.'.');
        }

        $handle = fopen($ruta, 'w');
        if ($handle === false) {
            @unlink($ruta);
            throw new RuntimeException('No se pudo abrir el archivo temporal del CSV de '.$table.'.');
        }

        try {
            fputcsv($handle, $columns);

            if ($columns !== []) {
                foreach (DB::table($table)->select($columns)->cursor() as $row) {
                    fputcsv($handle, $this->csvRow($table, $columns, $row));
                }
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($ruta);
            throw $e;
        }

        if (! fclose($handle)) {
            @unlink($ruta);
            throw new RuntimeException('No se pudo finalizar el CSV temporal de '.$table.'.');
        }

        return $ruta;
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
                ? (string) json_encode(AuditLog::redactarCambios($decoded), JSON_UNESCAPED_UNICODE)
                : SensitiveData::MASK;
        }

        return array_map(
            static fn (string $column): string => SensitiveData::csvSafe($values[$column] ?? null),
            $columns,
        );
    }
}
