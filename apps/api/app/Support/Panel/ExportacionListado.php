<?php

declare(strict_types=1);

namespace App\Support\Panel;

use App\Models\AuditLog;
use App\Support\Entrada;
use App\Support\SafeSpreadsheet;
use App\Support\SensitiveData;
use App\Support\SpreadsheetCellCache;
use App\Support\TemporaryExportFile;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Exportación segura de listados del panel en CSV (UTF-8 con BOM) o XLSX.
 * Reutiliza `SafeSpreadsheet`, neutraliza fórmulas (`csvSafe`), acota a `MAX_FILAS` informando truncamiento
 * mediante cabeceras y audita la descarga sin guardar texto libre de búsqueda.
 */
final class ExportacionListado
{
    public const MAX_FILAS = 5000;

    public static function formato(Request $request, string $predeterminado = 'csv'): string
    {
        $valor = strtolower(trim(Entrada::texto($request->query('formato', $request->input('formato', $predeterminado)))));

        return in_array($valor, ['csv', 'xlsx'], true) ? $valor : $predeterminado;
    }

    /**
     * @param  list<string>  $cabeceras
     * @param  iterable<int, list<string|int|float|null>>  $filas
     * @param  class-string|null  $modeloAuditoria
     * @param  array<string, mixed>  $contextoAuditoria
     */
    public static function responder(
        string $prefijo,
        string $tituloHoja,
        string $formato,
        array $cabeceras,
        iterable $filas,
        string $accionAuditoria,
        ?string $modeloAuditoria,
        int $actor,
        array $contextoAuditoria = [],
    ): StreamedResponse|BinaryFileResponse {
        $colectadas = [];
        $truncado = false;
        foreach ($filas as $fila) {
            if (count($colectadas) >= self::MAX_FILAS) {
                $truncado = true;
                break;
            }
            $colectadas[] = $fila;
        }

        $extension = $formato === 'xlsx' ? 'xlsx' : 'csv';
        $nombre = $prefijo.'_'.now()->format('Ymd_His').'.'.$extension;

        AuditLog::record($accionAuditoria, $modeloAuditoria, null, [
            'filename' => $nombre,
            'formato' => $extension,
            'registros' => count($colectadas),
            'truncado' => $truncado,
            ...$contextoAuditoria,
        ], $actor);

        $cabecerasRespuesta = ['Cache-Control' => 'no-store, private'];
        if ($truncado) {
            $cabecerasRespuesta['X-Export-Truncated'] = 'true';
            $cabecerasRespuesta['X-Export-Message'] = 'Exportación limitada a los primeros '.self::MAX_FILAS.' registros; afina los filtros para exportar todo.';
        }

        if ($extension === 'csv') {
            return response()->streamDownload(function () use ($cabeceras, $colectadas): void {
                $salida = fopen('php://output', 'wb');
                if (! is_resource($salida)) {
                    throw new RuntimeException('No se pudo crear el archivo CSV.');
                }
                fwrite($salida, "\xEF\xBB\xBF");
                fputcsv($salida, $cabeceras);
                foreach ($colectadas as $fila) {
                    fputcsv($salida, array_map(static fn ($celda): string => SensitiveData::csvSafe($celda), $fila));
                }
                fclose($salida);
            }, $nombre, [
                ...$cabecerasRespuesta,
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $archivo = tempnam(sys_get_temp_dir(), 'horus_xlsx_');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal XLSX.');
        }

        $previousCache = Settings::getCache();
        $cache = new SpreadsheetCellCache;
        $spreadsheet = null;
        try {
            Settings::setCache($cache);
            $spreadsheet = new Spreadsheet;
            Settings::setCache($previousCache);
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(mb_substr($tituloHoja, 0, 31));
            SafeSpreadsheet::writeRow($sheet, 1, $cabeceras);
            $rowNumber = 2;
            foreach ($colectadas as $fila) {
                SafeSpreadsheet::writeRow($sheet, $rowNumber++, $fila);
            }
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($archivo);

            $zip = new ZipArchive;
            if ($zip->open($archivo, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('No se pudo completar el archivo XLSX.');
            }
            try {
                if ($zip->locateName('xl/worksheets/sheet1.xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                    throw new RuntimeException('El archivo XLSX está incompleto.');
                }
            } finally {
                $zip->close();
            }

            $temporal = new TemporaryExportFile($archivo, false);

            return response()->download($temporal, $nombre, [
                ...$cabecerasRespuesta,
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $e) {
            @unlink($archivo);
            throw $e;
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
