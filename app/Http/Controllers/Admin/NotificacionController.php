<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificacionInterna;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class NotificacionController extends Controller implements HasMiddleware
{
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
            ->latest()
            ->paginate(12);

        return view('admin.notificaciones.index', [
            'notificaciones' => $notificaciones,
            'pendientes' => NotificacionInterna::query()
                ->visiblePara($usuario)
                ->where('leida', false)
                ->count(),
        ]);
    }

    /**
     * Solo el destinatario de una personal o cualquier miembro del personal en una general.
     * Personales ajenas o huérfanas e indeterminadas: 403. La lectura de una general sigue siendo compartida.
     */
    public function marcarLeida(NotificacionInterna $notificacion): RedirectResponse
    {
        if (! $notificacion->esVisiblePara($this->usuario())) {
            abort(403);
        }

        $notificacion->update(['leida' => true]);

        return back()->with('success', 'La notificación se marcó como leída.');
    }

    private function usuario(): User
    {
        $usuario = auth()->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
