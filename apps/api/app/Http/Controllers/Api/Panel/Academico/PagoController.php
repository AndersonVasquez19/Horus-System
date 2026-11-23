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

    private const ORDENABLES_COMPROBANTES = ['fecha' => 'transacciones_pago.fecha_pago', 'monto' => 'transacciones_pago.monto'];

    public function __construct(private readonly GestionPagos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:pagos.ver', only: ['metodos', 'index', 'comprobantes', 'show', 'deMatricula', 'verComprobante', 'recibo', 'cajaDiariaResumen', 'cajaDiariaPdf']),
            new Middleware('permission:pagos.registrar', only: ['adjuntarComprobante', 'registrar', 'registrarDeMatricula', 'generarObligacion', 'verificarReferencia']),
            new Middleware('permission:pagos.planificar', only: ['crearObligacion', 'anular']),
            new Middleware('permission:pagos.confirmar', only: ['confirmar', 'rechazar']),
            new Middleware('permission:pagos.reversar', only: ['reversar']),
            new Middleware('permission:pagos.exportar', only: ['exportar']),
        ];
    }

    /** Verifica si un código de operación (Yape/banco) ya fue usado en otra transacción no rechazada ni reversada. */
    public function verificarReferencia(Request $request): JsonResponse
    {
        $referencia = trim(\App\Support\Entrada::texto($request->query('referencia', '')));
        if (mb_strlen($referencia) < 3) {
            return response()->json(['data' => ['duplicada' => false, 'coincidencias' => []]]);
        }

        $filas = TransaccionPago::query()
            ->where('tipo', 'pago')
            ->whereIn('estado', ['pendiente', 'confirmada'])
            ->whereRaw('LOWER(referencia) = ?', [mb_strtolower($referencia)])
            ->whereNotExists(function (\Illuminate\Database\Query\Builder $q): void {
                $q->from('transacciones_pago as rev')
                    ->whereColumn('rev.reversa_de_id', 'transacciones_pago.id')
                    ->where('rev.estado', 'confirmada');
            })
            ->with(['obligacion:id,identificador,matricula_id'])
            ->latest('id')
            ->limit(5)
            ->get();

        $coincidencias = [];
        foreach ($filas as $t) {
            if ($t->obligacion === null) {
                continue;
            }
            $coincidencias[] = [
                'obligacion' => $t->obligacion->identificador,
                'matricula_id' => $t->obligacion->matricula_id,
                'estado' => $t->estado,
                'fecha_pago' => substr(\App\Support\Entrada::texto($t->fecha_pago), 0, 10),
            ];
        }

        return response()->json([
            'data' => [
                'duplicada' => $coincidencias !== [],
                'coincidencias' => $coincidencias,
            ],
        ]);
    }

    /** Resumen JSON de pagos confirmados del día (o fecha indicada) agrupado por método. */
    public function cajaDiariaResumen(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->datosCajaDiaria($request)]);
    }

    /** Reporte PDF de 1 hoja con el cierre de caja diario. */
    public function cajaDiariaPdf(Request $request): Response
    {
        $datos = $this->datosCajaDiaria($request);
        $pdf = Pdf::loadView('pdf.caja-diaria', [
            'fechaCaja' => $datos['fecha'],
            'fechaEmision' => HoraLocal::ahora()->format('d/m/Y H:i'),
            'cantidad' => $datos['cantidad'],
            'total' => $datos['total'],
            'porMetodo' => $datos['por_metodo'],
            'movimientos' => $datos['movimientos'],
        ]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"caja-diaria-{$datos['fecha']}.pdf\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array{fecha: string, cantidad: int, total: string, por_metodo: list<array{metodo: string, cantidad: int, total: string}>, movimientos: list<array{matricula_id: int, estudiante: string, metodo: string, referencia: string|null, monto: string}>} */
    private function datosCajaDiaria(Request $request): array
    {
        $fecha = ListFilter::date($request, 'fecha') ?: HoraLocal::hoy();

        $txs = TransaccionPago::query()
            ->where('tipo', 'pago')
            ->where('estado', 'confirmada')
            ->whereDate('fecha_pago', $fecha)
            ->with(['metodo:id,nombre', 'obligacion.matricula.estudiante:id,nombres,apellidos'])
            ->orderBy('id')
            ->get();

        $totalCentimos = 0;
        $grupos = [];
        $movimientos = [];

        foreach ($txs as $tx) {
            $centimos = GestionPagos::leer($tx->monto);
            $totalCentimos += $centimos;
            $metodo = $tx->metodo->nombre ?? 'Otro';
            if (! isset($grupos[$metodo])) {
                $grupos[$metodo] = ['metodo' => $metodo, 'cantidad' => 0, 'centimos' => 0];
            }
            $grupos[$metodo]['cantidad']++;
            $grupos[$metodo]['centimos'] += $centimos;

            $est = $tx->obligacion?->matricula?->estudiante;
            $movimientos[] = [
                'matricula_id' => $tx->obligacion->matricula_id ?? 0,
                'estudiante' => $est ? trim($est->nombres.' '.$est->apellidos) : 'Estudiante',
                'metodo' => $metodo,
                'referencia' => $tx->referencia,
                'monto' => GestionPagos::formato($centimos),
            ];
        }

        $porMetodo = array_values(array_map(static fn (array $g): array => [
            'metodo' => $g['metodo'],
            'cantidad' => $g['cantidad'],
            'total' => GestionPagos::formato($g['centimos']),
        ], $grupos));

        return [
            'fecha' => $fecha,
            'cantidad' => $txs->count(),
            'total' => GestionPagos::formato($totalCentimos),
            'por_metodo' => $porMetodo,
            'movimientos' => $movimientos,
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

    /**
     * Pagos registrados con su comprobante (uno por fila, el más reciente primero) y lo que aún debe cada matrícula. Es la vista
     * principal de Pagos: lo que la persona pagó, no la planificación de cobro. Solo pagos; los reversos no se listan.
     */
    public function comprobantes(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', ['por_confirmar', 'confirmada', 'rechazada']),
            'comprobante' => ListFilter::in($request, 'comprobante', ['sin']),
            'desde' => ListFilter::date($request, 'desde'),
            'hasta' => ListFilter::date($request, 'hasta'),
        ];

        $consulta = TransaccionPago::query()
            ->select('transacciones_pago.*')
            ->where('transacciones_pago.tipo', 'pago')
            ->with(['metodo', 'comprobante:id,transaccion_pago_id,mime','obligacion.transacciones', 'obligacion.matricula.estudiante', 'obligacion.matricula.convocatoria']);

        if ($filtros['busqueda'] !== '') {
            $busqueda = $filtros['busqueda'];
            $consulta->where(function (Builder $q) use ($busqueda): void {
                $q->whereHas('obligacion.matricula.estudiante', fn (Builder $e) => BusquedaLike::contiene($e, ['dni', 'nombres', 'apellidos'], $busqueda))
                    ->orWhereHas('obligacion.matricula.convocatoria', fn (Builder $cv) => BusquedaLike::contiene($cv, ['codigo'], $busqueda));
                BusquedaLike::contiene($q, ['transacciones_pago.referencia'], $busqueda, or: true);
            });
        }
        if ($filtros['estado'] !== '') {
            $consulta->where('transacciones_pago.estado', $filtros['estado'] === 'por_confirmar' ? 'pendiente' : $filtros['estado']);
        }
        if ($filtros['comprobante'] === 'sin') {
            $consulta->whereDoesntHave('comprobante');
        }
        if ($filtros['desde'] !== '') {
            $consulta->whereDate('transacciones_pago.fecha_pago', '>=', $filtros['desde']);
        }
        if ($filtros['hasta'] !== '') {
            $consulta->whereDate('transacciones_pago.fecha_pago', '<=', $filtros['hasta']);
        }

        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES_COMPROBANTES, '-fecha');
        $paginas = Listado::paginar($consulta, $request);
        $repetidas = $this->gestion->imagenesRepetidas(array_values(array_map(static fn (TransaccionPago $t): int => $t->id, $paginas->items())));

        return Listado::responder($paginas, fn (TransaccionPago $t): array => $this->filaComprobante($t, $repetidas[$t->id] ?? null), $orden, $filtros)->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  array{matricula_id: int, fecha_pago: string}|null  $repetida
     * @return array<string, mixed>
     */
    private function filaComprobante(TransaccionPago $t, ?array $repetida): array
    {
        $o = $t->obligacion;
        $matricula = $o?->matricula;
        $e = $matricula?->estudiante;
        if ($o === null || $matricula === null || $e === null) {
            throw new RuntimeException("El pago {$t->identificador} no tiene matrícula o estudiante asociado.");
        }
        $s = $this->gestion->saldos($o);

        return [
            'identificador' => $t->identificador,
            'obligacion' => $o->identificador,
            'matricula_id' => $matricula->id,
            'estudiante' => ['id' => $e->id, 'dni' => $e->dni, 'nombre' => trim($e->apellidos.', '.$e->nombres)],
            'edicion' => $matricula->convocatoria?->codigo,
            'monto' => GestionPagos::formato(GestionPagos::leer($t->monto)),
            'metodo' => $t->metodo->nombre ?? '',
            'fecha_pago' => substr((string) $t->fecha_pago, 0, 10),
            'referencia' => $t->referencia,
            'estado' => $t->estado,
            'tiene_comprobante' => $t->comprobante !== null,
            'tipo_comprobante' => self::tipoComprobante($t),
            'imagen_repetida' => $repetida,
            'revertida' => $o->transacciones->contains(fn (TransaccionPago $x): bool => $x->reversa_de_id === $t->id),
            'total' => GestionPagos::formato($s['total']),
            'pagado' => GestionPagos::formato($s['confirmado']),
            'por_confirmar' => GestionPagos::formato($s['pendiente']),
            'saldo' => GestionPagos::formato(max(0, $s['total'] - $s['confirmado'] - $s['pendiente'])),
        ];
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
        $filas = array_values($coleccion->take(self::LIMITE_EXPORTACION)->map(fn (ObligacionPago $o): array => $this->resumen($o))->all());

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

    /**
     * Registra un pago sobre una matrícula. Si aún no tiene obligación vigente, el servidor la crea (pago completo por el precio del
     * curso). La respuesta incluye el identificador del pago nuevo para adjuntarle la captura del comprobante.
     */
    public function registrarDeMatricula(Request $request, int $matricula): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'metodo' => ['required', 'string', 'max:20'],
            'monto' => ['required'],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
            'referencia' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'confirmar' => ['nullable', 'boolean'],
        ], ['metodo.required' => 'Selecciona el método de pago.', 'monto.required' => 'Indica el importe.', 'fecha_pago.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.']);
        [$t, $o, $creada] = $this->gestion->registrarPagoDeMatricula($request, $matricula, $datos, $request->header('Idempotency-Key'));

        return $this->json([
            'message' => $creada ? 'El pago se registró.' : 'Este pago ya estaba registrado.',
            'transaccion' => $t->identificador,
            'data' => $this->detalle($this->buscar($o->identificador)),
        ], $creada ? 201 : 200);
    }

    /** Regulariza en 1 clic una matrícula sin obligación vigente, generando su deuda por el precio del curso. */
    public function generarObligacion(Request $request, int $matricula): JsonResponse
    {
        [$o, $creada] = $this->gestion->generarObligacionAutomatica($request, $matricula);

        return $this->json([
            'message' => $creada ? 'Se generó la obligación de cobro.' : 'Esta matrícula ya tenía una obligación vigente.',
            'data' => $this->detalle($this->buscar($o->identificador)),
        ], $creada ? 201 : 200);
    }

    /** Adjunta la captura del comprobante (campo `imagen`, JPG/PNG/WebP de hasta 3 MB) a un pago por confirmar. */
    public function adjuntarComprobante(Request $request, string $transaccion): JsonResponse
    {
        $request->validate(['imagen' => ComprobantesPago::reglas(permitirPdf: true)], [
            'imagen.required' => 'Adjunta el comprobante de pago.',
            'imagen.mimes' => 'Sube una captura en JPG, PNG o WebP, o un PDF.',
            'imagen.mimetypes' => 'Sube una captura en JPG, PNG o WebP, o un PDF.',
            'imagen.max' => 'El comprobante no puede pasar de 3 MB.',
        ]);
        $t = $this->gestion->adjuntarComprobante($request, $transaccion, $request->file('imagen'));

        return $this->json([
            'message' => 'El comprobante se adjuntó.',
            // Solo avisa: la misma captura en otro pago vigente suele ser un error o un cobro duplicado; decide quien confirma.
            'advertencias' => ['imagen_repetida' => $this->gestion->imagenesRepetidas([$t->id])[$t->id] ?? null],
            'data' => $this->detalle($t->obligacion()->firstOrFail()),
        ]);
    }

    /** Sirve la captura con su tipo real; solo con permiso, sin caché y sin posibilidad de ejecutarse en el navegador. */
    public function verComprobante(Request $request, string $transaccion): Response
    {
        $c = $this->gestion->leerComprobante($request, $transaccion);
        // Una captura se muestra en línea; un PDF SOLO se descarga (nunca se abre en el navegador: puede traer contenido activo).
        $disposicion = $c['tipo'] === ComprobantesPago::PDF ? 'attachment; filename="comprobante-de-pago.pdf"' : 'inline';

        return response($c['bytes'], 200, [
            'Content-Type' => $c['tipo'], 'Content-Disposition' => $disposicion, 'X-Content-Type-Options' => 'nosniff',
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

    /** `imagen`, `pdf` o `null` (sin comprobante). El navegador lo usa para elegir entre mostrar la captura o descargar el PDF. */
    private static function tipoComprobante(TransaccionPago $t): ?string
    {
        $c = $t->comprobante;

        return $c === null ? null : ($c->mime === ComprobantesPago::PDF ? 'pdf' : 'imagen');
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
        $matricula = $o->matricula;
        if ($matricula === null) {
            throw new RuntimeException("La obligación {$o->identificador} no tiene matrícula asociada.");
        }
        $e = $matricula->estudiante;
        if ($e === null) {
            throw new RuntimeException("La matrícula {$matricula->id} no tiene estudiante asociado.");
        }
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
            'matricula_id' => $matricula->id,
            'estudiante' => [
                'id' => $e->id,
                'dni' => $e->dni,
                'nombre' => trim($e->apellidos.', '.$e->nombres),
                'telefono' => $e->telefono,
                'email' => $e->email,
            ],
            'edicion' => $matricula->convocatoria?->codigo,
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
        $repetidas = $this->gestion->imagenesRepetidas(array_values($o->transacciones->where('tipo', 'pago')->map(fn (TransaccionPago $t): int => $t->id)->all()));

        return [
            ...$this->resumen($o),
            'motivo_anulacion' => $o->motivo_anulacion,
            'cuotas' => $o->cuotas->map(function ($c) use ($pagado): array {
                $monto = GestionPagos::leer($c->monto);
                $p = $pagado[$c->id] ?? 0;

                return ['numero' => $c->numero, 'monto' => GestionPagos::formato($monto), 'vence' => substr((string) $c->vence, 0, 10), 'pagado' => GestionPagos::formato($p), 'saldo' => GestionPagos::formato(max(0, $monto - $p))];
            })->values()->all(),
            'transacciones' => $o->transacciones->map(fn (TransaccionPago $t): array => [
                'imagen_repetida' => $repetidas[$t->id] ?? null,
                'identificador' => $t->identificador,
                'tipo' => $t->tipo,
                'estado' => $t->estado,
                'monto' => GestionPagos::formato(GestionPagos::leer($t->monto)),
                'metodo' => $t->metodo->nombre ?? '',
                'fecha_pago' => substr((string) $t->fecha_pago, 0, 10),
                'referencia' => $t->referencia,
                'motivo' => $t->motivo,
                'reversa_de' => $t->reversaDe?->identificador,
                'tiene_comprobante' => $t->comprobante !== null,
                'tipo_comprobante' => self::tipoComprobante($t),
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
