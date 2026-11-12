<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Las tres páginas públicas administrables de Servicios Tecnológicos y su código de servicio.
 * El código es el identificador estable (único e inmutable) del servicio en el panel.
 */
final class PaginasServicios
{
    public const CABLEADO = 'CABLEADO';

    public const VIDEOVIGILANCIA = 'VIDEOVIGILANCIA';

    public const SOPORTE = 'SOPORTE';

    /** @var array<string, array{etiqueta: string, ruta: string}> */
    private const PAGINAS = [
        self::CABLEADO => ['etiqueta' => 'Cableado estructurado', 'ruta' => 'tecnologias.cableado'],
        self::VIDEOVIGILANCIA => ['etiqueta' => 'Sistema de videovigilancia', 'ruta' => 'tecnologias.camaras'],
        self::SOPORTE => ['etiqueta' => 'Soporte y mantenimiento', 'ruta' => 'tecnologias.soporte'],
    ];

    /** @return list<string> */
    public static function codigos(): array
    {
        return array_keys(self::PAGINAS);
    }

    /** @return array<string, array{etiqueta: string, ruta: string}> */
    public static function todas(): array
    {
        return self::PAGINAS;
    }

    /** @return array{codigo: string, etiqueta: string, ruta: string, url: string}|null */
    public static function de(?string $codigo): ?array
    {
        if ($codigo === null || ! isset(self::PAGINAS[$codigo])) {
            return null;
        }
        $pagina = self::PAGINAS[$codigo];

        return ['codigo' => $codigo, 'etiqueta' => $pagina['etiqueta'], 'ruta' => $pagina['ruta'], 'url' => route($pagina['ruta'], [], false)];
    }
}
