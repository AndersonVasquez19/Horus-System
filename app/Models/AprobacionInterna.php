<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AprobacionInterna extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADO = 'aprobado';

    public const ESTADO_RECHAZADO = 'rechazado';

    public const ESTADOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_APROBADO,
        self::ESTADO_RECHAZADO,
    ];

    public const TIPO_COMPRA = 'compra';

    public const TIPO_OPERATIVO = 'operativo';

    /**
     * Matriz de autoridad por tipo de solicitud:
     * - compra: Gerencia (rol admin)
     * - operativo: Coordinación (rol coordinador)
     *
     * @var array<string, string>
     */
    public const TIPOS_AUTORIDAD = [
        self::TIPO_COMPRA => 'admin',
        self::TIPO_OPERATIVO => 'coordinador',
    ];

    public const TIPOS_PERMITIDOS = [
        self::TIPO_COMPRA,
        self::TIPO_OPERATIVO,
    ];

    protected $table = 'aprobaciones_internas';

    protected $fillable = [
        'titulo',
        'tipo',
        'descripcion',
        'estado',
        'solicitante_id',
        'aprobador_id',
        'comentario',
        'referencia',
    ];

    protected static function booted(): void
    {
        static::created(function (self $aprobacion): void {
            if ($aprobacion->estado === self::ESTADO_PENDIENTE && $aprobacion->aprobador_id !== null) {
                NotificacionInterna::crearPersonal((int) $aprobacion->aprobador_id, [
                    'titulo' => 'Nueva solicitud de aprobación',
                    'mensaje' => 'Hay una solicitud pendiente para revisar: '.$aprobacion->titulo,
                    'tipo' => 'warning',
                    'leida' => false,
                    'vinculo' => '/admin/aprobaciones',
                ]);
            }
        });

        static::updated(function (self $aprobacion): void {
            if (! $aprobacion->wasChanged('estado')) {
                return;
            }

            if ($aprobacion->estado === self::ESTADO_PENDIENTE) {
                // Reapertura: solicitante y responsables del tipo, sin avisar a quien reabre ni duplicar.
                $destinatarios = self::sinRepetir(
                    [$aprobacion->solicitante_id, ...$aprobacion->responsablesIds()],
                    [auth()->id()],
                );
            } else {
                $destinatario = $aprobacion->solicitante_id ?? $aprobacion->aprobador_id;
                $destinatarios = $destinatario === null ? [] : [(int) $destinatario];
            }

            $mensaje = match ($aprobacion->estado) {
                self::ESTADO_APROBADO => 'fue aprobada correctamente.',
                self::ESTADO_RECHAZADO => 'fue rechazada y requiere revisión.'.($aprobacion->comentario ? ' Motivo: '.$aprobacion->comentario : ''),
                self::ESTADO_PENDIENTE => 'fue reabierta para revisión.'.($aprobacion->comentario ? ' '.$aprobacion->comentario : ''),
                default => 'cambió de estado.',
            };

            foreach ($destinatarios as $userId) {
                NotificacionInterna::crearPersonal($userId, [
                    'titulo' => 'Cambio de aprobación',
                    'mensaje' => 'La solicitud "'.$aprobacion->titulo.'" '.$mensaje,
                    'tipo' => match ($aprobacion->estado) {
                        self::ESTADO_APROBADO => 'success',
                        self::ESTADO_PENDIENTE => 'warning',
                        default => 'danger',
                    },
                    'leida' => false,
                    'vinculo' => '/admin/aprobaciones',
                ]);
            }
        });
    }

    /**
     * Ids de los usuarios que tienen el rol autorizado para el tipo de esta solicitud.
     *
     * @return list<int>
     */
    public function responsablesIds(): array
    {
        $rol = $this->rolAutorizado();
        if ($rol === null) {
            return [];
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', $rol)->where('guard_name', 'web'))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string|null>  $ids
     * @param  array<int, int|string|null>  $excluir
     * @return list<int>
     */
    private static function sinRepetir(array $ids, array $excluir): array
    {
        $excluir = array_map('intval', array_filter($excluir, fn ($id) => $id !== null));

        return array_values(array_unique(array_diff(
            array_map('intval', array_filter($ids, fn ($id) => $id !== null)),
            $excluir,
        )));
    }

    public function esSolicitante(?User $user): bool
    {
        return $user !== null && $this->solicitante_id !== null && (int) $this->solicitante_id === (int) $user->getKey();
    }

    /**
     * Obtiene el rol autorizado para resolver un tipo dado.
     */
    public static function rolAutorizadoParaTipo(string $tipo): ?string
    {
        return self::TIPOS_AUTORIDAD[$tipo] ?? null;
    }

    /**
     * Retorna el rol autorizado para resolver esta solicitud.
     */
    public function rolAutorizado(): ?string
    {
        return self::rolAutorizadoParaTipo($this->tipo);
    }

    /**
     * Determina si el usuario tiene autoridad para resolver esta solicitud (aprobar o rechazar).
     *
     * Reglas:
     * 1. Usuario debe existir.
     * 2. Prohibición estricta de autoaprobación: el solicitante no puede resolver su propia solicitud.
     * 3. El rol del usuario debe coincidir con el rol asignado exclusivamente a este tipo de solicitud.
     */
    public function puedeResolver(?User $user): bool
    {
        if ($user === null || $this->esSolicitante($user)) {
            return false;
        }

        $rolEsperado = $this->rolAutorizado();
        if ($rolEsperado === null) {
            return false;
        }

        // Mismo permiso que exige la ruta: la interfaz no ofrece acciones que el servidor rechazaría.
        return $user->hasRole($rolEsperado) && $user->can('aprobaciones.gestionar');
    }

    /**
     * Determina si el usuario puede reabrir la solicitud.
     *
     * Reglas:
     * 1. La solicitud debe estar resuelta (aprobado o rechazado).
     * 2. El usuario debe tener permiso 'aprobaciones.gestionar'.
     * 3. El usuario debe ser Gerencia (admin) o el rol autorizado para este tipo.
     */
    public function puedeReabrir(?User $user): bool
    {
        if ($user === null || $this->estado === self::ESTADO_PENDIENTE) {
            return false;
        }

        if (! $user->can('aprobaciones.gestionar')) {
            return false;
        }

        $rolEsperado = $this->rolAutorizado();

        return $user->hasRole('admin') || ($rolEsperado !== null && $user->hasRole($rolEsperado));
    }

    /**
     * Historial de auditoría de varias solicitudes en una sola consulta (evita N+1 en el listado),
     * agrupado por id de solicitud y ordenado del más reciente al más antiguo.
     *
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int|string, Collection<int, AuditLog>>
     */
    public static function historialesDe(array $ids): \Illuminate\Support\Collection
    {
        if ($ids === []) {
            return collect();
        }

        return AuditLog::query()
            ->with('user:id,name')
            ->where('model_type', self::class)
            ->whereIn('model_id', $ids)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('model_id');
    }

    /** @return BelongsTo<User, $this> */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /** @return BelongsTo<User, $this> */
    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobador_id');
    }
}
