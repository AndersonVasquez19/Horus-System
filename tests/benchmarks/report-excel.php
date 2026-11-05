<?php

declare(strict_types=1);

// Solo datos sintéticos; no arranca Laravel ni abre conexiones de base de datos.
// Ejecutar cada modo en un proceso distinto para comparar los picos de PHP.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\MatriculasExcelExport;
use App\Support\SafeSpreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$mode = $argv[1] ?? 'after';
$count = filter_var($argv[2] ?? '10000', FILTER_VALIDATE_INT);
if (! in_array($mode, ['before', 'after'], true) || $count === false || $count < 1 || $count > 100000) {
    throw new InvalidArgumentException('Uso: php tests/benchmarks/report-excel.php before|after 1..100000');
}

function rows(int $count): Generator
{
    for ($i = 1; $i <= $count; $i++) {
        yield [$i, ($i % 47 === 0 ? '=1+1' : 'Estudiante '.$i).' Ficticio', 'Curso '.($i % 100), '28/10/2026', 'pagada', ($i % 10000) / 100];
    }
}

$file = tempnam(sys_get_temp_dir(), 'horus-s03-');
if ($file === false) {
    throw new RuntimeException('No se pudo crear el temporal de la medición.');
}
try {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $start = hrtime(true);
    if ($mode === 'before') {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Matrículas');
        SafeSpreadsheet::writeRow($sheet, 1, ['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto']);
        $number = 2;
        foreach (rows($count) as $row) {
            SafeSpreadsheet::writeRow($sheet, $number++, $row);
        }
        (new Xlsx($book))->save($file);
    } else {
        (new MatriculasExcelExport)->writeRows(rows($count), $file);
    }
    $metrics = ['mode' => $mode, 'rows' => $count, 'seconds' => round((hrtime(true) - $start) / 1e9, 3),
        'peak_php_mib' => round(memory_get_peak_usage(true) / 1048576, 2), 'xlsx_bytes' => filesize($file)];
    if (isset($book)) {
        $book->disconnectWorksheets();
        unset($book, $sheet);
    }
    gc_collect_cycles();

    // Esta lectura ocurre DESPUÉS de capturar tiempo y memoria de generación.
    $book = IOFactory::load($file);
    $sheet = $book->getActiveSheet();
    $expected = hash_init('sha256');
    $actual = hash_init('sha256');
    $number = 2;
    foreach (rows($count) as $row) {
        hash_update($expected, json_encode($row, JSON_THROW_ON_ERROR));
        $read = $sheet->rangeToArray('A'.$number.':F'.$number, null, false, false)[0];
        hash_update($actual, json_encode($read, JSON_THROW_ON_ERROR));
        if ($sheet->getCell('B'.$number)->getDataType() !== 's') {
            throw new RuntimeException('El nombre perdió su tipo literal.');
        }
        $number++;
    }
    $metrics['sha256'] = hash_final($actual);
    $metrics['integrity'] = $metrics['sha256'] === hash_final($expected) && $sheet->getHighestDataRow() === $count + 1;
    $book->disconnectWorksheets();
    if (! $metrics['integrity']) {
        throw new RuntimeException('La comparación de integridad falló.');
    }
    echo json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    unlink($file);
}
