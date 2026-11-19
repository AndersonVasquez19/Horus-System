<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\AprobacionInterna;
use App\Models\MensajeContacto;
use App\Models\NotificacionInterna;
use App\Models\NotificacionLectura;
use App\Models\User;
use App\Support\ConsultaReclamaciones;
use App\Support\HoraLocal;
use App\Support\NotificacionesPendientes;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notificaciones internas del usuario del panel (compartidas por los dos paneles). No requieren un permiso de módulo:
 * el usuario sale siempre de la sesión y solo ve lo suyo (personales propias y generales). Misma regla de visibilidad
 * y de lectura por usuario que el Blade retirado el 14/11/2026.
 */
final class NotificacionController extends Controller
{
    /** Notificaciones que muestra el desplegable de la campana. */
    public const RECIENTES = 5;

    private const ORDENABLES = ['fecha' => 'created_at'];

    /** Tipo de aviso → texto que se muestra. Un tipo nuevo se muestra como «Aviso». */
    public const TIPOS = [
        'reclamacion' => 'Reclamación',
        'reclamacion_plazo' => 'Plazo de reclamación',
        'reclamacion_rebote' => 'Rebote de correo en reclamación',
        'contacto' => 'Mensaje de contacto',
        'recordatorio_cuota' => 'Cuota por vencer',
        'recordatorio_sesion' => 'Clase programada hoy',
        'recordatorio_contacto' => 'Mensajes sin atender',
        'warning' => 'Aviso',
        'success' => 'Confirmación',
        'danger' => 'Urgente',
        'info' => 'Información',
    ];

    public function index(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);

        $filtros = self::filtros($request);
        $consulta = NotificacionInterna::query()->visiblePara($usuario)->conLecturaDe($usuario);
        if ($filtros['estado'] === 'no_leidas') {
            $consulta->noLeidasPara($usuario);
        } elseif ($filtros['estado'] === 'leidas') {
            $consulta->whereNotIn('id', NotificacionInterna::query()->visiblePara($usuario)->noLeidasPara($usuario)->select('id'));
        }
        if ($filtros['tipo'] !== '') {
            $consulta->where('tipo', $filtros['tipo']);
        }
        if ($filtros['desde'] !== '') {
            $consulta->where('created_at', '>=', Carbon::parse($filtros['desde'], HoraLocal::ZONA)->startOfDay()->utc());
        }
        if ($filtros['hasta'] !== '') {
            $consulta->where('created_at', '<=', Carbon::parse($filtros['hasta'], HoraLocal::ZONA)->endOfDay()->utc());
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-fecha');
        $respuesta = Listado::responder(
            Listado::paginar($consulta, $request),
            fn (NotificacionInterna $notificacion): array => [
                ...self::resumen($notificacion),
                'leida' => $notificacion->estaLeidaPara($usuario),
            ],
            $orden,
            $filtros,
        );

        $cuerpo = $respuesta->getData(true);
        $cuerpo['meta']['pendientes'] = NotificacionesPendientes::para($usuario);
        $cuerpo['meta']['tipos'] = collect(self::TIPOS)->map(fn (string $etiqueta, string $clave): array => ['clave' => $clave, 'etiqueta' => $etiqueta])->values()->all();

        return response()->json($cuerpo);
    }

    /** Desplegable de la campana: las últimas no leídas del usuario y el total pendiente. */
    public function recientes(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);

        $items = NotificacionInterna::query()
            ->visiblePara($usuario)
            ->noLeidasPara($usuario)
            ->latest()
            ->latest('id')
            ->limit(self::RECIENTES)
            ->get()
            ->map(fn (NotificacionInterna $notificacion): array => [
                ...self::resumen($notificacion),
                'mensaje' => Str::limit((string) $notificacion->mensaje, 140),
            ])
            ->values();

        $conteos = $this->conteosPara($usuario);

        return response()->json([
            'data' => $items,
            'meta' => [
                'pendientes' => $conteos['notificaciones_sin_leer'],
                'conteos' => $conteos,
            ],
        ]);
    }

    /** Conteos ligeros para la barra lateral y la campana, respetando los permisos del usuario. */
    public function conteos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->conteosPara($this->usuario($request))]);
    }

    /** @return array{notificaciones_sin_leer: int, aprobaciones_pendientes: int, reclamaciones_por_vencer: int, mensajes_nuevos: int} */
    private function conteosPara(User $usuario): array
    {
        $reclamacionesPorVencer = 0;
        $mensajesNuevos = 0;
        if ($usuario->can('reclamaciones.ver')) {
            $plazos = ConsultaReclamaciones::plazos();
            $reclamacionesPorVencer = $plazos['vencidas'] + $plazos['vencen_hoy'] + $plazos['por_vencer'];
            $mensajesNuevos = MensajeContacto::query()->where('estado', MensajeContacto::ESTADO_NUEVO)->count();
        }

        return [
            'notificaciones_sin_leer' => NotificacionesPendientes::para($usuario),
            'aprobaciones_pendientes' => $usuario->can('aprobaciones.ver')
                ? AprobacionInterna::query()->where('estado', 'pendiente')->count()
                : 0,
            'reclamaciones_por_vencer' => $reclamacionesPorVencer,
            'mensajes_nuevos' => $mensajesNuevos,
        ];
    }

    /**
     * Solo el destinatario de una personal o cualquier miembro del personal en una general. Personales ajenas o
     * huérfanas e indeterminadas: 403. Una general queda leída solo para quien la marca.
     */
    public function marcarLeida(Request $request, NotificacionInterna $notificacion): JsonResponse
    {
        $usuario = $this->usuario($request);
        abort_unless($notificacion->esVisiblePara($usuario), 403);

        $notificacion->marcarLeidaPara($usuario);

        return response()->json(['message' => 'La notificación se marcó como leída.', 'meta' => ['pendientes' => NotificacionesPendientes::para($usuario)]]);
    }

    /** Marca como leídas todas las visibles y no leídas del usuario; no afecta a lo que ven los demás. */
    public function marcarTodas(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);

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

        return response()->json([
            'message' => match ($marcadas) {
                0 => 'No había notificaciones pendientes.',
                1 => 'Se marcó 1 notificación como leída.',
                default => "Se marcaron {$marcadas} notificaciones como leídas.",
            },
            'data' => ['marcadas' => $marcadas],
            'meta' => ['pendientes' => 0],
        ]);
    }

    /** @return array{estado: string, tipo: string, desde: string, hasta: string} */
    private static function filtros(Request $request): array
    {
        $texto = static fn (string $campo): string => is_string($request->query($campo)) ? trim((string) $request->query($campo)) : '';
        $fecha = static function (string $valor): string {
            if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $valor, $m) !== 1) {
                return '';
            }

            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $valor : '';
        };
        $estado = $texto('estado');
        $tipo = $texto('tipo');

        return [
            'estado' => in_array($estado, ['no_leidas', 'leidas'], true) ? $estado : '',
            'tipo' => array_key_exists($tipo, self::TIPOS) ? $tipo : '',
            'desde' => $fecha($texto('desde')),
            'hasta' => $fecha($texto('hasta')),
        ];
    }

    /** Texto del enlace según a dónde lleva. */
    private static function textoEnlace(?string $vinculo): string
    {
        return match (true) {
            $vinculo === null => '',
            str_contains($vinculo, '/aprobaciones') => 'Ir a aprobaciones',
            str_contains($vinculo, '/reclamaciones') => 'Ver en reclamaciones',
            default => 'Abrir',
        };
    }

    /** @return array<string, mixed> */
    private static function resumen(NotificacionInterna $notificacion): array
    {
        return [
            'id' => $notificacion->getKey(),
            'titulo' => $notificacion->titulo,
            'mensaje' => $notificacion->mensaje,
            'tipo' => $notificacion->tipo,
            'fecha' => $notificacion->created_at?->toIso8601String(),
            'fecha_local' => HoraLocal::formato($notificacion->created_at),
            'tipo_etiqueta' => self::TIPOS[(string) $notificacion->tipo] ?? 'Aviso',
            'vinculo' => $notificacion->vinculoSeguro(),
            'enlace_texto' => self::textoEnlace($notificacion->vinculoSeguro()),
        ];
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
