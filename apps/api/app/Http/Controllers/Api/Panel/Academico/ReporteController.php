<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportMatriculasRequest;
use App\Models\Curso;
use App\Models\Matricula;
use App\Services\MatriculasExcelExport;
use App\Services\ReportesMatriculas;
use App\Support\ConfiguracionSistema;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes del panel académico (`matriculas`, `cobranza`, `asistencia`, `calificaciones`).
 */
final class ReporteController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ReportesMatriculas $reportes) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reportes.ver', only: ['index']),
            new Middleware('permission:reportes.exportar.excel', only: ['exportarExcel']),
            new Middleware('permission:reportes.exportar.pdf', only: ['exportarPdf']),
        ];
    }

    public function index(ReportMatriculasRequest $request): JsonResponse
    {
        $tipo = $request->tipo();
        $this->autorizarTipo($request, $tipo);

        if ($tipo !== 'matriculas') {
            return $this->indexSecundario($request, $tipo);
        }

        $consulta = $this->reportes->consulta($request);
        $resumen = $this->reportes->resumen($consulta);
        $lote = $this->reportes->lote($consulta, $request, $resumen['total']);

        $totales = [
            'estudiantes' => $resumen['estudiantes'],
            'cursos' => $resumen['cursos'],
            'matriculas' => $lote->total(),
        ];
        if ($request->user()?->hasRole('admin') === true) {
            $totales['ingresos'] = number_format($resumen['ingresos'], 2, '.', '');
        }

        return response()->json([
            'tipo' => 'matriculas',
            'data' => $lote->getCollection()->map(fn (Matricula $matricula): array => [
                'id' => $matricula->id,
                'estudiante' => $matricula->estudiante === null ? null : trim($matricula->estudiante->nombres.' '.$matricula->estudiante->apellidos),
                'curso' => $matricula->curso === null ? null : ['codigo' => $matricula->curso->codigo, 'nombre' => $matricula->curso->nombre],
                'fecha' => $matricula->fecha_matricula->toDateString(),
                'estado' => $matricula->estado,
                'monto_pagado' => $matricula->monto_pagado === null ? null : number_format((float) $matricula->monto_pagado, 2, '.', ''),
            ])->values()->all(),
            'resumen' => $totales,
            'meta' => [
                'lote' => $lote->currentPage(),
                'lotes' => $lote->lastPage(),
                'tamano_lote' => ReportesMatriculas::TAMANO_LOTE,
                'total' => $lote->total(),
                'filtros' => $request->filters(),
                'cursos' => $this->cursosDisponibles(),
            ],
        ]);
    }

    public function exportarExcel(ReportMatriculasRequest $request): BinaryFileResponse
    {
        $tipo = $request->tipo();
        $this->autorizarTipo($request, $tipo);

        if ($tipo === 'matriculas') {
            return $this->reportes->descargaTemporal(
                'xlsx',
                'report_excel_exported',
                fn (string $archivo) => app(MatriculasExcelExport::class)->write($this->reportes->consulta($request), $archivo),
                $this->reportes->filtrosAuditados($request),
            );
        }

        [$cabeceras, $filas, $tituloHoja] = $this->tablaParaTipo($request, $tipo);

        return $this->reportes->descargaTemporal(
            'xlsx',
            'report_excel_exported',
            fn (string $archivo) => $this->reportes->escribirXlsxTabla($cabeceras, $filas, $tituloHoja, $archivo),
            [...$this->reportes->filtrosAuditados($request), 'tipo' => $tipo],
        );
    }

    public function exportarPdf(ReportMatriculasRequest $request): BinaryFileResponse
    {
        $tipo = $request->tipo();
        $this->autorizarTipo($request, $tipo);

        $cursoFiltro = $request->filled('curso_id')
            ? Curso::withTrashed()->find($request->validated('curso_id'), ['id', 'codigo', 'nombre'])
            : null;

        if ($tipo === 'matriculas' && ! $request->boolean('completo')) {
            $matriculas = $this->reportes->lote($this->reportes->consulta($request), $request);

            // La vista solo es la plantilla del PDF; la interfaz del panel es la de apps/web.
            $pdf = Pdf::loadView('pdf.reporte-matriculas', [
                'matriculas' => $matriculas,
                'fecha' => HoraLocal::ahora()->format('d/m/Y'),
                'filtros' => $request->filters(),
                'limitePdf' => ReportesMatriculas::TAMANO_LOTE,
                'cursoFiltro' => $cursoFiltro,
            ]);

            return $this->reportes->descargaTemporal(
                'pdf',
                'report_pdf_exported',
                fn (string $archivo) => $pdf->save($archivo),
                $this->reportes->filtrosAuditados($request, $matriculas->currentPage()),
            );
        }

        if ($tipo === 'matriculas') {
            $consulta = $this->reportes->consulta($request);
            $totalReal = (clone $consulta)->count();
            $registros = (clone $consulta)
                ->orderByDesc('fecha_matricula')
                ->orderByDesc('id')
                ->limit(ReportesMatriculas::MAX_PDF_COMPLETO)
                ->get();

            $filas = $registros->map(fn (Matricula $m): array => [
                (string) $m->id,
                $m->estudiante ? trim($m->estudiante->nombres.' '.$m->estudiante->apellidos) : '—',
                $m->curso->nombre ?? '—',
                $m->fecha_matricula->format('d/m/Y'),
                ucfirst((string) $m->estado),
                $m->monto_pagado !== null ? ConfiguracionSistema::monto($m->monto_pagado) : '—',
            ])->all();

            $titulo = 'Reporte Completo de Matrículas';
            $subtitulo = 'Consolidado completo filtrado hasta '.ReportesMatriculas::MAX_PDF_COMPLETO.' registros';
            $cabeceras = ['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto'];
            $metaResumen = [];
            $notaTruncado = $totalReal > ReportesMatriculas::MAX_PDF_COMPLETO
                ? 'Mostrando los primeros '.ReportesMatriculas::MAX_PDF_COMPLETO." de {$totalReal} registros; usa Excel para descargar el listado íntegro."
                : null;
        } else {
            [$cabeceras, $todasFilas, $titulo, $subtitulo, $metaResumen] = $this->tablaParaTipo($request, $tipo);
            $totalReal = count($todasFilas);
            $filas = array_slice($todasFilas, 0, ReportesMatriculas::MAX_PDF_COMPLETO);
            $notaTruncado = $totalReal > ReportesMatriculas::MAX_PDF_COMPLETO
                ? 'Mostrando las primeras '.ReportesMatriculas::MAX_PDF_COMPLETO." filas de {$totalReal}; usa Excel para descargar el listado íntegro."
                : null;
        }

        $pdf = Pdf::loadView('pdf.reporte-academico', [
            'titulo' => $titulo,
            'subtitulo' => $subtitulo,
            'fecha' => HoraLocal::ahora()->format('d/m/Y H:i'),
            'total' => $totalReal,
            'cursoFiltro' => $cursoFiltro,
            'cabeceras' => $cabeceras,
            'filas' => $filas,
            'metaResumen' => $metaResumen,
            'notaTruncado' => $notaTruncado,
        ]);

        return $this->reportes->descargaTemporal(
            'pdf',
            'report_pdf_exported',
            fn (string $archivo) => $pdf->save($archivo),
            [...$this->reportes->filtrosAuditados($request), 'tipo' => $tipo, 'completo' => true],
        );
    }

    private function indexSecundario(ReportMatriculasRequest $request, string $tipo): JsonResponse
    {
        $datos = match ($tipo) {
            'cobranza' => $this->reportes->cobranza($request),
            'asistencia' => $this->reportes->asistencia($request),
            'calificaciones' => $this->reportes->calificaciones($request),
            default => throw new \InvalidArgumentException('Tipo de reporte no reconocido.'),
        };

        $todas = $datos['rows'];
        $total = count($todas);
        $porPagina = ReportesMatriculas::TAMANO_LOTE;
        $ultima = max(1, (int) ceil($total / $porPagina));
        $pagina = Entrada::entero(($request->validated('lote') ?? 1));
        abort_if($pagina > $ultima, 404, 'El lote solicitado no existe. Vuelve a aplicar los filtros.');

        return response()->json([
            'tipo' => $tipo,
            'data' => array_slice($todas, ($pagina - 1) * $porPagina, $porPagina),
            'resumen' => $datos['resumen'],
            'meta' => [
                'lote' => $pagina,
                'lotes' => $ultima,
                'tamano_lote' => $porPagina,
                'total' => $total,
                'filtros' => $request->filters(),
                'cursos' => $this->cursosDisponibles(),
            ],
        ]);
    }

    /** @return array{0: list<string>, 1: list<list<string|int|float|null>>, 2: string, 3: string, 4: array<string, string>} */
    private function tablaParaTipo(ReportMatriculasRequest $request, string $tipo): array
    {
        if ($tipo === 'cobranza') {
            $d = $this->reportes->cobranza($request);

            return [
                ['Matrícula', 'DNI', 'Estudiante', 'Curso', 'Concepto', 'Total (PEN)', 'Cobrado (PEN)', 'Saldo (PEN)', 'Estado'],
                array_map(fn (array $r): array => [
                    '#'.$r['id'],
                    $r['dni'],
                    $r['estudiante'],
                    $r['curso'] ? $r['curso']['codigo'].' · '.$r['curso']['nombre'] : null,
                    $r['concepto'],
                    $r['total'],
                    $r['pagado'],
                    $r['saldo'],
                    ucfirst(Entrada::texto($r['estado'])),
                ], $d['rows']),
                'Reporte de Pagos',
                'Estado de obligaciones y saldos derivados del libro de pagos',
                [
                    'Facturado' => ConfiguracionSistema::monto($d['resumen']['total_facturado']),
                    'Cobrado' => ConfiguracionSistema::monto($d['resumen']['cobrado']),
                    'Saldo pendiente' => ConfiguracionSistema::monto($d['resumen']['saldo_pendiente']),
                ],
            ];
        }

        if ($tipo === 'asistencia') {
            $d = $this->reportes->asistencia($request);

            return [
                ['Edición', 'Curso', 'Sesiones realizadas', 'Matriculados', 'Asistencia promedio', 'Estado'],
                array_map(fn (array $r): array => [
                    $r['codigo'].' · '.$r['edicion'],
                    $r['curso'] ? $r['curso']['codigo'].' · '.$r['curso']['nombre'] : null,
                    Entrada::texto($r['sesiones_realizadas']),
                    Entrada::texto($r['matriculados']),
                    $r['promedio'] !== null ? $r['promedio'].'%' : 'Sin sesiones',
                    ucfirst(Entrada::texto($r['estado'])),
                ], $d['rows']),
                'Reporte de Asistencia',
                'Consolidado de sesiones realizadas y asistencia por edición',
                [
                    'Ediciones' => Entrada::texto($d['resumen']['ediciones']),
                    'Sesiones realizadas' => Entrada::texto($d['resumen']['sesiones_realizadas']),
                    'Promedio' => $d['resumen']['promedio_asistencia'] !== null ? $d['resumen']['promedio_asistencia'].'%' : '—',
                ],
            ];
        }

        $d = $this->reportes->calificaciones($request);

        return [
            ['Edición', 'Curso', 'Matriculados', 'Evaluados', 'Aprobados', 'Desaprobados', 'Promedio', 'Estado'],
            array_map(fn (array $r): array => [
                $r['codigo'].' · '.$r['edicion'],
                $r['curso'] ? $r['curso']['codigo'].' · '.$r['curso']['nombre'] : null,
                Entrada::texto($r['matriculados']),
                Entrada::texto($r['con_nota']),
                Entrada::texto($r['aprobados']),
                Entrada::texto($r['desaprobados']),
                $r['promedio'] !== null ? number_format(Entrada::decimal($r['promedio']), 2) : '—',
                ucfirst(Entrada::texto($r['estado'])),
            ], $d['rows']),
            'Reporte de Calificaciones',
            'Consolidado de notas finales y aprobación por edición',
            [
                'Ediciones' => Entrada::texto($d['resumen']['ediciones']),
                'Evaluados' => Entrada::texto($d['resumen']['evaluados']),
                'Aprobados' => Entrada::texto($d['resumen']['aprobados']),
                'Promedio general' => $d['resumen']['promedio_general'] !== null ? number_format(Entrada::decimal($d['resumen']['promedio_general']), 2) : '—',
            ],
        ];
    }

    private function autorizarTipo(ReportMatriculasRequest $request, string $tipo): void
    {
        $permiso = match ($tipo) {
            'cobranza' => 'pagos.ver',
            'asistencia' => 'asistencias.ver',
            'calificaciones' => 'calificaciones.ver',
            default => null,
        };
        if ($permiso !== null) {
            abort_unless($request->user()?->can($permiso) === true, 403);
        }
    }

    /** @return list<array{id: int, codigo: string, nombre: string}> */
    private function cursosDisponibles(): array
    {
        return array_values(Curso::withTrashed()
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre'])
            ->map(fn (Curso $c): array => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre])
            ->all());
    }
}
