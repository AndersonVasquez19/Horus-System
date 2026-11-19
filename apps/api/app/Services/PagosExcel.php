<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeSpreadsheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Obligaciones de pago en XLSX: una fila por obligación con total, pagado, saldo y estado de cobro (PEN). Sin referencias ni
 * claves. Celdas como texto o número literal ({@see SafeSpreadsheet}): ningún nombre ni concepto se interpreta como fórmula.
 */
final class PagosExcel
{
    /** @param list<array<string, mixed>> $filas resúmenes de obligación */
    public function write(array $filas, string $archivo): void
    {
        $libro = new Spreadsheet;
        try {
            $sheet = $libro->getActiveSheet();
            $sheet->setTitle('Pagos');
            SafeSpreadsheet::writeRow($sheet, 1, ['DNI', 'Estudiante', 'Edición', 'Concepto', 'Plan', 'Total (PEN)', 'Pagado (PEN)', 'Por confirmar (PEN)', 'Saldo (PEN)', 'Estado']);
            $n = 2;
            foreach ($filas as $f) {
                SafeSpreadsheet::writeRow($sheet, $n++, [
                    $f['estudiante']['dni'], $f['estudiante']['nombre'], $f['edicion'] ?? '', $f['concepto'], $f['plan'],
                    $f['monto_total'], $f['pagado'], $f['pendiente_confirmar'], $f['saldo'], $f['estado'],
                ]);
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
