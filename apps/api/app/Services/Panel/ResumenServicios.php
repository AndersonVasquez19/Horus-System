<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Support\HoraLocal;

/**
 * Indicadores del dashboard de servicios tecnológicos. Solo datos del dominio tecnológico; cada bloque solo con
 * el permiso de su módulo. Las cotizaciones mantienen la restricción vigente (rol `admin` + `cotizaciones.ver`,
 * docs/restriccion-acceso-comercial-admin.md) y se muestran sin datos de contacto.
 */
final class ResumenServicios
{
    /** @return array<string, mixed> */
    public function para(User $user): array
    {
        $indicadores = [];
        $bloques = [];

        if ($user->can('servicios.ver')) {
            $porEstado = ServicioTecnologico::query()->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
            $indicadores['servicios_activos'] = (int) ($porEstado['activo'] ?? 0);
            $indicadores['servicios_total'] = (int) $porEstado->sum();
            $indicadores['servicios_publicados'] = ServicioTecnologico::query()
                ->whereHas('publicacion', fn ($consulta) => $consulta->where('estado', 'publicada'))
                ->count();
        }

        if (self::puedeVerCotizaciones($user)) {
            $porEstado = SolicitudCotizacion::query()->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
            $indicadores['cotizaciones_recibidas'] = (int) ($porEstado['recibida'] ?? 0);
            $indicadores['cotizaciones_en_revision'] = (int) ($porEstado['en_revision'] ?? 0);
            $indicadores['seguimientos_pendientes'] = SolicitudCotizacion::query()
                ->whereNotNull('proximo_seguimiento')
                ->whereDate('proximo_seguimiento', '<=', HoraLocal::ahora()->toDateString())
                ->count();

            $bloques['ultimas_cotizaciones'] = SolicitudCotizacion::query()
                ->select(['identificador', 'codigo', 'recibida_at', 'estado', 'servicio_nombre_historico', 'proximo_seguimiento'])
                ->latest('recibida_at')
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (SolicitudCotizacion $solicitud): array => [
                    'identificador' => $solicitud->identificador,
                    'codigo' => $solicitud->codigo,
                    'servicio' => $solicitud->servicio_nombre_historico,
                    'estado' => $solicitud->estado,
                    'recibida_en' => $solicitud->recibida_at?->toIso8601String(),
                    'proximo_seguimiento' => $solicitud->proximo_seguimiento?->toDateString(),
                ])
                ->all();
        }

        return ['indicadores' => $indicadores, 'bloques' => $bloques];
    }

    public static function puedeVerCotizaciones(User $user): bool
    {
        return $user->hasRole('admin') && $user->can('cotizaciones.ver');
    }
}
