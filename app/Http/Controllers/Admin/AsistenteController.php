<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class AsistenteController extends Controller implements HasMiddleware
{
    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:asistente.ver', only: ['index', 'mensaje']),
        ];
    }

    public function index(Request $request): View
    {
        $intents = ChatIntent::query()
            ->orderByDesc('prioridad')
            ->get();

        $intencion = $request->input('intent');
        $intentValido = $intents->contains('nombre', $intencion) ? $intencion : null;

        $logs = ChatLog::query()
            ->when($intentValido, fn ($query) => $query->where('intent_detectado', $intentValido))
            ->latest()
            ->limit(50)
            ->get();

        return view('admin.asistente.index', [
            'intents' => $intents,
            'logs' => $logs,
            'intentSeleccionado' => $intentValido,
        ]);
    }

    public function mensaje(Request $request, AsistenteVirtualService $asistente): JsonResponse
    {
        $datos = $request->validate(
            [
                'mensaje' => ['required', 'string', 'max:2000'],
                'session_id' => ['required', 'string', 'max:64'],
            ],
            [
                'mensaje.required' => 'Escribe un mensaje para el asistente.',
                'mensaje.string' => 'El mensaje debe ser texto.',
                'mensaje.max' => 'El mensaje no debe superar los 2000 caracteres.',
                'session_id.required' => 'La sesión del chat no es válida.',
                'session_id.string' => 'La sesión del chat no es válida.',
                'session_id.max' => 'La sesión del chat no es válida.',
            ],
        );

        return response()->json($asistente->procesarMensaje($datos['mensaje'], $datos['session_id']));
    }
}
