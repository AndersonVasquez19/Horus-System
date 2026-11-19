<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Services\ComprobantesPago;
use App\Services\GestionPagos;
use App\Services\PagosExcel;
use App\Support\BusquedaLike;
use App\Support\HoraLocal;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use App\Support\TemporaryExportFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Finanzas (`pagos.ver|planificar|registrar|confirmar|reversar`). Toda escritura pasa por {@see GestionPagos}. Las respuestas
 * llevan los importes como texto decimal (PEN) y nunca incluyen la clave de idempotencia ni datos de quien opera.
 */
final class PagoController extends Controller implements HasMiddleware
{
    private const LIMITE_EXPORTACION = 5000;

    private const ORDENABLES = ['creado' => 'obligaciones_pago.created_at', 'monto' => 'obligaciones_pago.monto_total'];

    public function __construct(private readonly GestionPagos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:pagos.ver', only: ['metodos', 'index', 'show', 'deMatricula', 'verComprobante', 'recibo']),
            new Middleware('permission:pagos.registrar', only: ['adjuntarComprobante']),
            new Middleware('permission:pagos.planificar', only: ['crearObligacion', 'anular']),
            new Middleware('permission:pagos.registrar', only: ['registrar']),
            new Middleware('permission:pagos.confirmar', only: ['confirmar', 'rechazar']),
            new Middleware('permission:pagos.reversar', only: ['reversar']),
            new Middleware('permission:pagos.exportar', only: ['exportar']),
        ];
    }

    public function metodos(): JsonResponse
    {
        return $this->json(['data' => MetodoPago::query()->where('activo', true)->orderBy('nombre')->get(['codigo', 'nombre'])->all()]);
    }

    public function index(Request $request): JsonResponse
    {
        [$consulta, $filtros] = $this->consultaFiltrada($request);
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-creado');
        $paginas = Listado::paginar($consulta, $request);

        return Listado::responder($paginas, fn (ObligacionPago $o): array => $this->resumen($o), $orden, $filtros)->header('Cache-Control', 'no-store, private');
    }

    /** Excel de las obligaciones (hasta 5000, respetando filtros y orden), sin conservarlo en el servidor y con constancia sin datos personales. */
    public function exportar(Request $request): BinaryFileResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        [$consulta, $filtros] = $this->consultaFiltrada($request);
        Listado::ordenar($consulta, $request, self::ORDENABLES, '-creado');

        $coleccion = $consulta->limit(self::LIMITE_EXPORTACION + 1)->get();
        $truncado = $coleccion->count() > self::LIMITE_EXPORTACION;
        $filas = $coleccion->take(self::LIMITE_EXPORTACION)->map(fn (ObligacionPago $o): array => $this->resumen($o))->all();

        $nombre = 'pagos-'.now()->format('Ymd-His').'.xlsx';
        $disco = Storage::disk('local');
        if (! $disco->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }
        $archivo = $disco->path('exports/'.Str::uuid()->toString().'.xlsx');
        $temporal = new TemporaryExportFile($archivo, false);

        try {
            app(PagosExcel::class)->write($filas, $archivo);
            clearstatcache(true, $archivo);
            if (! is_file($archivo) || filesize($archivo) === 0) {
                throw new RuntimeException('No se pudo completar el archivo de exportación.');
            }
            AuditLog::record('pagos_exportados', ObligacionPago::class, null, [
                'filename' => $nombre,
                'obligaciones' => count($filas),
                'truncado' => $truncado,
                'filtros' => [
                    'estado' => $filtros['estado'] !== '' ? $filtros['estado'] : null,
                    'desde' => $filtros['desde'] !== '' ? $filtros['desde'] : null,
                    'hasta' => $filtros['hasta'] !== '' ? $filtros['hasta'] : null,
                    'con_busqueda' => $filtros['busqueda'] !== '',
                ],
            ], $usuario->id);

            $cabeceras = [
                'Cache-Control' => 'no-store, private',
                'X-Export-Truncated' => $truncado ? 'true' : 'false',
                'X-Export-Limit' => (string) self::LIMITE_EXPORTACION,
            ];
            if ($truncado) {
                $cabeceras['X-Export-Message'] = 'Se exportaron los primeros '.self::LIMITE_EXPORTACION.' registros mas recientes. Aplica filtros para acotar la descarga.';
            }

            return response()->download($temporal, $nombre, $cabeceras)->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($archivo)) {
                unlink($archivo);
            }

            throw $error;
        }
    }

    public function show(string $obligacion): JsonResponse
    {
        return $this->json(['data' => $this->detalle($this->buscar($obligacion))]);
    }

    /** Recibo e historial de pagos confirmados en PDF (sin claves ni enlaces privados). */
    public function recibo(Request $request, string $obligacion): Response
    {
        /** @var User|null $usuario */
        $usuario = $request->user();
        $modelo = $this->buscar($obligacion);
        $modelo->loadMissing(['matricula.estudiante', 'matricula.curso', 'matricula.convocatoria', 'cuotas', 'transacciones.metodo', 'transacciones.reversaDe']);
        $detalle = $this->detalle($modelo);

        AuditLog::record('pago_recibo_pdf', ObligacionPago::class, $modelo->id, ['matricula_id' => $modelo->matricula_id], $usuario?->id);

        $pdf = Pdf::loadView('pdf.recibo-pago', [
            'obligacion' => $modelo,
            'detalle' => $detalle,
            'fecha' => HoraLocal::ahora()->format('d/m/Y H:i'),
        ]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"recibo-pago-{$modelo->identificador}.pdf\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Obligación vigente de una matrícula (o `data: null` si todavía no tiene). */
    public function deMatricula(int $matricula): JsonResponse
    {
        $o = ObligacionPago::query()->where('matricula_id', $matricula)->where('estado', 'vigente')->first();
        abort_if($o === null && ! Matricula::query()->withTrashed()->whereKey($matricula)->exists(), 404);

        return $this->json(['data' => $o === null ? null : $this->detalle($this->buscar($o->identificador))]);
    }

    public function crearObligacion(Request $request, int $matricula): JsonResponse
    {
        $datos = $request->validate([
            'concepto' => ['required', 'string', 'min:3', 'max:120', 'not_regex:/[<>]/u'],
            'plan' => ['required', 'string', 'in:completo,fraccionado'],
            'monto_total' => ['required'],
            'cuotas' => ['nullable', 'array', 'max:'.GestionPagos::MAX_CUOTAS],
            'cuotas.*' => ['array:monto,vence'],
            'cuotas.*.monto' => ['required'],
            'cuotas.*.vence' => ['required', 'date_format:Y-m-d'],
        ], [
            'concepto.required' => 'Indica el concepto.',
            'plan.in' => 'El plan debe ser completo o fraccionado.',
            'monto_total.required' => 'Indica el monto total.',
            'cuotas.*.vence.date_format' => 'La fecha de la cuota debe tener el formato AAAA-MM-DD.',
        ]);
        $o = $this->gestion->crearObligacion($request, $matricula, $datos);

        return $this->json(['message' => 'La obligación de pago se creó.', 'data' => $this->detalle($this->buscar($o->identificador))], 201);
    }

    public function anular(Request $request, string $obligacion): JsonResponse
    {
        $o = $this->gestion->anularObligacion($request, $this->buscar($obligacion)->identificador, $this->motivo($request));

        return $this->json(['message' => 'La obligación se anuló.', 'data' => $this->detalle($this->buscar($o->identificador))]);
    }

    public function registrar(Request $request, string $obligacion): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'metodo' => ['required', 'string', 'max:20'],
            'monto' => ['required'],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
            'referencia' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'confirmar' => ['nullable', 'boolean'],
        ], ['metodo.required' => 'Selecciona el método de pago.', 'monto.required' => 'Indica el importe.', 'fecha_pago.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.']);
        [$t, $creada] = $this->gestion->registrarPago($request, $this->buscar($obligacion)->identificador, $datos, $request->header('Idempotency-Key'));

        return $this->json(['message' => $creada ? 'El pago se registró.' : 'Este pago ya estaba registrado.', 'data' => $this->detalle($this->buscar($obligacion))], $creada ? 201 : 200);
    }

    /** Adjunta la captura del comprobante (campo `imagen`, JPG/PNG/WebP de hasta 3 MB) a un pago por confirmar. */
    public function adjuntarComprobante(Request $request, string $transaccion): JsonResponse
    {
        $request->validate(['imagen' => ComprobantesPago::reglas()], [
            'imagen.required' => 'Adjunta la captura del comprobante de pago.',
            'imagen.image' => 'El comprobante debe ser una imagen.',
            'imagen.mimes' => 'Sube una captura en JPG, PNG o WebP.',
            'imagen.mimetypes' => 'Sube una captura en JPG, PNG o WebP.',
            'imagen.max' => 'La captura no puede pasar de 3 MB.',
            'imagen.dimensions' => 'La captura debe medir entre 100 y 8000 píxeles por lado.',
        ]);
        $t = $this->gestion->adjuntarComprobante($request, $transaccion, $request->file('imagen'));

        return $this->json(['message' => 'El comprobante se adjuntó.', 'data' => $this->detalle($t->obligacion()->firstOrFail())]);
    }

    /** Sirve la captura con su tipo real; solo con permiso, sin caché y sin posibilidad de ejecutarse en el navegador. */
    public function verComprobante(Request $request, string $transaccion): Response
    {
        $c = $this->gestion->leerComprobante($request, $transaccion);

        return response($c['bytes'], 200, [
            'Content-Type' => $c['tipo'], 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; sandbox",
        ]);
    }

    public function confirmar(Request $request, string $transaccion): JsonResponse
    {
        $t = $this->gestion->confirmar($request, $transaccion);

        return $this->json(['message' => 'El pago se confirmó.', 'data' => $this->detalle($t->obligacion()->firstOrFail())]);
    }

    public function rechazar(Request $request, string $transaccion): JsonResponse
    {
        $t = $this->gestion->rechazar($request, $transaccion, $this->motivo($request));

        return $this->json(['message' => 'El pago se rechazó.', 'data' => $this->detalle($t->obligacion()->firstOrFail())]);
    }

    public function reversar(Request $request, string $transaccion): JsonResponse
    {
        $t = $this->gestion->reversar($request, $transaccion, $this->motivo($request));

        return $this->json(['message' => 'El pago se reversó.', 'data' => $this->detalle($t->obligacion()->firstOrFail())]);
    }

    private function motivo(Request $request): string
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:200']], [
            'motivo.required' => 'Indica el motivo.', 'motivo.min' => 'El motivo debe tener al menos 10 caracteres.', 'motivo.max' => 'El motivo admite hasta 200 caracteres.',
        ]);

        return trim($datos['motivo']);
    }

    private function buscar(string $identificador): ObligacionPago
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return ObligacionPago::query()->with(['matricula.estudiante', 'matricula.convocatoria', 'cuotas', 'transacciones.metodo', 'transacciones.reversaDe', 'transacciones.comprobante'])
            ->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /**
     * Consulta de obligaciones con sumas de transacciones calculadas en SQL para filtrar el estado de cobro antes de paginar
     * y evitar consultas N+1 al presentar cada fila.
     *
     * @return array{0: Builder<ObligacionPago>, 1: array{busqueda: string, estado: string, desde: string, hasta: string}}
     */
    private function consultaFiltrada(Request $request): array
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', ['pendiente', 'parcial', 'con_saldo', 'pagada', 'anulada', 'por_confirmar', 'vencidas']),
            'desde' => ListFilter::date($request, 'desde'),
            'hasta' => ListFilter::date($request, 'hasta'),
        ];

        $sqlConfirmado = "(SELECT ROUND(COALESCE(SUM(CASE WHEN t.tipo = 'pago' THEN t.monto ELSE -t.monto END), 0), 2) FROM transacciones_pago t WHERE t.obligacion_pago_id = obligaciones_pago.id AND t.estado = 'confirmada')";
        $sqlPendiente = "(SELECT ROUND(COALESCE(SUM(t.monto), 0), 2) FROM transacciones_pago t WHERE t.obligacion_pago_id = obligaciones_pago.id AND t.tipo = 'pago' AND t.estado = 'pendiente')";

        $consulta = ObligacionPago::query()
            ->select('obligaciones_pago.*')
            ->selectRaw("{$sqlConfirmado} as monto_confirmado_calculado, {$sqlPendiente} as monto_pendiente_calculado")
            ->with(['matricula.estudiante', 'matricula.convocatoria', 'cuotas']);

        if ($filtros['busqueda'] !== '') {
            $busqueda = $filtros['busqueda'];
            $consulta->where(function (Builder $q) use ($busqueda): void {
                $q->whereHas('matricula.estudiante', fn (Builder $e) => BusquedaLike::contiene($e, ['dni', 'nombres', 'apellidos'], $busqueda))
                    ->orWhereHas('matricula.convocatoria', fn (Builder $cv) => BusquedaLike::contiene($cv, ['codigo'], $busqueda));
                BusquedaLike::contiene($q, ['obligaciones_pago.concepto'], $busqueda, or: true);
            });
        }

        if ($filtros['estado'] === 'anulada') {
            $consulta->where('obligaciones_pago.estado', 'anulada');
        } elseif ($filtros['estado'] !== '') {
            $consulta->where('obligaciones_pago.estado', 'vigente');
            match ($filtros['estado']) {
                'pendiente' => $consulta->whereRaw("{$sqlConfirmado} <= 0"),
                'pagada' => $consulta->whereRaw("{$sqlConfirmado} >= ROUND(obligaciones_pago.monto_total, 2)"),
                'parcial' => $consulta->whereRaw("{$sqlConfirmado} > 0 AND {$sqlConfirmado} < ROUND(obligaciones_pago.monto_total, 2)"),
                'con_saldo' => $consulta->whereRaw("{$sqlConfirmado} < ROUND(obligaciones_pago.monto_total, 2)"),
                'por_confirmar' => $consulta->whereRaw("{$sqlPendiente} > 0"),
                'vencidas' => $consulta->whereRaw("{$sqlConfirmado} < ROUND(obligaciones_pago.monto_total, 2)")
                    ->whereHas('cuotas', function (Builder $c) use ($sqlConfirmado): void {
                        $c->where('vence', '<', HoraLocal::hoy())
                            ->whereRaw("ROUND(COALESCE((SELECT SUM(c2.monto) FROM cuotas_obligacion c2 WHERE c2.obligacion_pago_id = obligaciones_pago.id AND c2.numero <= cuotas_obligacion.numero), 0), 2) > {$sqlConfirmado}");
                    }),
                default => null,
            };
        }

        if ($filtros['desde'] !== '') {
            $consulta->where('obligaciones_pago.created_at', '>=', Carbon::parse($filtros['desde'], HoraLocal::ZONA)->startOfDay()->utc());
        }
        if ($filtros['hasta'] !== '') {
            $consulta->where('obligaciones_pago.created_at', '<=', Carbon::parse($filtros['hasta'], HoraLocal::ZONA)->endOfDay()->utc());
        }

        return [$consulta, $filtros];
    }

    /** @param  array{total: int, confirmado: int, pendiente: int, saldo: int}|null  $saldos */
    private function estadoCobro(ObligacionPago $o, ?array $saldos = null): string
    {
        if ($o->estado === 'anulada') {
            return 'anulada';
        }
        $s = $saldos ?? $this->gestion->saldos($o);

        return $s['confirmado'] <= 0 ? 'pendiente' : ($s['confirmado'] >= $s['total'] ? 'pagada' : 'parcial');
    }

    /** @return array<string, mixed> */
    private function resumen(ObligacionPago $o): array
    {
        $s = $this->gestion->saldos($o);
        $e = $o->matricula->estudiante;
        $proximaCuota = null;
        if ($o->estado === 'vigente' && $s['total'] > $s['confirmado']) {
            $o->loadMissing('cuotas');
            $restante = $s['confirmado'];
            $hoy = HoraLocal::hoy();
            foreach ($o->cuotas->sortBy('numero') as $cuota) {
                $montoCuota = GestionPagos::leer($cuota->monto);
                $cubierto = min($montoCuota, $restante);
                $restante -= $cubierto;
                $saldoCuota = $montoCuota - $cubierto;
                if ($saldoCuota > 0) {
                    $vence = substr((string) $cuota->vence, 0, 10);
                    $proximaCuota = [
                        'numero' => $cuota->numero,
                        'vence' => $vence,
                        'saldo' => GestionPagos::formato($saldoCuota),
                        'vencida' => $vence < $hoy,
                    ];

                    break;
                }
            }
        }

        return [
            'identificador' => $o->identificador,
            'matricula_id' => $o->matricula->id,
            'estudiante' => [
                'id' => $e->id,
                'dni' => $e->dni,
                'nombre' => trim($e->apellidos.', '.$e->nombres),
                'telefono' => $e->telefono,
                'email' => $e->email,
            ],
            'edicion' => $o->matricula->convocatoria?->codigo,
            'concepto' => $o->concepto,
            'plan' => $o->plan,
            'estado' => $this->estadoCobro($o, $s),
            'moneda' => $o->moneda,
            'monto_total' => GestionPagos::formato($s['total']),
            'pagado' => GestionPagos::formato($s['confirmado']),
            'pendiente_confirmar' => GestionPagos::formato($s['pendiente']),
            'saldo' => GestionPagos::formato(max(0, $s['total'] - $s['confirmado'])),
            'proxima_cuota' => $proximaCuota,
        ];
    }

    /** @return array<string, mixed> */
    private function detalle(ObligacionPago $o): array
    {
        $o->loadMissing(['matricula.estudiante', 'matricula.convocatoria', 'cuotas', 'transacciones.metodo', 'transacciones.reversaDe']);
        $pagado = $this->gestion->pagadoPorCuota($o);

        return [
            ...$this->resumen($o),
            'motivo_anulacion' => $o->motivo_anulacion,
            'cuotas' => $o->cuotas->map(function ($c) use ($pagado): array {
                $monto = GestionPagos::leer($c->monto);
                $p = $pagado[$c->id] ?? 0;

                return ['numero' => $c->numero, 'monto' => GestionPagos::formato($monto), 'vence' => substr((string) $c->vence, 0, 10), 'pagado' => GestionPagos::formato($p), 'saldo' => GestionPagos::formato(max(0, $monto - $p))];
            })->values()->all(),
            'transacciones' => $o->transacciones->map(fn (TransaccionPago $t): array => [
                'identificador' => $t->identificador,
                'tipo' => $t->tipo,
                'estado' => $t->estado,
                'monto' => GestionPagos::formato(GestionPagos::leer($t->monto)),
                'metodo' => $t->metodo->nombre,
                'fecha_pago' => substr((string) $t->fecha_pago, 0, 10),
                'referencia' => $t->referencia,
                'motivo' => $t->motivo,
                'reversa_de' => $t->reversaDe?->identificador,
                'tiene_comprobante' => $t->comprobante !== null,
                'revertida' => $t->tipo === 'pago' && $o->transacciones->contains(fn (TransaccionPago $x): bool => $x->reversa_de_id === $t->id),
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $cuerpo */
    private function json(array $cuerpo, int $estado = 200): JsonResponse
    {
        return response()->json($cuerpo, $estado)->header('Cache-Control', 'no-store, private');
    }
}
