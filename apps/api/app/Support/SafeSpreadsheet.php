<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Escribe filas en una hoja sin que PhpSpreadsheet infiera el tipo de celda.
 *
 * Worksheet::fromArray() y setCellValue() usan DefaultValueBinder, que convierte en
 * fórmula cualquier texto que empiece por "=" y en número cualquier texto numérico
 * (perdiendo ceros a la izquierda de un DNI). Aquí cada texto se guarda como cadena
 * literal (TYPE_STRING): el contenido se conserva intacto y Excel/LibreOffice no lo
 * evalúan al abrir el archivo. Los textos que empiezan por un carácter de fórmula
 * reciben además quotePrefix, para que tampoco se evalúen si alguien edita la celda.
 */
final class SafeSpreadsheet
{
    /** Primer carácter con el que una hoja de cálculo puede iniciar una fórmula o un DDE. */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  list<string|int|float|null>  $values  Los int/float se escriben como números; el resto como texto.
     */
    public static function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }

            $coordinate = Coordinate::stringFromColumnIndex($index + 1).$row;

            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);

                continue;
            }

            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);

            if (self::startsLikeFormula($value)) {
                $sheet->getStyle($coordinate)->setQuotePrefix(true);
            }
        }
    }

    public static function startsLikeFormula(string $value): bool
    {
        return $value !== '' && in_array($value[0], self::FORMULA_TRIGGERS, true);
    }
}
