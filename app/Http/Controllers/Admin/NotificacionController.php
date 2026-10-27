<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificacionInterna;
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
        $notificaciones = NotificacionInterna::query()
            ->where('user_id', auth()->id())
            ->orWhereNull('user_id')
            ->latest()
            ->paginate(12);

        return view('admin.notificaciones.index', [
            'notificaciones' => $notificaciones,
            'pendientes' => NotificacionInterna::query()
                ->where(function ($query): void {
                    $query->where('user_id', auth()->id())
                        ->orWhereNull('user_id');
                })
                ->where('leida', false)
                ->count(),
        ]);
    }

    public function marcarLeida(NotificacionInterna $notificacion): RedirectResponse
    {
        if ($notificacion->user_id !== null && $notificacion->user_id !== auth()->id()) {
            abort(403);
        }

        $notificacion->update(['leida' => true]);

        return back()->with('success', 'La notificación se marcó como leída.');
    }
}
