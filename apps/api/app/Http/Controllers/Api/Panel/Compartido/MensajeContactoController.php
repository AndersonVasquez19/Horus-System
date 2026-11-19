<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MensajeContacto;
use App\Models\User;
use App\Support\HoraLocal;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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
            new Middleware('permission:reclamaciones.gestionar', only: ['atender']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        $estado = is_string($estado) && in_array($estado, [MensajeContacto::ESTADO_NUEVO, MensajeContacto::ESTADO_ATENDIDO], true) ? $estado : '';

        $consulta = MensajeContacto::query()->with('atendidoPor:id,name');
        if ($estado !== '') {
            $consulta->where('estado', $estado);
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
            ['estado' => $estado],
        );

        $cuerpo = $respuesta->getData(true);
        $cuerpo['meta']['nuevos'] = MensajeContacto::query()->where('estado', MensajeContacto::ESTADO_NUEVO)->count();

        return response()->json($cuerpo, 200, self::CABECERAS);
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
            $mensaje->forceFill(['estado' => MensajeContacto::ESTADO_ATENDIDO, 'atendido_por' => $usuario->getKey(), 'atendido_at' => now()])->save();
            AuditLog::record('contacto_atendido', MensajeContacto::class, $mensaje->id, [], (int) $usuario->getKey());
        });

        return response()->json(['message' => 'El mensaje se marcó como atendido.'], 200, self::CABECERAS);
    }
}
