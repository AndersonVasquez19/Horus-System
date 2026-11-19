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
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use App\Support\TemporaryExportFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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
    public function __construct(private readonly GestionPagos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:pagos.ver', only: ['metodos', 'index', 'show', 'deMatricula', 'verComprobante']),
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
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', ['pendiente', 'parcial', 'pagada', 'anulada']),
        ];
        $consulta = ObligacionPago::query()->with(['matricula.estudiante', 'matricula.convocatoria']);
        if ($filtros['busqueda'] !== '') {
            $consulta->whereHas('matricula.estudiante', fn ($q) => BusquedaLike::contiene($q, ['dni', 'nombres', 'apellidos'], $filtros['busqueda']));
        }
        if ($filtros['estado'] === 'anulada') {
            $consulta->where('estado', 'anulada');
        } elseif ($filtros['estado'] !== '') {
            $consulta->where('estado', 'vigente');
        }
        $orden = Listado::ordenar($consulta, $request, ['creado' => 'created_at'], '-creado');
        $paginas = Listado::paginar($consulta, $request);
        if ($filtros['estado'] !== '' && $filtros['estado'] !== 'anulada') {
            // El estado de cobro se deriva del libro: se filtra sobre la página, así que el total es aproximado solo con este filtro.
            $paginas->setCollection($paginas->getCollection()->filter(fn (ObligacionPago $o): bool => $this->estadoCobro($o) === $filtros['estado'])->values());
        }

        return Listado::responder($paginas, fn (ObligacionPago $o): array => $this->resumen($o), $orden, $filtros)->header('Cache-Control', 'no-store, private');
    }

    /** Excel de las obligaciones (hasta 5000), sin conservarlo en el servidor y con constancia de acceso sin datos de personas. */
    public function exportar(Request $request): BinaryFileResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $filas = ObligacionPago::query()->with(['matricula.estudiante', 'matricula.convocatoria'])->orderBy('id')->limit(5000)->get()
            ->map(fn (ObligacionPago $o): array => $this->resumen($o))->all();

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
            AuditLog::record('pagos_exportados', ObligacionPago::class, null, ['filename' => $nombre, 'obligaciones' => count($filas)], $usuario->id);

            return response()->download($temporal, $nombre, ['Cache-Control' => 'no-store, private'])->deleteFileAfterSend(true);
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

    private function estadoCobro(ObligacionPago $o): string
    {
        if ($o->estado === 'anulada') {
            return 'anulada';
        }
        $s = $this->gestion->saldos($o);

        return $s['confirmado'] <= 0 ? 'pendiente' : ($s['confirmado'] >= $s['total'] ? 'pagada' : 'parcial');
    }

    /** @return array<string, mixed> */
    private function resumen(ObligacionPago $o): array
    {
        $s = $this->gestion->saldos($o);
        $e = $o->matricula->estudiante;

        return [
            'identificador' => $o->identificador,
            'matricula_id' => $o->matricula->id,
            'estudiante' => ['dni' => $e->dni, 'nombre' => trim($e->apellidos.', '.$e->nombres)],
            'edicion' => $o->matricula->convocatoria?->codigo,
            'concepto' => $o->concepto,
            'plan' => $o->plan,
            'estado' => $this->estadoCobro($o),
            'moneda' => $o->moneda,
            'monto_total' => GestionPagos::formato($s['total']),
            'pagado' => GestionPagos::formato($s['confirmado']),
            'pendiente_confirmar' => GestionPagos::formato($s['pendiente']),
            'saldo' => GestionPagos::formato(max(0, $s['total'] - $s['confirmado'])),
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
