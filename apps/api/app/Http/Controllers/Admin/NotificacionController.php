<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificacionInterna;
use App\Models\NotificacionLectura;
use App\Models\User;
use App\Support\HoraLocal;
use App\Support\NotificacionesPendientes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NotificacionController extends Controller implements HasMiddleware
{
    /** Notificaciones que muestra el desplegable de la campana. */
    public const RECIENTES = 5;

    /**
     * El grupo /admin ya exige sesión y rol administrativo; se conserva el requisito de sesión del controlador.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('auth'),
        ];
    }

    public function index(): View
    {
        $usuario = $this->usuario();

        $notificaciones = NotificacionInterna::query()
            ->visiblePara($usuario)
            ->conLecturaDe($usuario)
            ->latest()
            ->latest('id')
            ->paginate(12);

        return view('admin.notificaciones.index', [
            'notificaciones' => $notificaciones,
            'pendientes' => NotificacionesPendientes::para($usuario),
            'usuario' => $usuario,
        ]);
    }

    /**
     * Desplegable de la campana: las últimas no leídas del usuario y el total pendiente. Solo campos de
     * presentación; el vínculo pasa por vinculoSeguro().
     */
    public function recientes(): JsonResponse
    {
        $usuario = $this->usuario();

        $items = NotificacionInterna::query()
            ->visiblePara($usuario)
            ->noLeidasPara($usuario)
            ->latest()
            ->latest('id')
            ->limit(self::RECIENTES)
            ->get()
            ->map(fn (NotificacionInterna $notificacion): array => [
                'id' => $notificacion->getKey(),
                'titulo' => $notificacion->titulo,
                'mensaje' => Str::limit((string) $notificacion->mensaje, 140),
                'tipo' => $notificacion->tipo,
                'fecha' => HoraLocal::formato($notificacion->created_at),
                'vinculo' => $notificacion->vinculoSeguro(),
            ])
            ->values();

        return response()->json([
            'pendientes' => NotificacionesPendientes::para($usuario),
            'notificaciones' => $items,
        ]);
    }

    /**
     * Solo el destinatario de una personal o cualquier miembro del personal en una general.
     * Personales ajenas o huérfanas e indeterminadas: 403. Una general queda leída solo para quien la marca.
     */
    public function marcarLeida(NotificacionInterna $notificacion): RedirectResponse
    {
        $usuario = $this->usuario();

        if (! $notificacion->esVisiblePara($usuario)) {
            abort(403);
        }

        $notificacion->marcarLeidaPara($usuario);

        return back()->with('success', 'La notificación se marcó como leída.');
    }

    /**
     * Marca como leídas todas las notificaciones visibles y no leídas del usuario (personales en su fila,
     * generales con una lectura propia). No afecta a lo que ven los demás.
     */
    public function marcarTodas(Request $request): RedirectResponse|JsonResponse
    {
        $usuario = $this->usuario();

        $marcadas = DB::transaction(function () use ($usuario): int {
            $pendientes = NotificacionInterna::query()
                ->visiblePara($usuario)
                ->noLeidasPara($usuario)
                ->get(['id', 'audiencia', 'user_id', 'leida']);

            $personales = $pendientes->where('audiencia', NotificacionInterna::AUDIENCIA_PERSONAL)->modelKeys();
            if ($personales !== []) {
                NotificacionInterna::query()->whereKey($personales)->update(['leida' => true]);
            }

            $ahora = now();
            $lecturas = $pendientes->where('audiencia', NotificacionInterna::AUDIENCIA_GENERAL)
                ->map(fn (NotificacionInterna $general): array => [
                    'notificacion_id' => $general->getKey(),
                    'user_id' => $usuario->getKey(),
                    'leida_at' => $ahora,
                ])->values()->all();
            if ($lecturas !== []) {
                NotificacionLectura::query()->insertOrIgnore($lecturas);
            }

            return $pendientes->count();
        });

        NotificacionesPendientes::olvidar($usuario);

        if ($request->wantsJson()) {
            return response()->json(['marcadas' => $marcadas, 'pendientes' => 0]);
        }

        return back()->with('success', match ($marcadas) {
            0 => 'No había notificaciones pendientes.',
            1 => 'Se marcó 1 notificación como leída.',
            default => "Se marcaron {$marcadas} notificaciones como leídas.",
        });
    }

    private function usuario(): User
    {
        $usuario = auth()->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
