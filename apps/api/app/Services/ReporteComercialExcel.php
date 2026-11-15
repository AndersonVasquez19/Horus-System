<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SafeSpreadsheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Exporta el reporte comercial a XLSX en filas `Bloque | Indicador | Valor`, a partir del mismo arreglo que ve la pantalla
 * ({@see ReportesComerciales::generar()}). Las celdas se escriben como texto o número literal ({@see SafeSpreadsheet}).
 */
final class ReporteComercialExcel
{
    private const BLOQUES = ['prospectos' => 'Prospectos', 'oportunidades' => 'Oportunidades', 'soporte' => 'Soporte', 'proyectos' => 'Proyectos'];

    /** @param array<string, mixed> $reporte */
    public function write(array $reporte, string $archivo): void
    {
        $libro = new Spreadsheet;
        try {
            $sheet = $libro->getActiveSheet();
            $sheet->setTitle('Reporte comercial');
            SafeSpreadsheet::writeRow($sheet, 1, ['Bloque', 'Indicador', 'Valor']);
            $fila = 2;
            SafeSpreadsheet::writeRow($sheet, $fila++, ['Periodo', 'Desde', $reporte['periodo']['desde']]);
            SafeSpreadsheet::writeRow($sheet, $fila++, ['Periodo', 'Hasta', $reporte['periodo']['hasta']]);
            foreach ($reporte['bloques'] as $bloque => $datos) {
                foreach ($this->aplanar((array) $datos) as [$indicador, $valor]) {
                    SafeSpreadsheet::writeRow($sheet, $fila++, [self::BLOQUES[$bloque] ?? $bloque, $indicador, $valor]);
                }
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

    /**
     * @param  array<string, mixed>  $datos
     * @return list<array{0: string, 1: string|int|float|null}>
     */
    private function aplanar(array $datos, string $prefijo = ''): array
    {
        $filas = [];
        foreach ($datos as $clave => $valor) {
            $nombre = $prefijo.str_replace('_', ' ', (string) $clave);
            if (is_array($valor)) {
                array_push($filas, ...$this->aplanar($valor, $nombre.': '));
            } elseif (is_int($valor) || is_float($valor)) {
                $filas[] = [ucfirst($nombre), $valor];
            } else {
                $filas[] = [ucfirst($nombre), $valor === null ? 'sin datos' : (string) $valor];
            }
        }

        return $filas;
    }
}
