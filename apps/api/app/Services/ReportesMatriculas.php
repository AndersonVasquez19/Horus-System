<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\ReportMatriculasRequest;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Support\BusquedaLike;
use App\Support\Entrada;
use App\Support\SafeSpreadsheet;
use App\Support\SpreadsheetCellCache;
use App\Support\TemporaryExportFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * Consulta, paginación por lotes y descarga temporal de los reportes de matrículas (extraídas de
 * Admin\ReportController sin cambiar su comportamiento). La usan el panel Blade y la API de los paneles de apps/web.
 *
 * @phpstan-type CursoResumen array{codigo: string, nombre: string}
 * @phpstan-type FilaCobranza array{id: int, obligacion: string, estudiante: string|null, dni: string|null, curso: CursoResumen|null, concepto: string, total: string, pagado: string, saldo: string, estado: string}
 * @phpstan-type ResumenCobranza array{obligaciones: int, total_facturado: string, cobrado: string, saldo_pendiente: string}
 * @phpstan-type FilaAsistencia array{identificador: string, codigo: string, edicion: string, curso: CursoResumen|null, sesiones_realizadas: int, matriculados: int, promedio: float|null, estado: string}
 * @phpstan-type ResumenAsistencia array{ediciones: int, sesiones_realizadas: int, matriculados: int, promedio_asistencia: float|null}
 * @phpstan-type FilaCalificaciones array{identificador: string, codigo: string, edicion: string, curso: CursoResumen|null, matriculados: int, con_nota: int, aprobados: int, desaprobados: int, promedio: float|null, estado: string}
 * @phpstan-type ResumenCalificaciones array{ediciones: int, evaluados: int, aprobados: int, promedio_general: float|null}
 */
final class ReportesMatriculas
{
    public const TAMANO_LOTE = 50;

    public const MAX_PDF_COMPLETO = 500;

    /** @return Builder<Matricula> */
    public function consulta(ReportMatriculasRequest $request): Builder
    {
        // No hidratar observaciones ni datos personales que el reporte no utiliza.
        $query = Matricula::query()
            ->select(['id', 'estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado'])
            ->with(['estudiante:id,nombres,apellidos', 'curso:id,codigo,nombre']);
        $filtros = $request->filters();

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }
        if (isset($filtros['estado'])) {
            $query->where('estado', $filtros['estado']);
        }
        if (isset($filtros['curso_id'])) {
            $query->where('curso_id', $filtros['curso_id']);
        }
        if (isset($filtros['busqueda'])) {
            $busqueda = Entrada::texto($filtros['busqueda']);
            $query->where(function (Builder $query) use ($busqueda): void {
                $query->whereHas('estudiante', function (Builder $estudiante) use ($busqueda): void {
                    BusquedaLike::contiene($estudiante, ['dni', 'nombres', 'apellidos'], $busqueda);
                })->orWhereHas('curso', function (Builder $curso) use ($busqueda): void {
                    BusquedaLike::contiene($curso, ['codigo', 'nombre'], $busqueda);
                });
            });
        }

        return $query;
    }

    /**
     * El mismo agregado filtrado alimenta el resumen y el total del paginador. Los ingresos salen del libro de pagos
     * (transacciones confirmadas netas de reversos sobre obligaciones vigentes de las matrículas filtradas).
     *
     * @param  Builder<Matricula>  $query
     * @return array{total: int, estudiantes: int, cursos: int, ingresos: float}
     */
    public function resumen(Builder $query): array
    {
        $totales = (clone $query)->toBase()->select([])
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT estudiante_id) as estudiantes, COUNT(DISTINCT curso_id) as cursos')
            ->first();

        $netoLibro = DB::table('obligaciones_pago as o')
            ->join('transacciones_pago as t', fn ($j) => $j->on('t.obligacion_pago_id', '=', 'o.id')->where('t.estado', 'confirmada'))
            ->where('o.estado', 'vigente')
            ->whereIn('o.matricula_id', (clone $query)->toBase()->select('matriculas.id'))
            ->selectRaw("COALESCE(SUM(CASE WHEN t.tipo = 'pago' THEN t.monto ELSE -t.monto END), 0) as neto")
            ->value('neto') ?? 0;

        return [
            'total' => Entrada::entero($totales?->total),
            'estudiantes' => Entrada::entero($totales?->estudiantes),
            'cursos' => Entrada::entero($totales?->cursos),
            'ingresos' => max(0.0, Entrada::decimal($netoLibro)),
        ];
    }

    /**
     * @param  Builder<Matricula>  $query
     * @return LengthAwarePaginator<int, Matricula>
     */
    public function lote(Builder $query, ReportMatriculasRequest $request, ?int $total = null): LengthAwarePaginator
    {
        $matriculas = (clone $query)->orderByDesc('fecha_matricula')->orderByDesc('id')
            ->paginate(self::TAMANO_LOTE, ['*'], 'lote', Entrada::entero(($request->validated('lote') ?? 1)), $total)
            ->appends($request->filters());

        abort_if($matriculas->currentPage() > $matriculas->lastPage(), 404, 'El lote solicitado no existe. Vuelve a aplicar los filtros.');

        return $matriculas;
    }

    /**
     * Escribe el reporte en un temporal privado de nombre único, audita y lo entrega para descarga.
     *
     * El nombre interno es un UUID para que dos exportaciones simultáneas no compartan archivo.
     * Si la escritura o la auditoría fallan, el temporal (con datos personales) se borra antes
     * de propagar el error; en el camino feliz lo elimina deleteFileAfterSend().
     *
     * @param  callable(string): mixed  $escribir
     * @param  array<string, mixed>  $filtros
     */
    public function descargaTemporal(string $extension, string $accionAuditoria, callable $escribir, array $filtros = []): BinaryFileResponse
    {
        $nombre = 'reporte-matriculas-'.now()->format('Ymd-His').'.'.$extension;
        $disco = Storage::disk('local');
        if (! $disco->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }

        $archivo = $disco->path('exports/'.Str::uuid()->toString().'.'.$extension);
        $temporal = new TemporaryExportFile($archivo, false);

        try {
            $escribir($archivo);

            clearstatcache(true, $archivo);
            if (! is_file($archivo) || filesize($archivo) === 0) {
                throw new RuntimeException('No se pudo completar el archivo de exportación.');
            }

            AuditLog::record($accionAuditoria, Matricula::class, null, ['filename' => $nombre, 'filtros' => $filtros]);

            return response()->download($temporal, $nombre)->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($archivo)) {
                unlink($archivo);
            }

            throw $error;
        }
    }

    /**
     * Resumen acotado de los filtros usados en la exportación (el texto libre de búsqueda no se guarda).
     *
     * @return array{estado: string|null, curso_id: int|null, eliminados: bool, con_busqueda: bool, lote?: int}
     */
    public function filtrosAuditados(ReportMatriculasRequest $request, ?int $lote = null): array
    {
        $filtros = $request->filters();
        $resumen = [
            'estado' => isset($filtros['estado']) ? Entrada::texto($filtros['estado']) : null,
            'curso_id' => isset($filtros['curso_id']) ? Entrada::entero($filtros['curso_id']) : null,
            'eliminados' => $request->boolean('eliminados'),
            'con_busqueda' => isset($filtros['busqueda']) && Entrada::texto($filtros['busqueda']) !== '',
        ];

        if ($lote !== null) {
            $resumen['lote'] = $lote;
        }

        return $resumen;
    }

    /**
     * Reporte de cobranza (obligaciones, cobrado neto y saldo pendiente).
     *
     * @return array{rows: list<FilaCobranza>, resumen: ResumenCobranza}
     */
    public function cobranza(ReportMatriculasRequest $request): array
    {
        $filtros = $request->filters();
        $query = ObligacionPago::query()
            ->with(['matricula.estudiante:id,dni,nombres,apellidos', 'matricula.curso:id,codigo,nombre', 'transacciones'])
            ->whereHas('matricula', function (Builder $m) use ($filtros): void {
                if (isset($filtros['curso_id'])) {
                    $m->where('curso_id', Entrada::entero($filtros['curso_id']));
                }
                if (isset($filtros['busqueda'])) {
                    $b = Entrada::texto($filtros['busqueda']);
                    $m->where(function (Builder $sub) use ($b): void {
                        $sub->whereHas('estudiante', fn (Builder $e) => BusquedaLike::contiene($e, ['dni', 'nombres', 'apellidos'], $b))
                            ->orWhereHas('curso', fn (Builder $c) => BusquedaLike::contiene($c, ['codigo', 'nombre'], $b));
                    });
                }
            })
            ->orderByDesc('id');

        if (($filtros['estado'] ?? null) === 'anulada') {
            $query->where('estado', 'anulada');
        } else {
            $query->where('estado', 'vigente');
        }

        $pagos = app(GestionPagos::class);
        $estadoFiltro = isset($filtros['estado']) ? Entrada::texto($filtros['estado']) : '';
        $filas = [];
        $totalFacturado = 0;
        $totalCobrado = 0;
        $totalSaldo = 0;

        foreach ($query->get() as $ob) {
            $saldos = $pagos->saldos($ob);
            $estadoCobro = match (true) {
                $ob->estado === 'anulada' => 'anulada',
                $saldos['confirmado'] >= $saldos['total'] => 'pagada',
                $saldos['confirmado'] > 0 => 'parcial',
                default => 'pendiente',
            };
            if (in_array($estadoFiltro, ['pendiente', 'parcial', 'pagada'], true) && $estadoCobro !== $estadoFiltro) {
                continue;
            }
            $mat = $ob->matricula;
            $est = $mat?->estudiante;
            $curso = $mat?->curso;

            $totalFacturado += $saldos['total'];
            $totalCobrado += $saldos['confirmado'];
            $totalSaldo += $saldos['saldo'];

            $filas[] = [
                'id' => $ob->matricula_id,
                'obligacion' => $ob->identificador,
                'estudiante' => $est ? trim($est->nombres.' '.$est->apellidos) : null,
                'dni' => $est?->dni,
                'curso' => $curso ? ['codigo' => $curso->codigo, 'nombre' => $curso->nombre] : null,
                'concepto' => $ob->concepto,
                'total' => GestionPagos::formato($saldos['total']),
                'pagado' => GestionPagos::formato($saldos['confirmado']),
                'saldo' => GestionPagos::formato($saldos['saldo']),
                'estado' => $estadoCobro,
            ];
        }

        return [
            'rows' => $filas,
            'resumen' => [
                'obligaciones' => count($filas),
                'total_facturado' => GestionPagos::formato($totalFacturado),
                'cobrado' => GestionPagos::formato($totalCobrado),
                'saldo_pendiente' => GestionPagos::formato($totalSaldo),
            ],
        ];
    }

    /**
     * Reporte consolidado de asistencia por edición.
     *
     * @return array{rows: list<FilaAsistencia>, resumen: ResumenAsistencia}
     */
    public function asistencia(ReportMatriculasRequest $request): array
    {
        $filtros = $request->filters();
        $query = Convocatoria::query()->with('curso:id,codigo,nombre')->orderByDesc('fecha_inicio');
        if (isset($filtros['curso_id'])) {
            $query->where('curso_id', Entrada::entero($filtros['curso_id']));
        }
        if (isset($filtros['busqueda'])) {
            $b = Entrada::texto($filtros['busqueda']);
            $query->where(function (Builder $q) use ($b): void {
                BusquedaLike::contiene($q, ['codigo', 'nombre'], $b);
                $q->orWhereHas('curso', fn (Builder $c) => BusquedaLike::contiene($c, ['codigo', 'nombre'], $b));
            });
        }

        $asistencias = app(GestionAsistencias::class);
        $filas = [];
        $totalSesiones = 0;
        $totalMatriculados = 0;
        $promedios = [];

        foreach ($query->get() as $conv) {
            $res = $asistencias->resumen($conv);
            $porcentajes = collect($res['matriculas'])->pluck('porcentaje')->filter(fn ($v): bool => $v !== null);
            $promedio = $porcentajes->isEmpty() ? null : round((float) $porcentajes->avg(), 1);
            if ($promedio !== null) {
                $promedios[] = $promedio;
            }
            $totalSesiones += $res['sesiones_realizadas'];
            $totalMatriculados += count($res['matriculas']);

            $filas[] = [
                'identificador' => $conv->identificador,
                'codigo' => $conv->codigo,
                'edicion' => $conv->nombre ?: ($conv->curso->nombre ?? $conv->codigo),
                'curso' => $conv->curso ? ['codigo' => $conv->curso->codigo, 'nombre' => $conv->curso->nombre] : null,
                'sesiones_realizadas' => $res['sesiones_realizadas'],
                'matriculados' => count($res['matriculas']),
                'promedio' => $promedio,
                'estado' => $conv->estado,
            ];
        }

        return [
            'rows' => $filas,
            'resumen' => [
                'ediciones' => count($filas),
                'sesiones_realizadas' => $totalSesiones,
                'matriculados' => $totalMatriculados,
                'promedio_asistencia' => $promedios === [] ? null : round(array_sum($promedios) / count($promedios), 1),
            ],
        ];
    }

    /**
     * Reporte consolidado de calificaciones por edición.
     *
     * @return array{rows: list<FilaCalificaciones>, resumen: ResumenCalificaciones}
     */
    public function calificaciones(ReportMatriculasRequest $request): array
    {
        $filtros = $request->filters();
        $query = Convocatoria::query()->with('curso:id,codigo,nombre')->orderByDesc('fecha_inicio');
        if (isset($filtros['curso_id'])) {
            $query->where('curso_id', Entrada::entero($filtros['curso_id']));
        }
        if (isset($filtros['busqueda'])) {
            $b = Entrada::texto($filtros['busqueda']);
            $query->where(function (Builder $q) use ($b): void {
                BusquedaLike::contiene($q, ['codigo', 'nombre'], $b);
                $q->orWhereHas('curso', fn (Builder $c) => BusquedaLike::contiene($c, ['codigo', 'nombre'], $b));
            });
        }

        $filas = [];
        $totalEvaluados = 0;
        $totalAprobados = 0;
        $todasNotas = [];

        foreach ($query->get() as $conv) {
            $mats = Matricula::query()
                ->where('convocatoria_id', $conv->id)
                ->where('estado', '!=', 'anulada')
                ->get(['nota_final', 'resultado']);
            $conNota = $mats->filter(fn (Matricula $m): bool => $m->nota_final !== null);
            $aprobados = $conNota->where('resultado', 'aprobado')->count();
            $desaprobados = $conNota->where('resultado', 'desaprobado')->count();
            $promedio = $conNota->isEmpty() ? null : round((float) $conNota->avg('nota_final'), 2);
            foreach ($conNota as $m) {
                $todasNotas[] = (float) $m->nota_final;
            }
            $totalEvaluados += $conNota->count();
            $totalAprobados += $aprobados;

            $filas[] = [
                'identificador' => $conv->identificador,
                'codigo' => $conv->codigo,
                'edicion' => $conv->nombre ?: ($conv->curso->nombre ?? $conv->codigo),
                'curso' => $conv->curso ? ['codigo' => $conv->curso->codigo, 'nombre' => $conv->curso->nombre] : null,
                'matriculados' => $mats->count(),
                'con_nota' => $conNota->count(),
                'aprobados' => $aprobados,
                'desaprobados' => $desaprobados,
                'promedio' => $promedio,
                'estado' => $conv->estado,
            ];
        }

        return [
            'rows' => $filas,
            'resumen' => [
                'ediciones' => count($filas),
                'evaluados' => $totalEvaluados,
                'aprobados' => $totalAprobados,
                'promedio_general' => $todasNotas === [] ? null : round(array_sum($todasNotas) / count($todasNotas), 2),
            ],
        ];
    }

    /**
     * Genera un archivo XLSX seguro desde cabeceras y filas tabulares.
     *
     * @param  list<string>  $cabeceras
     * @param  iterable<list<string|int|float|null>>  $filas
     */
    public function escribirXlsxTabla(array $cabeceras, iterable $filas, string $tituloHoja, string $archivo): void
    {
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
            foreach ($filas as $row) {
                SafeSpreadsheet::writeRow($sheet, $rowNumber++, $row);
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
