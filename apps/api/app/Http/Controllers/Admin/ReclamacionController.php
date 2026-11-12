<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GestionarReclamacionRequest;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use App\Services\GestionReclamaciones;
use App\Support\HojaReclamacion;
use App\Support\Reclamaciones;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bandeja administrativa del Libro de Reclamaciones: consulta e impresión (E3) y gestión del caso (E4).
 *
 * Permite a Gerencia y personal con permiso `reclamaciones.ver` consultar las quejas
 * y reclamos recibidos, aplicar filtros por tipo, estado, fechas y texto, revisar
 * el detalle completo de cada registro (con su historial y envíos) e imprimir la hoja.
 * Ninguna de estas peticiones GET modifica reclamaciones: la única escritura es la auditoría del acceso.
 *
 * La gestión (`gestionar`) solo delega en {@see GestionReclamaciones}, que comprueba permiso, estado y reglas
 * bajo bloqueo y deja historial y auditoría. Este controlador no decide reglas del libro.
 */
class ReclamacionController extends Controller implements HasMiddleware
{
    /** Mensaje de éxito por operación de gestión. */
    private const MENSAJES = [
        'responsable' => 'Responsable actualizado.',
        'tipo' => 'Tipo de registro actualizado.',
        'nota' => 'Nota interna registrada.',
        'propuesta' => 'Propuesta de solución registrada.',
        'rechazo' => 'Rechazo de la propuesta registrado; el plazo se reanudó.',
        'aceptacion' => 'Aceptación de la propuesta registrada; el caso concluyó por acuerdo.',
        'respuesta' => 'Respuesta formal registrada.',
        'reintento' => 'Envío reprogramado.',
    ];

    public function __construct(private readonly GestionReclamaciones $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reclamaciones.ver', only: ['index', 'show', 'hoja']),
            // Permiso fino por operación: GestionarReclamacionRequest::authorize() y el propio servicio.
            new Middleware('permission:reclamaciones.gestionar|reclamaciones.responder', only: ['gestionar']),
        ];
    }

    public function index(Request $request): Response
    {
        /** @var array{busqueda?: string|null, tipo?: string|null, estado?: string|null, desde?: string|null, hasta?: string|null, page?: int|null} $filtros */
        $filtros = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', Rule::in(['queja', 'reclamo'])],
            'estado' => ['nullable', 'string', Rule::in(['abierto', 'respondido', 'concluido_por_acuerdo'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $consulta = Reclamacion::query();

        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));
        if ($busqueda !== '') {
            $termino = '%'.strtr($busqueda, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            // Escape explícito compartido: SQLite no define el escape implícito de PostgreSQL.
            // Operador y columnas son SQL fijo; el texto personal siempre viaja como parámetro.
            $operador = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $consulta->where(function ($q) use ($termino, $operador) {
                $q->whereRaw("numero {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("nombres {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("apellidos {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("(nombres || ' ' || apellidos) {$operador} ? ESCAPE '!'", [$termino]);
            });
        }

        if (! empty($filtros['tipo'])) {
            $consulta->where('tipo', $filtros['tipo']);
        }

        if (! empty($filtros['estado'])) {
            $consulta->where('estado', $filtros['estado']);
        }

        if (! empty($filtros['desde'])) {
            $consulta->where('recibida_at', '>=', CarbonImmutable::parse($filtros['desde'], 'America/Lima')->startOfDay()->utc());
        }

        if (! empty($filtros['hasta'])) {
            $consulta->where('recibida_at', '<=', CarbonImmutable::parse($filtros['hasta'], 'America/Lima')->endOfDay()->utc());
        }

        $reclamaciones = $consulta->orderByDesc('id')->paginate(15)->withQueryString();

        $metricas = Reclamacion::query()
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN tipo = 'queja' THEN 1 END) as total_quejas,
                COUNT(CASE WHEN tipo = 'reclamo' THEN 1 END) as total_reclamos,
                COUNT(CASE WHEN estado = 'abierto' THEN 1 END) as total_abiertos
            ")
            ->first();

        // Constancia del acceso a la bandeja: solo los nombres de los filtros usados y la página, nunca sus valores.
        $usados = array_values(array_filter(
            ['busqueda', 'tipo', 'estado', 'desde', 'hasta'],
            fn (string $filtro): bool => trim((string) ($filtros[$filtro] ?? '')) !== '',
        ));
        $this->gestion->registrarConsultaBandeja($this->usuario($request), $usados, $reclamaciones->currentPage());

        return response()->view('admin.reclamaciones.index', [
            'reclamaciones' => $reclamaciones,
            'filtros' => $filtros,
            'metricas' => [
                'total' => (int) ($metricas?->getAttribute('total') ?? 0),
                'quejas' => (int) ($metricas?->getAttribute('total_quejas') ?? 0),
                'reclamos' => (int) ($metricas?->getAttribute('total_reclamos') ?? 0),
                'abiertos' => (int) ($metricas?->getAttribute('total_abiertos') ?? 0),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, Reclamacion $reclamacion): Response
    {
        $registro = $this->gestion->consultar($this->usuario($request), $reclamacion->identificador);

        return response()->view('admin.reclamaciones.show', [
            'reclamacion' => $registro,
            // Indicadores de solo lectura: se calculan para mostrarlos, sin persistir nada.
            'avisoPlazo' => $this->gestion->umbralAviso($registro),
            'propuestaVencida' => $this->gestion->propuestaVencidaSinProcesar($registro),
            'responsables' => $this->responsablesDisponibles(),
            'correoActivo' => Reclamaciones::envioHabilitado(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Operaciones de gestión (E4). Un 409 del servicio (el caso ya no admite la operación) vuelve al detalle con
     * el mensaje fijo del servicio, que no contiene datos personales; 403 y 404 siguen su curso normal.
     */
    public function gestionar(GestionarReclamacionRequest $request, Reclamacion $reclamacion): RedirectResponse|JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        $operacion = (string) $datos['operacion'];
        $actor = $this->usuario($request);
        $uuid = (string) $reclamacion->identificador;

        try {
            match ($operacion) {
                'responsable' => $this->gestion->asignarResponsable(
                    $actor, $uuid, $datos['responsable_id'] === null ? null : (int) $datos['responsable_id']),
                'tipo' => $this->gestion->reclasificarTipo($actor, $uuid, (string) $datos['tipo']),
                'nota' => $this->gestion->agregarNota($actor, $uuid, (string) $datos['nota']),
                'propuesta' => $this->gestion->enviarPropuesta($actor, $uuid, (string) $datos['asunto'], (string) $datos['cuerpo']),
                'rechazo' => $this->gestion->registrarRechazo(
                    $actor, $uuid, (string) $datos['fecha_recepcion'], isset($datos['nota']) ? (string) $datos['nota'] : null),
                'aceptacion' => $this->gestion->registrarAceptacion(
                    $actor, $uuid, (string) $datos['fecha_recepcion'], (string) $datos['medio'], (string) $datos['constancia']),
                'respuesta' => $this->gestion->registrarRespuesta(
                    $actor, $uuid, (string) $datos['respuesta'], (string) $datos['canal'],
                    isset($datos['constancia']) ? (string) $datos['constancia'] : null),
                'reintento' => $this->gestion->reintentarEnvio($actor, $this->envioDeLaReclamacion($reclamacion, (int) $datos['envio_id'])),
                // El request ya rechaza una operación desconocida (403); nunca se ejecuta otra cosa.
                default => abort(403),
            };
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return redirect()->route('admin.reclamaciones.show', $reclamacion)
                ->withErrors(['gestion' => $e->getMessage()])
                ->withInput();
        }

        $mensaje = self::MENSAJES[$operacion];
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje]);
        }

        return redirect()->route('admin.reclamaciones.show', $reclamacion)->with('success', $mensaje);
    }

    /** Un envío solo se reintenta desde la reclamación a la que pertenece: si no, 404 sin tocar nada. */
    private function envioDeLaReclamacion(Reclamacion $reclamacion, int $envioId): int
    {
        abort_unless(
            ReclamacionEnvio::query()->whereKey($envioId)->where('reclamacion_id', $reclamacion->getKey())->exists(),
            404,
        );

        return $envioId;
    }

    /**
     * Personal del panel con `reclamaciones.ver`: el mismo criterio con el que el servicio valida la asignación.
     * Solo id y nombre.
     *
     * @return list<array{id: int, name: string}>
     */
    private function responsablesDisponibles(): array
    {
        if (! Permission::query()->where('name', 'reclamaciones.ver')->where('guard_name', 'web')->exists()) {
            return [];
        }

        return User::permission('reclamaciones.ver')
            ->role(RolesAndPermissionsSeeder::STAFF_ROLES)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
            ->all();
    }

    /** Hoja de reclamación imprimible: el texto oficial es el de {@see HojaReclamacion} (el mismo de la copia al consumidor). */
    public function hoja(Request $request, Reclamacion $reclamacion): Response
    {
        $registro = $this->gestion->imprimir($this->usuario($request), $reclamacion->identificador);

        return response()->view('admin.reclamaciones.hoja', [
            'reclamacion' => $registro,
            'hoja' => HojaReclamacion::cuerpo($registro),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function usuario(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
