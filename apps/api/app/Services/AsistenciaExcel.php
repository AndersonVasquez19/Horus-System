<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeSpreadsheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Asistencia de una edición en XLSX: una fila por matrícula, una columna por sesión (P presente, A ausente, T tardanza,
 * J justificada) y el porcentaje. Celdas como texto o número literal ({@see SafeSpreadsheet}): ningún nombre se interpreta como fórmula.
 */
final class AsistenciaExcel
{
    /**
     * @param  array{sesiones: list<array{numero: int, fecha: string}>, filas: list<array{dni: string, nombre: string, estados: list<string>, porcentaje: ?float}>}  $matriz
     */
    public function write(string $codigo, string $curso, array $matriz, string $archivo): void
    {
        $libro = new Spreadsheet;
        try {
            $sheet = $libro->getActiveSheet();
            $sheet->setTitle('Asistencia');
            SafeSpreadsheet::writeRow($sheet, 1, ['Edición', $codigo]);
            SafeSpreadsheet::writeRow($sheet, 2, ['Curso', $curso]);

            $cabecera = ['DNI', 'Estudiante'];
            foreach ($matriz['sesiones'] as $s) {
                $cabecera[] = 'S'.$s['numero'].' '.$s['fecha'];
            }
            $cabecera[] = '% asistencia';
            SafeSpreadsheet::writeRow($sheet, 4, $cabecera);

            $fila = 5;
            foreach ($matriz['filas'] as $f) {
                SafeSpreadsheet::writeRow($sheet, $fila++, [$f['dni'], $f['nombre'], ...$f['estados'], $f['porcentaje'] ?? '']);
            }

            $writer = new Xlsx($libro);
            $writer->setPreCalculateFormulas(false);
            $writer->save($archivo);

            $zip = new ZipArchive;
            if ($zip->open($archivo, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('No se pudo completar el archivo XLSX.');
            }
            $zip->close();
        } finally {
            $libro->disconnectWorksheets();
        }
    }
}
