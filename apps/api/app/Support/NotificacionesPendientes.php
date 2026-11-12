<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NotificacionInterna;
use App\Models\User;

/**
 * Número de notificaciones visibles y no leídas del usuario. Se calcula una vez por petición (memoria en el
 * Request) y lo comparten la campana de la barra superior y el inicio.
 */
final class NotificacionesPendientes
{
    private const ATRIBUTO = 'horus.notificaciones_pendientes';

    public static function para(User $usuario): int
    {
        $clave = self::ATRIBUTO.'.'.$usuario->getKey();
        $atributos = request()->attributes;

        if (! $atributos->has($clave)) {
            $atributos->set($clave, NotificacionInterna::query()
                ->visiblePara($usuario)
                ->noLeidasPara($usuario)
                ->count());
        }

        return (int) $atributos->get($clave);
    }

    public static function olvidar(User $usuario): void
    {
        request()->attributes->remove(self::ATRIBUTO.'.'.$usuario->getKey());
    }
}
