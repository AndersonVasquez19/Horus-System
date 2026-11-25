<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Vocabulario cerrado de intenciones de Horus AI que se pueden guardar en el registro del chat. Lo que no esté en la
 * lista se anota como `otro`: así la columna nunca recibe un valor arbitrario.
 */
final class IntencionesAsistente
{
    public const OTRO = 'otro';

    public const CONOCIDAS = [
        'acknowledgement', 'advisory_info', 'agreement_info', 'capabilities', 'clarification', 'company_info',
        'contact_info', 'course_recommendation', 'enrollment_request', 'faq', 'farewell', 'followup_attribute',
        'greeting', 'human_handoff', 'out_of_scope', 'policy', 'prompt_injection', 'quote_request',
        'sensitive_request', 'service_info', 'service_recommendation', 'thanks', 'training_info',
        'unavailable_info', 'unverified_info',
    ];

    /** Intenciones en las que el asistente reconoce que no tiene el dato publicado. */
    public const SIN_DATO = ['unavailable_info', 'unverified_info', 'out_of_scope'];

    public static function normalizar(mixed $intencion): ?string
    {
        if (! is_string($intencion) || $intencion === '') {
            return null;
        }

        return in_array($intencion, self::CONOCIDAS, true) ? $intencion : self::OTRO;
    }
}
