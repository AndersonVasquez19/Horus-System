<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Ajustes institucionales editables en Configuración (tabla `system_settings`) y su lectura en el panel y los
 * PDF administrativos. Se leen en una sola consulta por petición y sin caché entre peticiones, de modo que un
 * cambio se ve en la siguiente página. Un valor vacío o ausente usa el predeterminado.
 *
 * No alimenta el sitio público ni los correos: ambos tienen sus propios textos revisados.
 */
final class ConfiguracionSistema
{
    /**
     * Claves editables y su valor por defecto (datos publicados en el sitio de la empresa).
     *
     * @var array<string, string>
     */
    public const PREDETERMINADOS = [
        'nombre_empresa' => 'Horus Group SRL',
        'telefono' => '+51 972 021 374',
        'correo_contacto' => 'horusgroupcajamarca@gmail.com',
        'direccion' => 'Jr. José Gálvez 322, Cajamarca',
        'moneda' => 'S/',
    ];

    private const ATRIBUTO = 'horus.configuracion_sistema';

    /** @return array<string, string> */
    public static function todos(): array
    {
        $peticion = request();
        $memoria = $peticion->attributes->get(self::ATRIBUTO);
        if (is_array($memoria)) {
            /** @var array<string, string> $memoria */
            return $memoria;
        }

        $guardados = SystemSetting::query()
            ->whereIn('key', array_keys(self::PREDETERMINADOS))
            ->pluck('value', 'key');

        $valores = [];
        foreach (self::PREDETERMINADOS as $clave => $predeterminado) {
            $valor = $guardados[$clave] ?? null;
            $valores[$clave] = is_string($valor) && trim($valor) !== '' ? $valor : $predeterminado;
        }

        $peticion->attributes->set(self::ATRIBUTO, $valores);

        return $valores;
    }

    /** Descarta la lectura memorizada de esta petición (tras guardar cambios). */
    public static function olvidar(): void
    {
        request()->attributes->remove(self::ATRIBUTO);
    }

    public static function nombreEmpresa(): string
    {
        return self::todos()['nombre_empresa'];
    }

    public static function moneda(): string
    {
        return self::todos()['moneda'];
    }

    /** Importe con el símbolo configurado: «S/ 1,234.50». Un valor nulo se muestra como 0.00. */
    public static function monto(float|int|string|null $importe): string
    {
        return self::moneda().' '.number_format((float) $importe, 2, '.', ',');
    }
}
