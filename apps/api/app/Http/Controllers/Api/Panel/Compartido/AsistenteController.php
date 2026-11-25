<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use App\Services\ConversacionesAsistente;
use App\Services\MetricasAsistente;
use App\Support\ListFilter;
use App\Support\SenalesAsistente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Schema;

/**
 * Sección del asistente virtual del panel de apps/web: resumen de uso, conversaciones y probador.
 *
 * El motor es Horus AI (Python) con contingencia institucional; el filtro de privacidad se aplica antes de cualquier
 * llamada o registro, dentro de {@see AsistenteVirtualService}. Esta clase no decide nada del asistente: valida la
 * entrada, lo invoca con un ámbito `admin:` propio y presenta lo ya registrado (la pregunta del visitante no se guarda).
 */
final class AsistenteController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:asistente.ver', only: ['index', 'mensaje'])];
    }

    /** Cada pestaña pide solo lo suyo: `vista` = resumen (por defecto), conversaciones o probar. */
    public function index(Request $request, MetricasAsistente $metricas, ConversacionesAsistente $conversaciones): JsonResponse
    {
        $vista = ListFilter::in($request, 'vista', ['resumen', 'conversaciones', 'probar']) ?: 'resumen';
        $dias = ListFilter::in($request, 'dias', array_map('strval', MetricasAsistente::PERIODOS));
        $datos = ['vista' => $vista, 'horus_ai' => ['habilitado' => (bool) config('horus-advisor.enabled')]];
        $meta = null;

        if ($vista === 'resumen') {
            $datos['metricas'] = $metricas->resumen($dias === '' ? 30 : (int) $dias);
        }

        if ($vista === 'conversaciones') {
            $temas = $conversaciones->temas();
            $conCanal = Schema::hasColumn((new ChatLog)->getTable(), 'canal');
            $filtros = [
                'busqueda' => ListFilter::text($request, 'busqueda', 120),
                'etapa' => ListFilter::in($request, 'etapa', SenalesAsistente::ETAPAS),
                'tema' => ListFilter::in($request, 'intent', [...$temas, ConversacionesAsistente::TEMA_SIN_DATO]),
                'fuente' => ListFilter::in($request, 'fuente', ['advisor', 'respaldo']),
                'voto' => ListFilter::in($request, 'voto', ['up', 'down']),
                'canal' => ListFilter::in($request, 'canal', ['admin']) ?: 'web',
                'dias' => $dias === '' ? null : (int) $dias,
            ];
            $pagina = max(1, (int) ListFilter::text($request, 'page', 6));
            $listado = $conversaciones->listar($filtros, $pagina);

            $datos['conversaciones'] = $listado['conversaciones'];
            $datos['temas'] = $temas;
            $datos['con_votos'] = Schema::hasColumn((new ChatLog)->getTable(), 'voto');
            $datos['separa_canal'] = $conCanal;
            $datos['con_whatsapp'] = Schema::hasColumn((new ChatLog)->getTable(), 'abrio_whatsapp');
            $meta = [
                'pagina' => $listado['pagina'],
                'por_pagina' => ConversacionesAsistente::POR_PAGINA,
                'total' => $listado['total'],
                'paginas' => $listado['paginas'],
                'orden' => '-creado',
                'filtros' => array_filter([
                    'busqueda' => $filtros['busqueda'],
                    'etapa' => $filtros['etapa'],
                    'intent' => $filtros['tema'],
                    'fuente' => $filtros['fuente'],
                    'voto' => $filtros['voto'],
                    'canal' => $filtros['canal'] === 'admin' ? 'admin' : '',
                    'dias' => $dias,
                ], fn (string $v): bool => $v !== ''),
            ];
        }

        return response()->json($meta === null ? ['data' => $datos] : ['data' => $datos, 'meta' => $meta]);
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
