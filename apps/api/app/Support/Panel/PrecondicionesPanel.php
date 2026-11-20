<?php

declare(strict_types=1);

namespace App\Support\Panel;

use Illuminate\Http\Request;

/**
 * El BFF de Next solo reenvía campos de un cuerpo JSON, no cabeceras. Los servicios de servicios tecnológicos y
 * cotizaciones exigen `If-Match` (revisión) e `Idempotency-Key` (UUID por intención); el panel los envía como
 * `_revision` y `_clave` y aquí se trasladan a las cabeceras que esos servicios ya validan. Una cabecera real
 * enviada por el cliente tiene prioridad.
 */
final class PrecondicionesPanel
{
    public static function aplicar(Request $request): void
    {
        $revision = $request->input('_revision');
        if (! $request->headers->has('If-Match') && is_string($revision)) {
            $request->headers->set('If-Match', $revision);
        }

        $clave = $request->input('_clave');
        if (! $request->headers->has('Idempotency-Key') && is_string($clave)) {
            $request->headers->set('Idempotency-Key', $clave);
        }
    }
}
