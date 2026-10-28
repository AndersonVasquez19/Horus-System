<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Matricula;
use App\Support\SafeSpreadsheet;
use App\Support\SpreadsheetCellCache;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

final class MatriculasExcelExport
{
    /** @param Builder<Matricula> $query */
    public function write(Builder $query, string $file): void
    {
        $this->writeRows($this->rows($query), $file);
    }

    /**
     * Paginación por la misma pareja de ordenación, sin OFFSET creciente ni cambiar el contrato.
     *
     * @param  Builder<Matricula>  $query
     * @return Generator<int, list<string|int|float|null>>
     */
    private function rows(Builder $query): Generator
    {
        $connection = $query->getConnection();
        $level = $connection->transactionLevel();
        $connection->beginTransaction();
        try {
            if ($connection instanceof Connection && $connection->getDriverName() === 'pgsql') {
                if ($level === 0) {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                } else {
                    $isolation = $connection->selectOne("SELECT current_setting('transaction_isolation') AS isolation");
                    if (! in_array($isolation->isolation, ['repeatable read', 'serializable'], true)) {
                        throw new RuntimeException('La exportación requiere una transacción con snapshot consistente.');
                    }
                }
            }
            $last = null;
            do {
                $batch = (clone $query)->reorder()->orderByDesc('fecha_matricula')->orderByDesc('id');
                if ($last !== null) {
                    $batch->where(function (Builder $before) use ($last): void {
                        $before->where('fecha_matricula', '<', $last->getRawOriginal('fecha_matricula'))
                            ->orWhere(function (Builder $tie) use ($last): void {
                                $tie->where('fecha_matricula', $last->getRawOriginal('fecha_matricula'))
                                    ->where('id', '<', $last->getKey());
                            });
                    });
                }
                $models = $batch->limit(500)->get();
                foreach ($models as $matricula) {
                    yield $this->row($matricula);
                }
                $fullBatch = $models->count() === 500;
                $last = $models->last();
                unset($models);
            } while ($fullBatch);
            // Libera el snapshot antes de construir/comprimir el ZIP.
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > $level) {
                $connection->rollBack();
            }
        }
    }

    /** @return list<string|int|float|null> */
    private function row(Matricula $matricula): array
    {
        // Relaciones ya precargadas; no iniciar consultas implícitas desde el escritor.
        $estudiante = $matricula->getRelation('estudiante');
        $curso = $matricula->getRelation('curso');

        return [
            $matricula->getAttribute('id'),
            trim(($estudiante->nombres ?? '').' '.($estudiante->apellidos ?? '')),
            $curso?->nombre,
            $matricula->getAttribute('fecha_matricula')?->format('d/m/Y'),
            $matricula->getAttribute('estado'),
            (float) $matricula->getAttribute('monto_pagado'),
        ];
    }

    /** @param iterable<list<string|int|float|null>> $rows */
    public function writeRows(iterable $rows, string $file): void
    {
        $previousCache = Settings::getCache();
        $cache = new SpreadsheetCellCache;
        $spreadsheet = null;
        try {
            Settings::setCache($cache);
            $spreadsheet = new Spreadsheet;
            // La hoja ya capturó su caché; restaura la configuración global inmediatamente.
            Settings::setCache($previousCache);
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Matrículas');
            SafeSpreadsheet::writeRow($sheet, 1, ['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto']);
            $rowNumber = 2;
            foreach ($rows as $row) {
                if ($rowNumber > 1048576) {
                    throw new RuntimeException('El reporte supera el límite de filas XLSX. Reduce los filtros.');
                }
                SafeSpreadsheet::writeRow($sheet, $rowNumber++, $row);
            }

            // El escritor aún retiene índices, cadenas únicas y XML: no es memoria constante.
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($file);

            // No entregar un ZIP truncado aunque la escritura haya retornado sin lanzar.
            $zip = new ZipArchive;
            if ($zip->open($file, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('No se pudo completar el archivo XLSX.');
            }
            try {
                if ($zip->locateName('xl/worksheets/sheet1.xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                    throw new RuntimeException('El archivo XLSX está incompleto.');
                }
            } finally {
                $zip->close();
            }
        } finally {
            Settings::setCache($previousCache);
            try {
                $spreadsheet?->disconnectWorksheets();
            } finally {
                $cache->close();
            }
        }
    }
}
