<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class VisibilidadTecnologias
{
    public const ORIENTACION = 'Puedo orientarte sobre cursos, capacitaciones, asesoramiento y matrículas. ¿Qué información necesitas? También puedes consultar /contactos.';

    public static function contieneContenido(string $texto): bool
    {
        $normalizado = Str::ascii(mb_strtolower($texto));

        return preg_match('~(?:/tecnologias/|/solicitar-cotizacion|servicio[:_]|"tipo"\s*:\s*"servicio"|\b(?:tecnologias?|tecnologic[oa]s?|camaras?|cctv|videovigilancia|cableado|redes? lan|soporte tecnico|mantenimiento|cotizacion|cotizaciones)\b)~u', $normalizado) === 1;
    }

    /** @param array<string, mixed> $respuesta */
    public static function respuestaOculta(array $respuesta): bool
    {
        return ! config('tecnologias.visible')
            && self::contieneContenido((string) json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
