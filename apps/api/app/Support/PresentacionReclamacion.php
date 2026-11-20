<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\ReclamacionHistorial;
use Carbon\CarbonImmutable;

/**
 * Presentación de solo lectura del expediente en el panel (E3): etiquetas del historial y de los envíos.
 *
 * Devuelve texto plano que la vista imprime con escape normal de Blade. Solo usa claves conocidas de los
 * metadatos (nunca vuelca el JSON completo) y nunca muestra cuerpos de correo, claves de idempotencia ni
 * identificadores del proveedor. El destinatario de un correo se muestra enmascarado.
 */
final class PresentacionReclamacion
{
    private const EVENTOS = [
        'registrada' => 'Reclamación registrada',
        'hoja_respaldo_registrada' => 'Hoja del libro físico de respaldo registrada',
        'responsable_asignado' => 'Responsable asignado',
        'tipo_reclasificado' => 'Tipo reclasificado',
        'nota_interna' => 'Nota interna',
        'propuesta_enviada' => 'Propuesta de solución enviada',
        'propuesta_aceptada' => 'Propuesta de solución aceptada',
        'propuesta_rechazada' => 'Propuesta de solución rechazada',
        'propuesta_vencida' => 'Propuesta de solución vencida (plazo reanudado)',
        'respuesta_formal_registrada' => 'Respuesta formal registrada',
        'correo_enviado' => 'Correo enviado',
        'correo_fallido' => 'Correo no enviado',
        'carta_registrada' => 'Carta registrada',
    ];

    private const TIPOS_ENVIO = [
        'copia_hoja' => 'Copia de la hoja al consumidor',
        'propuesta' => 'Propuesta de solución',
        'respuesta' => 'Respuesta formal',
    ];

    private const CANALES = [
        'web' => 'Plataforma web',
        'respaldo' => 'Libro físico de respaldo',
        'correo' => 'Correo electrónico',
        'carta' => 'Carta',
    ];

    private const ESTADOS_ENVIO = [
        'pendiente' => 'Pendiente',
        'procesando' => 'En proceso',
        'enviado' => 'Enviado',
        'fallido' => 'Fallido',
    ];

    private const ESTADOS_CASO = [
        'abierto' => 'Abierto',
        'respondido' => 'Respondido',
        'concluido_por_acuerdo' => 'Concluido por acuerdo',
    ];

    public static function evento(string $evento): string
    {
        return self::EVENTOS[$evento] ?? ucfirst(str_replace('_', ' ', $evento));
    }

    /**
     * Cambio de estado del caso que el evento implica (según las reglas del servicio), si lo hay.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function transicion(ReclamacionHistorial $h): ?array
    {
        $tipo = self::metadato($h, 'tipo');

        return match (true) {
            $h->evento === 'propuesta_aceptada' => [self::ESTADOS_CASO['abierto'], self::ESTADOS_CASO['concluido_por_acuerdo']],
            $h->evento === 'carta_registrada' && $tipo === 'respuesta',
            $h->evento === 'correo_enviado' && $tipo === 'respuesta' => [self::ESTADOS_CASO['abierto'], self::ESTADOS_CASO['respondido']],
            default => null,
        };
    }

    /**
     * Detalles legibles de los metadatos conocidos del evento (sin datos personales: los metadatos no los tienen).
     *
     * @return list<string>
     */
    public static function detalles(ReclamacionHistorial $h): array
    {
        $d = [];
        $tipo = self::metadato($h, 'tipo');
        $anterior = self::metadato($h, 'anterior');

        if ($h->evento === 'tipo_reclasificado') {
            $d[] = 'Tipo: '.self::tipoCaso($anterior).' → '.self::tipoCaso($tipo);
        } elseif ($h->evento === 'responsable_asignado') {
            $d[] = 'Responsable: '.self::usuario($anterior).' → '.self::usuario(self::metadato($h, 'responsable_id'));
        } elseif ($tipo !== null) {
            $d[] = 'Envío: '.(self::TIPOS_ENVIO[$tipo] ?? $tipo);
        }
        if (($canal = self::metadato($h, 'canal')) !== null) {
            $d[] = 'Canal: '.(self::CANALES[$canal] ?? $canal);
        }
        if (($medio = self::metadato($h, 'medio')) !== null) {
            $d[] = 'Medio: '.(self::CANALES[$medio] ?? $medio);
        }
        if (($envio = self::metadato($h, 'envio_id')) !== null) {
            $d[] = 'Envío N.º '.$envio;
        }
        foreach (['fecha_recepcion' => 'Fecha de recepción', 'suspension_desde' => 'Suspensión desde', 'suspension_hasta' => 'Suspensión hasta',
            'fecha_limite' => 'Fecha límite', 'fecha_limite_nueva' => 'Nueva fecha límite'] as $clave => $etiqueta) {
            if (($fecha = self::metadato($h, $clave)) !== null) {
                $d[] = $etiqueta.': '.self::fecha($fecha);
            }
        }
        if (($dias = self::metadato($h, 'dias_suspendidos')) !== null) {
            $d[] = 'Días hábiles suspendidos: '.$dias;
        }
        if ($h->evento === 'correo_fallido' && ($resultado = self::metadato($h, 'resultado')) !== null) {
            $d[] = 'Resultado: '.str_replace('_', ' ', $resultado);
        }

        return $d;
    }

    public static function tipoEnvio(string $tipo): string
    {
        return self::TIPOS_ENVIO[$tipo] ?? $tipo;
    }

    public static function canal(string $canal): string
    {
        return self::CANALES[$canal] ?? $canal;
    }

    public static function estadoEnvio(string $estado): string
    {
        return self::ESTADOS_ENVIO[$estado] ?? $estado;
    }

    /**
     * Destinatario del envío. El correo siempre se dirige al correo del consumidor (EnvioReclamaciones) y se
     * muestra enmascarado; la carta se dirige al domicilio registrado (sección de datos del consumidor).
     */
    public static function destinatario(Reclamacion $r, ReclamacionEnvio $envio): string
    {
        return $envio->canal === 'carta' ? 'Domicilio registrado del consumidor' : self::correoEnmascarado((string) $r->email);
    }

    public static function correoEnmascarado(string $correo): string
    {
        $arroba = mb_strrpos($correo, '@');
        if ($arroba === false || $arroba === 0) {
            return '••••';
        }

        return mb_substr($correo, 0, min(2, $arroba)).'•••'.mb_substr($correo, $arroba);
    }

    private static function metadato(ReclamacionHistorial $h, string $clave): ?string
    {
        // `metadatos` es JSON con cast `array` (columna NOT NULL); solo se leen claves conocidas.
        $valor = $h->metadatos[$clave] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }

    private static function tipoCaso(?string $tipo): string
    {
        return match ($tipo) {
            'queja' => 'Queja',
            'reclamo' => 'Reclamo',
            default => 'sin dato',
        };
    }

    private static function usuario(?string $id): string
    {
        return $id === null ? 'sin asignar' : 'usuario N.º '.$id;
    }

    private static function fecha(string $valor): string
    {
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor);

        return $fecha !== null && $fecha->format('Y-m-d') === $valor ? $fecha->format('d/m/Y') : $valor;
    }
}
