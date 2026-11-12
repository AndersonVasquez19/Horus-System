<?php

declare(strict_types=1);

namespace Horus\Local;

use Throwable;

/** Un fallo del comando de renovación no debe cerrar API, web ni worker sanos. */
final class LocalCatalogRenewal
{
    public static function attempt(callable $dispatch, callable $recordFailure): bool
    {
        try {
            $dispatch();

            return true;
        } catch (Throwable $error) {
            try {
                $recordFailure(['event' => 'renewal_control_unconfirmed', 'exception_type' => $error::class,
                    'at' => date(DATE_ATOM)]);
            } catch (Throwable) {
                // La pérdida del registro tampoco autoriza cerrar procesos sanos.
            }

            return false;
        }
    }
}
