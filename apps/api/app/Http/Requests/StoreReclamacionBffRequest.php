<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Alta del Libro de Reclamaciones recibida por el BFF de apps/web (Fase 2c.1). Reutiliza TODAS las reglas de
 * {@see StoreReclamacionRequest} (formato Anexo I, honeypot, marca firmada, token de idempotencia, campos prohibidos)
 * y solo añade la declaración que en el sitio Blade hace la casilla del paso 3: veracidad y conocimiento del
 * tratamiento de datos. El servidor es quien registra la versión de privacidad informada (`privacidad_version`).
 */
class StoreReclamacionBffRequest extends StoreReclamacionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'acepta_privacidad' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'acepta_privacidad.required' => 'Debes aceptar la declaración para enviar la hoja.',
            'acepta_privacidad.accepted' => 'Debes aceptar la declaración para enviar la hoja.',
        ];
    }

    /** @return array<string, mixed> */
    public function datosReclamacion(): array
    {
        $datos = parent::datosReclamacion();
        unset($datos['acepta_privacidad']);

        return $datos;
    }
}
