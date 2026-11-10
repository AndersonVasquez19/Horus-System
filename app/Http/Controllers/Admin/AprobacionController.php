<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AprobacionInterna;
use App\Models\User;
use App\Services\GestionAprobaciones;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AprobacionController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionAprobaciones $gestion) {}

    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     * La autoridad por tipo, la autoaprobación y el estado se comprueban en {@see GestionAprobaciones}.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:aprobaciones.ver', only: ['index', 'update', 'destroy']),
            new Middleware('permission:aprobaciones.gestionar', only: [
                'store', 'aprobar', 'rechazar', 'reabrir',
            ]),
        ];
    }

    public function index(Request $request): View
    {
        $query = AprobacionInterna::query()->with(['solicitante:id,name', 'aprobador:id,name']);

        $estado = ListFilter::text($request, 'estado');
        if ($estado !== '') {
            $query->where('estado', $estado);
        }

        $tipo = ListFilter::text($request, 'tipo');
        if ($tipo !== '') {
            $query->where('tipo', $tipo);
        }

        $busqueda = ListFilter::text($request, 'busqueda');
        if ($busqueda !== '') {
            BusquedaLike::contiene($query, ['titulo', 'tipo', 'descripcion', 'comentario', 'referencia'], $busqueda);
        }

        $aprobaciones = $query
            ->orderByRaw("CASE estado WHEN 'pendiente' THEN 1 WHEN 'aprobado' THEN 2 WHEN 'rechazado' THEN 3 ELSE 4 END")
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        /** @var array<int, int> $ids */
        $ids = $aprobaciones->getCollection()->map(fn (AprobacionInterna $a): int => (int) $a->id)->all();

        return view('admin.aprobaciones.index', [
            'aprobaciones' => $aprobaciones,
            'historiales' => AprobacionInterna::historialesDe($ids),
            'estado' => $estado,
            'tipo' => $tipo,
            'busqueda' => $busqueda,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', Rule::in(AprobacionInterna::TIPOS_PERMITIDOS)],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            // Se validan por compatibilidad con clientes previos, pero se ignoran: el solicitante es la sesión
            // y el aprobador solo se fija al decidir.
            'solicitante_id' => ['nullable', 'integer', 'exists:users,id'],
            'aprobador_id' => ['nullable', 'integer', 'exists:users,id'],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'in:pendiente,aprobado,rechazado'],
        ], [
            'tipo.in' => 'Selecciona un tipo de solicitud válido (compra u operativo).',
        ]);

        $this->gestion->registrar($this->actor($request), $data);

        return redirect()->route('admin.aprobaciones.index')->with('success', 'La solicitud de aprobación se registró correctamente.');
    }

    public function update(Request $request, AprobacionInterna $aprobacion): RedirectResponse|JsonResponse
    {
        $actor = $this->actor($request);
        abort_if($aprobacion->estado !== AprobacionInterna::ESTADO_PENDIENTE, 409, 'No se puede modificar una solicitud que ya ha sido resuelta.');
        abort_unless($aprobacion->esSolicitante($actor) || $actor->hasRole('admin'), 403, 'Solo el solicitante o Gerencia pueden modificar esta solicitud.');

        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'solicitante_id' => ['nullable', 'integer', 'exists:users,id'],
            'aprobador_id' => ['nullable', 'integer', 'exists:users,id'],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'string'],
        ]);

        // Campos que determinan autoridad o decisión: no se cambian por la edición ordinaria.
        $errores = [];
        if (isset($data['estado']) && $data['estado'] !== $aprobacion->estado) {
            $errores['estado'] = 'El estado no puede modificarse mediante edición ordinaria. Utilice las operaciones específicas de aprobación, rechazo o reapertura.';
        }
        if ($data['tipo'] !== $aprobacion->tipo) {
            $errores['tipo'] = 'El tipo de solicitud no puede cambiarse: determina la autoridad que la resuelve.';
        }
        if (isset($data['solicitante_id']) && (int) $data['solicitante_id'] !== (int) $aprobacion->solicitante_id) {
            $errores['solicitante_id'] = 'No se puede sustituir al solicitante original de una solicitud.';
        }
        if (isset($data['aprobador_id']) && (int) $data['aprobador_id'] !== (int) $aprobacion->aprobador_id) {
            $errores['aprobador_id'] = 'El responsable de la decisión solo se registra al aprobar o rechazar.';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $this->ejecutar($request, function () use ($request, $aprobacion, $data): void {
            $this->gestion->actualizar($this->actor($request), (int) $aprobacion->getKey(), $data);
        }, 'La solicitud de aprobación se actualizó correctamente.');
    }

    public function destroy(Request $request, AprobacionInterna $aprobacion): RedirectResponse|JsonResponse
    {
        $actor = $this->actor($request);
        abort_if($aprobacion->estado !== AprobacionInterna::ESTADO_PENDIENTE, 409, 'No se puede eliminar una solicitud que ya ha sido resuelta.');
        abort_unless($aprobacion->esSolicitante($actor) || $actor->hasRole('admin'), 403, 'Solo el solicitante o Gerencia pueden eliminar esta solicitud.');

        return $this->ejecutar($request, function () use ($request, $aprobacion): void {
            $this->gestion->eliminar($this->actor($request), (int) $aprobacion->getKey());
        }, 'La solicitud de aprobación se eliminó correctamente.');
    }

    public function aprobar(Request $request, AprobacionInterna $aprobacion): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->ejecutar($request, function () use ($request, $aprobacion, $data): void {
            $this->gestion->aprobar($this->actor($request), (int) $aprobacion->getKey(), $data['comentario'] ?? null);
        }, 'La solicitud ha sido aprobada correctamente.');
    }

    public function rechazar(Request $request, AprobacionInterna $aprobacion): RedirectResponse|JsonResponse
    {
        $data = $request->validate($this->reglasMotivo(), $this->mensajesMotivo('rechazo'));

        return $this->ejecutar($request, function () use ($request, $aprobacion, $data): void {
            $this->gestion->rechazar($this->actor($request), (int) $aprobacion->getKey(), trim($data['motivo']));
        }, 'La solicitud ha sido rechazada.');
    }

    public function reabrir(Request $request, AprobacionInterna $aprobacion): RedirectResponse|JsonResponse
    {
        $data = $request->validate($this->reglasMotivo(), $this->mensajesMotivo('reapertura'));

        return $this->ejecutar($request, function () use ($request, $aprobacion, $data): void {
            $this->gestion->reabrir($this->actor($request), (int) $aprobacion->getKey(), trim($data['motivo']));
        }, 'La solicitud ha sido reabierta para una nueva evaluación.');
    }

    /**
     * Un conflicto de estado (solicitud ya resuelta, típicamente por otra persona) se comunica con un aviso en
     * el panel; las peticiones JSON reciben el 409. Los 403 se propagan siempre.
     */
    private function ejecutar(Request $request, Closure $operacion, string $exito): RedirectResponse|JsonResponse
    {
        $operacion();

        if ($request->expectsJson()) {
            return response()->json(['message' => $exito]);
        }

        return redirect()->route('admin.aprobaciones.index')->with('success', $exito);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
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
