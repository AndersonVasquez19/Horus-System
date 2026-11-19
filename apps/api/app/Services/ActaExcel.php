<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeSpreadsheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Acta de una convocatoria cerrada en XLSX: una fila por matrícula con sus notas, nota final y resultado. Las celdas se
 * escriben como texto o número literal ({@see SafeSpreadsheet}), así que ningún nombre se interpreta como fórmula.
 */
final class ActaExcel
{
    /**
     * @param  array<string, mixed>  $acta  la matriz de notas que arma `CalificacionController` para la pantalla
     */
    public function write(array $acta, string $archivo, bool $borrador = false): void
    {
        $libro = new Spreadsheet;
        try {
            $sheet = $libro->getActiveSheet();
            $sheet->setTitle($borrador ? 'BORRADOR' : 'Acta');
            SafeSpreadsheet::writeRow($sheet, 1, ['Convocatoria', $acta['convocatoria']['codigo']]);
            if ($borrador) {
                // Documento no oficial: el acta solo vale tras el cierre de la convocatoria.
                SafeSpreadsheet::writeRow($sheet, 3, ['BORRADOR - NO OFICIAL: notas y resultados provisionales, sujetos a cambio hasta el cierre del acta.']);
            }
            SafeSpreadsheet::writeRow($sheet, 2, ['Curso', $acta['convocatoria']['curso'] ?? '']);

            $cabecera = ['DNI', 'Estudiante'];
            foreach ($acta['evaluaciones'] as $evaluacion) {
                $cabecera[] = $evaluacion['nombre'].' ('.$evaluacion['peso'].'%)';
            }
            array_push($cabecera, 'Nota final', 'Resultado');
            SafeSpreadsheet::writeRow($sheet, 4, $cabecera);

            $fila = 5;
            foreach ($acta['matriculas'] as $matricula) {
                $celdas = [$matricula['estudiante']['dni'], $matricula['estudiante']['nombre']];
                foreach ($acta['evaluaciones'] as $evaluacion) {
                    $celdas[] = $matricula['notas'][$evaluacion['identificador']] ?? '';
                }
                array_push($celdas, $matricula['nota_final'] ?? '', $borrador ? 'PROVISIONAL' : ($matricula['resultado'] ?? ''));
                SafeSpreadsheet::writeRow($sheet, $fila++, $celdas);
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
