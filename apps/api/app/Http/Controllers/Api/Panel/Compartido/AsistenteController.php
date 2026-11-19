<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use App\Support\HoraLocal;
use App\Support\ListFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Probador y registros del asistente virtual para el panel de apps/web (equivale al módulo Blade `admin/asistente`).
 *
 * El motor es Horus AI (Python) con contingencia institucional; el filtro de privacidad se aplica antes de cualquier
 * llamada o registro, dentro de {@see AsistenteVirtualService}. Esta clase no decide nada del asistente: valida la
 * entrada, lo invoca con un ámbito `admin:` propio y lista lo ya registrado (metadatos y el texto ya filtrado).
 */
final class AsistenteController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:asistente.ver', only: ['index', 'mensaje'])];
    }

    public function index(Request $request): JsonResponse
    {
        $intenciones = ChatIntent::query()->orderByDesc('prioridad')->get();

        $filtro = ListFilter::text($request, 'intent');
        $intentValido = $filtro !== '' && $intenciones->contains('nombre', $filtro) ? $filtro : null;
        $porPagina = filter_var($request->query('per_page'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]) ?: 50;
        $pagina = max(1, (int) $request->query('page', 1));

        $paginado = ChatLog::query()
            ->when($intentValido, fn ($consulta) => $consulta->where('intent_detectado', $intentValido))
            ->latest()
            ->orderByDesc('id')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        $registros = collect($paginado->items());

        return response()->json([
            'data' => [
                'intenciones' => $intenciones->map(fn (ChatIntent $i): array => [
                    'nombre' => $i->nombre, 'respuesta' => $i->respuesta, 'prioridad' => $i->prioridad, 'activo' => (bool) $i->activo,
                ])->values()->all(),
                'registros' => $registros->map(fn (ChatLog $r): array => [
                    'id' => $r->id,
                    'fecha_local' => HoraLocal::formato($r->created_at),
                    'mensaje' => $r->mensaje_usuario,
                    'respuesta' => $r->respuesta_bot,
                    'intencion' => $r->intent_detectado,
                    'fuente' => $r->fuente,
                ])->values()->all(),
                'filtro' => $intentValido,
                'horus_ai' => [
                    'habilitado' => (bool) config('horus-advisor.enabled'),
                    'url_configurada' => trim((string) config('horus-advisor.url', '')) !== '',
                ],
            ],
            'meta' => [
                'pagina' => $paginado->currentPage(),
                'por_pagina' => $paginado->perPage(),
                'total' => $paginado->total(),
                'paginas' => $paginado->lastPage(),
                'orden' => '-creado',
                'filtros' => array_filter([
                    'intent' => $intentValido,
                    'per_page' => $request->query('per_page'),
                ], fn ($v): bool => $v !== null && $v !== ''),
            ],
        ]);
    }

    public function mensaje(Request $request, AsistenteVirtualService $asistente): JsonResponse
    {
        $datos = $request->validate([
            'mensaje' => ['required', 'string', 'max:2000'],
            'session_id' => ['required', 'string', 'max:64'],
        ], [
            'mensaje.required' => 'Escribe un mensaje para el asistente.',
            'mensaje.string' => 'El mensaje debe ser texto.',
            'mensaje.max' => 'El mensaje no debe superar los 2000 caracteres.',
            'session_id.required' => 'La sesión del chat no es válida.',
            'session_id.string' => 'La sesión del chat no es válida.',
            'session_id.max' => 'La sesión del chat no es válida.',
        ]);

        return response()->json($asistente->procesarMensaje($datos['mensaje'], $datos['session_id'], 'admin:'.$request->session()->getId()));
    }
}
