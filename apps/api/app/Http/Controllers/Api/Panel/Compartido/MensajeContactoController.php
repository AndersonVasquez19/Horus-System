<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MensajeContacto;
use App\Models\User;
use App\Support\BusquedaLike;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bandeja de los mensajes de «Contáctanos», dentro del módulo de reclamaciones: se ven con `reclamaciones.ver` y se marcan
 * como atendidos con `reclamaciones.gestionar`. Contienen datos personales: respuestas siempre `no-store, private` y sin ids
 * internos. La auditoría de «atendido» no lleva el texto del mensaje.
 */
final class MensajeContactoController extends Controller implements HasMiddleware
{
    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reclamaciones.ver', only: ['index']),
            new Middleware('permission:reclamaciones.gestionar', only: ['atender', 'atenderLote']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'estado' => ListFilter::in($request, 'estado', [MensajeContacto::ESTADO_NUEVO, MensajeContacto::ESTADO_ATENDIDO]),
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'asunto' => ListFilter::text($request, 'asunto', 120),
            'desde' => ListFilter::date($request, 'desde'),
            'hasta' => ListFilter::date($request, 'hasta'),
        ];

        $consulta = MensajeContacto::query()->with('atendidoPor:id,name');
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['nombre', 'correo', 'telefono', 'asunto', 'mensaje'], $filtros['busqueda']);
        }
        if ($filtros['asunto'] !== '') {
            $consulta->where('asunto', $filtros['asunto']);
        }
        if ($filtros['desde'] !== '') {
            $consulta->where('created_at', '>=', Carbon::parse($filtros['desde'], HoraLocal::ZONA)->startOfDay()->utc());
        }
        if ($filtros['hasta'] !== '') {
            $consulta->where('created_at', '<=', Carbon::parse($filtros['hasta'], HoraLocal::ZONA)->endOfDay()->utc());
        }
        $consulta->latest('created_at')->latest('id');

        $respuesta = Listado::responder(
            Listado::paginar($consulta, $request),
            fn (MensajeContacto $m): array => [
                'id' => $m->identificador,
                'nombre' => $m->nombre,
                'telefono' => $m->telefono,
                'correo' => $m->correo,
                'asunto' => $m->asunto,
                'mensaje' => $m->mensaje,
                'estado' => $m->estado,
                'recibido_local' => HoraLocal::formato($m->created_at),
                'atendido_por' => $m->atendidoPor?->name,
                'atendido_local' => $m->atendido_at === null ? null : HoraLocal::formato($m->atendido_at),
            ],
            '-fecha',
            $filtros,
        );

        $cuerpo = Listado::contenido($respuesta);
        $cuerpo['meta']['nuevos'] = MensajeContacto::query()->where('estado', MensajeContacto::ESTADO_NUEVO)->count();
        $cuerpo['meta']['asuntos'] = MensajeContacto::query()->distinct()->orderBy('asunto')->pluck('asunto')->values()->all();

        return response()->json($cuerpo, 200, self::CABECERAS);
    }

    /** Marca como atendidos en bloque los mensajes indicados por su identificador UUID. */
    public function atenderLote(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        $datos = $request->validate([
            'identificadores' => ['required', 'array', 'min:1', 'max:100'],
            'identificadores.*' => ['required', 'uuid'],
        ]);

        $marcados = DB::transaction(function () use ($datos, $usuario): int {
            $mensajes = MensajeContacto::query()
                ->whereIn('identificador', array_map('strtolower', $datos['identificadores']))
                ->where('estado', MensajeContacto::ESTADO_NUEVO)
                ->lockForUpdate()
                ->get();

            $ahora = now();
            foreach ($mensajes as $mensaje) {
                $mensaje->forceFill([
                    'estado' => MensajeContacto::ESTADO_ATENDIDO,
                    'atendido_por' => $usuario->id,
                    'atendido_at' => $ahora,
                ])->save();
                AuditLog::record('contacto_atendido', MensajeContacto::class, $mensaje->id, [], $usuario->id);
            }

            return $mensajes->count();
        });

        return response()->json([
            'message' => match ($marcados) {
                0 => 'Ningún mensaje pendiente requería atención.',
                1 => 'Se marcó 1 mensaje como atendido.',
                default => "Se marcaron {$marcados} mensajes como atendidos.",
            },
            'data' => ['marcados' => $marcados],
        ], 200, self::CABECERAS);
    }

    /** Marca el mensaje como atendido (repetirlo no cambia nada). */
    public function atender(Request $request, MensajeContacto $mensaje): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        DB::transaction(function () use ($mensaje, $usuario): void {
            $mensaje = MensajeContacto::query()->whereKey($mensaje->getKey())->lockForUpdate()->firstOrFail();
            if ($mensaje->estado === MensajeContacto::ESTADO_ATENDIDO) {
                return;
            }
            $mensaje->forceFill(['estado' => MensajeContacto::ESTADO_ATENDIDO, 'atendido_por' => $usuario->id, 'atendido_at' => now()])->save();
            AuditLog::record('contacto_atendido', MensajeContacto::class, $mensaje->id, [], $usuario->id);
        });

        return response()->json(['message' => 'El mensaje se marcó como atendido.'], 200, self::CABECERAS);
    }
}
