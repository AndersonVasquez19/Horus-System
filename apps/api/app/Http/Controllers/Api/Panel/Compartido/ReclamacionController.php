<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Http\Requests\GestionarReclamacionRequest;
use App\Models\Estudiante;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\ReclamacionHistorial;
use App\Models\User;
use App\Services\GestionReclamaciones;
use App\Services\OperacionesReclamacion;
use App\Support\AuditoriaAcademica;
use App\Support\ConsultaReclamaciones;
use App\Support\Entrada;
use App\Support\HojaReclamacion;
use App\Support\Panel\ExportacionListado;
use App\Support\Panel\Listado;
use App\Support\PresentacionReclamacion;
use App\Support\Reclamaciones;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Libro de Reclamaciones para los dos paneles: bandeja, expediente, hoja imprimible y gestión del caso. Mismos
 * servicios, filtros y constancias de acceso que el Blade (`GestionReclamaciones`, `ConsultaReclamaciones`,
 * `OperacionesReclamacion`): este controlador no decide ninguna regla del libro.
 *
 * Contienen datos personales: toda respuesta va con `Cache-Control: no-store, private`. La representación es una lista
 * cerrada: nunca el token de envío, cuerpos de correo, claves de idempotencia ni identificadores del proveedor, y el
 * destinatario de un correo se muestra enmascarado. El identificador público es el UUID; el id interno no sale.
 */
final class ReclamacionController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['recibida' => 'recibida_at', 'limite' => 'fecha_limite', 'numero' => 'numero'];

    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    private const ZONA = 'America/Lima';

    public function __construct(
        private readonly GestionReclamaciones $gestion,
        private readonly OperacionesReclamacion $operaciones,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reclamaciones.ver', only: ['index', 'show', 'hoja', 'exportar']),
            new Middleware('permission:reclamaciones.gestionar', only: ['asignarLote']),
            // Permiso fino por operación: GestionarReclamacionRequest::authorize() y el propio servicio.
            new Middleware('permission:reclamaciones.gestionar|reclamaciones.responder', only: ['gestionar']),
        ];
    }

    public function exportar(Request $request): StreamedResponse|BinaryFileResponse
    {
        $filtros = ConsultaReclamaciones::validar($request);
        $consulta = Reclamacion::query();
        ConsultaReclamaciones::aplicar($consulta, $filtros, (int) $this->usuario($request)->id);
        Listado::ordenar($consulta, $request, self::ORDENABLES, '-recibida');

        $filas = $consulta->cursor()->map(fn (Reclamacion $r): array => [
            $r->numero,
            ucfirst((string) $r->tipo),
            ucfirst((string) $r->estado),
            trim($r->nombres.' '.$r->apellidos),
            $this->local($r->recibida_at),
            $r->fecha_limite->format('Y-m-d'),
            $r->suspension_desde !== null ? 'Sí' : 'No',
        ]);

        return ExportacionListado::responder(
            'reclamaciones',
            'Reclamaciones',
            ExportacionListado::formato($request),
            ['Código', 'Tipo', 'Estado', 'Consumidor', 'Recibida', 'Fecha límite', 'Plazo suspendido'],
            $filas,
            'reclamaciones_exportadas',
            Reclamacion::class,
            AuditoriaAcademica::actorDe($request),
            ['filtros' => ConsultaReclamaciones::usados($filtros)],
        );
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = ConsultaReclamaciones::validar($request);
        $consulta = Reclamacion::query();
        ConsultaReclamaciones::aplicar($consulta, $filtros, (int) $this->usuario($request)->id);
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-recibida');
        $paginas = Listado::paginar($consulta, $request);

        // Constancia del acceso a la bandeja: solo los nombres de los filtros usados y la página, nunca sus valores.
        $this->gestion->registrarConsultaBandeja($this->usuario($request), ConsultaReclamaciones::usados($filtros), $paginas->currentPage());

        $respuesta = Listado::responder(
            $paginas,
            fn (Reclamacion $r): array => [
                'id' => $r->identificador,
                'numero' => $r->numero,
                'tipo' => $r->tipo,
                'estado' => $r->estado,
                'consumidor' => trim($r->nombres.' '.$r->apellidos),
                'recibida_local' => $this->local($r->recibida_at),
                'fecha_limite' => $r->fecha_limite->format('Y-m-d'),
                'plazo_suspendido' => $r->suspension_desde !== null,
            ],
            $orden,
            array_filter(array_intersect_key($filtros, array_flip(ConsultaReclamaciones::FILTROS)), fn ($v) => trim(Entrada::texto($v)) !== ''),
        );

        $datos = Listado::contenido($respuesta);
        $datos['meta']['metricas'] = ConsultaReclamaciones::metricas();
        $datos['meta']['plazos'] = ConsultaReclamaciones::plazos();
        $datos['meta']['responsables'] = $this->operaciones->responsablesDisponibles();

        return response()->json($datos, 200, self::CABECERAS);
    }

    public function show(Request $request, Reclamacion $reclamacion): JsonResponse
    {
        $actor = $this->usuario($request);
        $r = $this->gestion->consultar($actor, $reclamacion->identificador);

        $puedeGestionar = $actor->can('reclamaciones.gestionar');
        $dni = trim((string) $r->numero_documento);
        $estudianteRelacionado = null;
        if ($actor->can('estudiantes.ver') && preg_match('/\A\d{8}\z/', $dni) === 1) {
            $e = Estudiante::query()->where('dni', $dni)->first(['id', 'nombres', 'apellidos']);
            if ($e instanceof Estudiante) {
                $estudianteRelacionado = ['id' => (int) $e->id, 'nombre' => trim($e->nombres.' '.$e->apellidos)];
            }
        }

        return response()->json([
            'data' => $this->expediente($r),
            'meta' => [
                // Indicadores de solo lectura: se calculan para mostrarlos, sin persistir nada.
                'aviso_plazo' => $this->gestion->umbralAviso($r),
                'propuesta_vencida' => $this->gestion->propuestaVencidaSinProcesar($r),
                'correo_activo' => Reclamaciones::envioHabilitado(),
                'responsables' => $this->operaciones->responsablesDisponibles(),
                'puede_gestionar' => $puedeGestionar,
                'puede_responder' => $actor->can('reclamaciones.responder'),
                'estudiante_relacionado' => $estudianteRelacionado,
            ] + $this->gestion->operacionesDisponibles($actor, $r),
        ], 200, self::CABECERAS);
    }

    /** Hoja imprimible: el texto oficial es el de {@see HojaReclamacion} (el mismo de la copia al consumidor). */
    public function hoja(Request $request, Reclamacion $reclamacion): JsonResponse
    {
        $r = $this->gestion->imprimir($this->usuario($request), $reclamacion->identificador);

        return response()->json(['data' => [
            'id' => $r->identificador,
            'numero' => $r->numero,
            'tipo' => $r->tipo,
            'canal' => $r->canal,
            'canal_etiqueta' => $r->canal === 'respaldo'
                ? 'Libro físico de respaldo'.($r->numero_hoja_respaldo ? ' (hoja '.$r->numero_hoja_respaldo.')' : '')
                : 'Libro virtual',
            'texto_oficial' => HojaReclamacion::cuerpo($r),
            'respuesta' => $r->respuesta,
            'respondida_local' => $r->respondida_at === null ? null : $r->respondida_at->copy()->timezone(self::ZONA)->format('d/m/Y H:i'),
            'impresa_local' => now(self::ZONA)->format('d/m/Y H:i'),
        ]], 200, self::CABECERAS);
    }

    /** Un 409 del servicio (el caso ya no admite la operación) se devuelve con su mensaje fijo, sin datos personales. */
    public function gestionar(GestionarReclamacionRequest $request, Reclamacion $reclamacion): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        try {
            $mensaje = $this->operaciones->ejecutar($this->usuario($request), $reclamacion, $datos);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], 409, self::CABECERAS);
        }

        return response()->json(['message' => $mensaje], 200, self::CABECERAS);
    }

    /** Asigna o retira responsable en lote sobre casos abiertos (409 si todos los seleccionados ya están cerrados). */
    public function asignarLote(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'identificadores' => ['required', 'array', 'min:1', 'max:50'],
            'identificadores.*' => ['required', 'uuid'],
            'responsable_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $actor = $this->usuario($request);
        $casos = Reclamacion::query()
            ->whereIn('identificador', array_map('strtolower', $datos['identificadores']))
            ->get();
        abort_if($casos->isEmpty(), 404, 'No se encontraron las reclamaciones seleccionadas.');

        $actualizadas = 0;
        $ultimoConflicto = null;
        foreach ($casos as $caso) {
            try {
                $this->operaciones->ejecutar($actor, $caso, [
                    'operacion' => 'responsable',
                    'responsable_id' => $datos['responsable_id'] ?? null,
                ]);
                $actualizadas++;
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
                $ultimoConflicto = $e->getMessage();
            }
        }

        if ($actualizadas === 0 && $ultimoConflicto !== null) {
            return response()->json(['message' => $ultimoConflicto], 409, self::CABECERAS);
        }

        return response()->json([
            'message' => $actualizadas === 1
                ? 'Responsable actualizado en 1 reclamación.'
                : "Responsable actualizado en {$actualizadas} reclamaciones.",
            'actualizadas' => $actualizadas,
        ], 200, self::CABECERAS);
    }

    /** @return array<string, mixed> */
    private function expediente(Reclamacion $r): array
    {
        $responsable = $r->responsable_id === null ? null : User::query()->whereKey($r->responsable_id)->value('name');

        return [
            'id' => $r->identificador,
            'numero' => $r->numero,
            'tipo' => $r->tipo,
            'estado' => $r->estado,
            'canal' => $r->canal,
            'numero_hoja_respaldo' => $r->numero_hoja_respaldo,
            'canal_respuesta' => $r->canal_respuesta,
            'recibida_local' => $this->local($r->recibida_at),
            'fecha_limite' => $r->fecha_limite->format('Y-m-d'),
            'suspension_desde' => $r->suspension_desde?->format('Y-m-d'),
            'suspension_hasta' => $r->suspension_hasta?->format('Y-m-d'),
            'respondida_local' => $r->respondida_at === null ? null : $this->local($r->respondida_at),
            'respuesta' => $r->respuesta,
            'responsable' => $r->responsable_id === null ? null : ['id' => (int) $r->responsable_id, 'nombre' => $responsable === null ? null : Entrada::texto($responsable)],
            'consumidor' => [
                'nombres' => $r->nombres,
                'apellidos' => $r->apellidos,
                'tipo_documento' => $r->tipo_documento,
                'numero_documento' => $r->numero_documento,
                'domicilio' => $r->domicilio,
                'telefono' => $r->telefono,
                'email' => $r->email,
                'es_menor' => (bool) $r->es_menor,
                'representante' => $r->es_menor ? [
                    'nombre' => $r->representante_nombre,
                    'domicilio' => $r->representante_domicilio,
                    'telefono' => $r->representante_telefono,
                    'email' => $r->representante_email,
                ] : null,
            ],
            'bien' => ['tipo' => $r->bien_tipo, 'descripcion' => $r->bien_descripcion, 'monto' => $r->monto_bien],
            'detalle' => $r->detalle,
            'pedido' => $r->pedido,
            'historial' => $r->historial->map(fn (ReclamacionHistorial $h): array => $this->actuacion($h))->values()->all(),
            'envios' => $r->envios->map(fn (ReclamacionEnvio $e): array => $this->envio($r, $e))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function actuacion(ReclamacionHistorial $h): array
    {
        $transicion = PresentacionReclamacion::transicion($h);

        return [
            'evento' => $h->evento,
            'etiqueta' => PresentacionReclamacion::evento($h->evento),
            'fecha_local' => $this->local($h->created_at),
            'actor' => $h->actor_nombre,
            'transicion' => $transicion === null ? null : ['de' => $transicion[0], 'a' => $transicion[1]],
            'detalles' => PresentacionReclamacion::detalles($h),
            'nota' => $h->nota,
        ];
    }

    /** @return array<string, mixed> */
    private function envio(Reclamacion $r, ReclamacionEnvio $e): array
    {
        $fecha = $e->enviado_at ?? $e->created_at;

        return [
            'id' => $e->id,
            'tipo' => PresentacionReclamacion::tipoEnvio($e->tipo),
            'canal' => PresentacionReclamacion::canal($e->canal),
            'destinatario' => PresentacionReclamacion::destinatario($r, $e),
            'fecha_local' => $fecha === null ? null : $this->local($fecha),
            'fecha_es_de_envio' => $e->enviado_at !== null,
            'estado' => $e->estado,
            'estado_etiqueta' => PresentacionReclamacion::estadoEnvio($e->estado),
            'intentos' => (int) $e->intentos,
            'error_codigo' => $e->estado === 'fallido' ? $e->error_codigo : null,
            'constancia' => $e->canal === 'carta' ? $e->constancia : null,
            'reintentable' => $e->estado === 'fallido' && $e->canal === 'correo',
        ];
    }

    private function local(?\DateTimeInterface $fecha): string
    {
        if ($fecha === null) {
            return '—';
        }

        return CarbonImmutable::instance($fecha)->timezone(self::ZONA)->format('d/m/Y H:i');
    }

    private function usuario(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
