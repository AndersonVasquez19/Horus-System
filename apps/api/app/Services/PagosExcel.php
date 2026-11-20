<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Entrada;
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
                $estudiante = Entrada::lista($f['estudiante']);
                SafeSpreadsheet::writeRow($sheet, $n++, [
                    Entrada::celda($estudiante['dni'] ?? ''), Entrada::celda($estudiante['nombre'] ?? ''), Entrada::celda($f['edicion'] ?? ''), Entrada::celda($f['concepto']), Entrada::celda($f['plan']),
                    Entrada::celda($f['monto_total']), Entrada::celda($f['pagado']), Entrada::celda($f['pendiente_confirmar']), Entrada::celda($f['saldo']), Entrada::celda($f['estado']),
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
