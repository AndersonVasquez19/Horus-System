<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAprobacionRequest;
use App\Http\Requests\UpdateAprobacionRequest;
use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\GestionAprobaciones;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\ListFilter;
use App\Support\Panel\ExportacionListado;
use App\Support\Panel\Listado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Aprobaciones internas para los dos paneles. Toda escritura pasa por {@see GestionAprobaciones} (transacción, bloqueo de
 * la fila, estado releído bajo bloqueo, autoridad por tipo, sin autoaprobación y auditoría como última escritura): este
 * controlador no decide ninguna regla. Los permisos por acción son los del Blade (`aprobaciones.ver|gestionar`) y las
 * acciones que el usuario puede hacer sobre cada solicitud viajan en la respuesta para que la interfaz no ofrezca las que
 * el servidor rechazaría. Respuestas sin caché: incluyen comentarios y motivos internos.
 */
final class AprobacionController extends Controller implements HasMiddleware
{
    private const LIMITE_EXPORTACION = 5000;

    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly GestionAprobaciones $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:aprobaciones.ver', only: ['index', 'exportar']),
            new Middleware('permission:aprobaciones.gestionar', only: ['store', 'update', 'destroy', 'aprobar', 'rechazar', 'reabrir']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$consulta, $filtros] = $this->consultaFiltrada($request);

        $paginas = Listado::paginar($consulta, $request);
        $usuario = $this->usuario($request);
        /** @var array<int, int> $ids */
        $ids = collect($paginas->items())->map(fn (AprobacionInterna $a): int => (int) $a->id)->all();
        $historiales = AprobacionInterna::historialesDe($ids);

        return Listado::responder(
            $paginas,
            fn (AprobacionInterna $a): array => $this->representar($a, $usuario, array_values($historiales->get($a->id, collect())->all())),
            'pendientes',
            $filtros,
        )->withHeaders(self::CABECERAS);
    }

    public function exportar(Request $request): StreamedResponse|BinaryFileResponse
    {
        [$consulta, $filtros] = $this->consultaFiltrada($request);
        $filas = $consulta->limit(self::LIMITE_EXPORTACION)->get()->map(fn (AprobacionInterna $a): array => [
            $a->id,
            $a->titulo,
            $a->tipo,
            ucfirst((string) $a->estado),
            $a->solicitante?->name,
            $a->aprobador?->name,
            $a->referencia,
            $a->created_at?->format('Y-m-d H:i'),
        ]);

        return ExportacionListado::responder(
            'aprobaciones',
            'Aprobaciones',
            ExportacionListado::formato($request),
            ['ID', 'Título', 'Tipo', 'Estado', 'Solicitante', 'Aprobador', 'Referencia', 'Creada'],
            $filas,
            'aprobaciones_exportadas',
            AprobacionInterna::class,
            AuditoriaAcademica::actorDe($request),
            ['estado' => $filtros['estado'] ?: null, 'tipo' => $filtros['tipo'] ?: null, 'alcance' => $filtros['alcance'] ?: null, 'con_busqueda' => $filtros['busqueda'] !== ''],
        );
    }

    /** @return array{0: Builder<AprobacionInterna>, 1: array{estado: string, tipo: string, alcance: string, busqueda: string}} */
    private function consultaFiltrada(Request $request): array
    {
        $usuario = $this->usuario($request);
        $filtros = [
            'estado' => ListFilter::in($request, 'estado', AprobacionInterna::ESTADOS),
            'tipo' => ListFilter::in($request, 'tipo', AprobacionInterna::TIPOS_PERMITIDOS),
            'alcance' => ListFilter::in($request, 'alcance', ['para_mi', 'mias', 'todas']),
            'busqueda' => ListFilter::text($request, 'busqueda'),
        ];

        $consulta = AprobacionInterna::query()->with(['solicitante:id,name', 'aprobador:id,name']);
        if ($filtros['alcance'] === 'mias') {
            $consulta->where('solicitante_id', $usuario->id);
        } elseif ($filtros['alcance'] === 'para_mi') {
            $tiposAutorizados = array_keys(array_filter(
                AprobacionInterna::TIPOS_AUTORIDAD,
                fn (string $rol): bool => $usuario->hasRole($rol),
            ));
            $consulta->where('estado', AprobacionInterna::ESTADO_PENDIENTE)
                ->where('solicitante_id', '!=', $usuario->id);
            if ($tiposAutorizados === []) {
                $consulta->whereRaw('1 = 0');
            } else {
                $consulta->whereIn('tipo', $tiposAutorizados);
            }
        }
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        if ($filtros['tipo'] !== '') {
            $consulta->where('tipo', $filtros['tipo']);
        }
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['titulo', 'tipo', 'descripcion', 'comentario', 'referencia'], $filtros['busqueda']);
        }
        // Primero lo pendiente, luego lo resuelto; dentro de cada grupo, lo más reciente.
        $consulta->orderByRaw("CASE estado WHEN 'pendiente' THEN 1 WHEN 'aprobado' THEN 2 WHEN 'rechazado' THEN 3 ELSE 4 END")
            ->latest('created_at')->orderByDesc('id');

        return [$consulta, $filtros];
    }

    public function store(StoreAprobacionRequest $request): JsonResponse
    {
        $aprobacion = $this->gestion->registrar($this->usuario($request), $this->datosRegistro($request->validated()));

        return $this->respuesta($aprobacion, $request, 'La solicitud de aprobación se registró correctamente.', 201);
    }

    public function update(UpdateAprobacionRequest $request, AprobacionInterna $aprobacion): JsonResponse
    {
        $actualizada = $this->gestion->actualizar($this->usuario($request), $aprobacion->id, $this->datosActualizacion($request->validated()));

        return $this->respuesta($actualizada, $request, 'La solicitud de aprobación se actualizó correctamente.');
    }

    public function destroy(Request $request, AprobacionInterna $aprobacion): JsonResponse
    {
        $this->gestion->eliminar($this->usuario($request), $aprobacion->id);

        return response()->json(['message' => 'La solicitud de aprobación se eliminó correctamente.'], 200, self::CABECERAS);
    }

    public function aprobar(Request $request, AprobacionInterna $aprobacion): JsonResponse
    {
        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:1000']]);
        $resuelta = $this->gestion->aprobar($this->usuario($request), $aprobacion->id, $datos['comentario'] ?? null);

        return $this->respuesta($resuelta, $request, 'La solicitud ha sido aprobada correctamente.');
    }

    public function rechazar(Request $request, AprobacionInterna $aprobacion): JsonResponse
    {
        $datos = $request->validate($this->reglasMotivo(), $this->mensajesMotivo('rechazo'));
        $resuelta = $this->gestion->rechazar($this->usuario($request), $aprobacion->id, trim($datos['motivo']));

        return $this->respuesta($resuelta, $request, 'La solicitud ha sido rechazada.');
    }

    public function reabrir(Request $request, AprobacionInterna $aprobacion): JsonResponse
    {
        $datos = $request->validate($this->reglasMotivo(), $this->mensajesMotivo('reapertura'));
        $abierta = $this->gestion->reabrir($this->usuario($request), $aprobacion->id, trim($datos['motivo']));

        return $this->respuesta($abierta, $request, 'La solicitud ha sido reabierta para una nueva evaluación.');
    }

    private function respuesta(AprobacionInterna $aprobacion, Request $request, string $mensaje, int $estado = 200): JsonResponse
    {
        $actual = AprobacionInterna::query()->with(['solicitante:id,name', 'aprobador:id,name'])->findOrFail($aprobacion->id);
        $historial = AprobacionInterna::historialesDe([(int) $actual->id])->get($actual->id, collect())->all();

        return response()->json(
            ['message' => $mensaje, 'data' => $this->representar($actual, $this->usuario($request), array_values($historial))],
            $estado,
            self::CABECERAS,
        );
    }

    /**
     * Lista cerrada de campos. Las banderas `puede_*` usan las mismas reglas que el servidor aplica al ejecutar.
     *
     * @param  list<AuditLog>  $historial
     * @return array<string, mixed>
     */
    private function representar(AprobacionInterna $a, User $usuario, array $historial): array
    {
        $pendiente = $a->estado === AprobacionInterna::ESTADO_PENDIENTE;
        $propia = $a->esSolicitante($usuario);

        return [
            'id' => $a->id,
            'titulo' => $a->titulo,
            'tipo' => $a->tipo,
            'estado' => $a->estado,
            'descripcion' => $a->descripcion,
            'comentario' => $a->comentario,
            'referencia' => $a->referencia,
            'rol_autorizado' => $a->rolAutorizado(),
            'solicitante' => $a->solicitante === null ? null : ['id' => $a->solicitante->id, 'nombre' => $a->solicitante->name],
            'aprobador' => $a->aprobador === null ? null : ['id' => $a->aprobador->id, 'nombre' => $a->aprobador->name],
            'creada_local' => HoraLocal::formato($a->created_at),
            'actualizada_local' => HoraLocal::formato($a->updated_at),
            'es_propia' => $propia,
            'puede_editar' => $pendiente && $usuario->can('aprobaciones.gestionar') && ($propia || $usuario->hasRole('admin')),
            'puede_resolver' => $pendiente && ! $propia && $a->puedeResolver($usuario),
            'puede_reabrir' => $a->puedeReabrir($usuario),
            'puede_eliminar' => $pendiente && $usuario->can('aprobaciones.gestionar') && ($propia || $usuario->hasRole('admin')),
            'historial' => array_map(function (AuditLog $evento): array {
                $cambios = $evento->redactedChanges();

                return [
                    'accion' => $evento->action,
                    'usuario' => $evento->user?->name,
                    'fecha_local' => HoraLocal::formato($evento->created_at, 'd/m/Y H:i:s'),
                    'estado_anterior' => is_string($cambios['estado_anterior'] ?? null) ? $cambios['estado_anterior'] : null,
                    'estado_nuevo' => is_string($cambios['estado_nuevo'] ?? null) ? $cambios['estado_nuevo'] : null,
                    'motivo' => is_string($cambios['motivo'] ?? null) ? $cambios['motivo'] : null,
                    'comentario' => is_string($cambios['comentario'] ?? null) ? $cambios['comentario'] : null,
                ];
            }, $historial),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{titulo: string, tipo: string, descripcion?: string|null, comentario?: string|null, referencia?: string|null}
     */
    private function datosRegistro(array $data): array
    {
        $datos = ['titulo' => Entrada::texto($data['titulo']), 'tipo' => Entrada::texto($data['tipo'])];
        foreach (['descripcion', 'comentario', 'referencia'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $datos[$campo] = $data[$campo] === null ? null : Entrada::texto($data[$campo]);
            }
        }

        return $datos;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{titulo: string, descripcion?: string|null, comentario?: string|null, referencia?: string|null}
     */
    private function datosActualizacion(array $data): array
    {
        $datos = ['titulo' => Entrada::texto($data['titulo'])];
        foreach (['descripcion', 'comentario', 'referencia'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $datos[$campo] = $data[$campo] === null ? null : Entrada::texto($data[$campo]);
            }
        }

        return $datos;
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 401);

        return $usuario;
    }

    /** @return array<string, list<string>> */
    private function reglasMotivo(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:1000']];
    }

    /** @return array<string, string> */
    private function mensajesMotivo(string $operacion): array
    {
        return [
            'motivo.required' => "El motivo de {$operacion} es obligatorio.",
            'motivo.min' => "El motivo de {$operacion} debe contener al menos 3 caracteres.",
            'motivo.max' => "El motivo de {$operacion} no puede superar los 1000 caracteres.",
        ];
    }
}
