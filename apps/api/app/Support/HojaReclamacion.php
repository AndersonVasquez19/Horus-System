<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Reclamacion;

/**
 * Texto de la Hoja de Reclamación (Anexo I) para la copia al consumidor. El resultado se guarda tal cual
 * en `reclamacion_envios.cuerpo`, que es la constancia de lo remitido. El encabezado procede de la
 * configuración; los datos del proveedor que faltan se rotulan «no configurado», nunca se inventan.
 */
final class HojaReclamacion
{
    public static function asunto(Reclamacion $r): string
    {
        $razon = self::proveedor('razon_social');

        return 'Hoja de Reclamación N° '.$r->numero.($razon !== null ? ' | '.$razon : '');
    }

    public static function cuerpo(Reclamacion $r): string
    {
        $linea = fn (string $etiqueta, ?string $valor): string => $etiqueta.': '.($valor !== null && $valor !== '' ? $valor : '—');
        $no = 'no configurado';
        $texto = [
            'LIBRO DE RECLAMACIONES — HOJA DE RECLAMACIÓN',
            $linea('Razón social', self::proveedor('razon_social') ?? $no),
            $linea('RUC', self::proveedor('ruc') ?? $no),
            $linea('Dirección del establecimiento', self::proveedor('direccion') ?? $no),
            $linea('Código de identificación del libro', self::proveedor('codigo_libro') ?? $no),
            $linea('Número', $r->numero),
            $linea('Fecha de registro', $r->recibida_at->setTimezone((string) config('reclamaciones.zona_horaria', 'America/Lima'))->format('d/m/Y H:i')),
            '',
            '1. IDENTIFICACIÓN DEL CONSUMIDOR RECLAMANTE',
            $linea('Nombres y apellidos', $r->nombres.' '.$r->apellidos),
            $linea('Documento', strtoupper($r->tipo_documento).' '.$r->numero_documento),
            $linea('Domicilio', $r->domicilio),
            $linea('Teléfono', $r->telefono),
            $linea('Correo electrónico', $r->email),
        ];
        if ($r->es_menor) {
            $texto[] = $linea('Representante (menor de edad)', $r->representante_nombre);
            $texto[] = $linea('Domicilio del representante', $r->representante_domicilio);
            $texto[] = $linea('Teléfono del representante', $r->representante_telefono);
            $texto[] = $linea('Correo del representante', $r->representante_email);
        }
        $texto[] = '';
        $texto[] = '2. IDENTIFICACIÓN DEL BIEN CONTRATADO';
        $texto[] = $linea('Tipo', $r->bien_tipo);
        $texto[] = $linea('Descripción', $r->bien_descripcion);
        $texto[] = $linea('Monto', $r->monto_bien !== null ? 'S/ '.$r->monto_bien : null);
        $texto[] = '';
        $texto[] = '3. DETALLE DE LA RECLAMACIÓN Y PEDIDO DEL CONSUMIDOR';
        $texto[] = $linea('Tipo', $r->tipo === 'reclamo' ? 'Reclamo' : 'Queja');
        $texto[] = 'Detalle: '.$r->detalle;
        $texto[] = 'Pedido: '.$r->pedido;
        $texto[] = $linea('Respuesta solicitada por', $r->canal_respuesta === 'carta' ? 'carta' : 'correo electrónico');
        $texto[] = '';
        $texto[] = 'Fecha límite de respuesta: '.$r->fecha_limite->format('d/m/Y');
        $texto[] = 'Conserve esta copia como constancia de su reclamación.';

        return implode("\n", $texto);
    }

    private static function proveedor(string $clave): ?string
    {
        $valor = config('reclamaciones.proveedor.'.$clave);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }
}
