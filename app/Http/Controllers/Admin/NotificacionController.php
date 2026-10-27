<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificacionInterna;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificacionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
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
