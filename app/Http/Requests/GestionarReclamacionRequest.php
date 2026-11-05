<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de las operaciones de gestión del panel sobre una reclamación (sin rutas todavía: Fase 4).
 * El campo `operacion` selecciona las reglas y el permiso. No existen operaciones de edición ni eliminación:
 * los datos del consumidor son inmutables.
 */
class GestionarReclamacionRequest extends FormRequest
{
    private const PERMISOS = [
        'responsable' => 'reclamaciones.gestionar', 'tipo' => 'reclamaciones.gestionar', 'nota' => 'reclamaciones.gestionar',
        'propuesta' => 'reclamaciones.gestionar', 'rechazo' => 'reclamaciones.gestionar', 'reintento' => 'reclamaciones.gestionar',
        'aceptacion' => 'reclamaciones.responder', 'respuesta' => 'reclamaciones.responder',
    ];

    public function authorize(): bool
    {
        $permiso = self::PERMISOS[(string) $this->input('operacion')] ?? null;

        return $permiso !== null && $this->user() !== null && $this->user()->can($permiso);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = ['operacion' => ['required', Rule::in(array_keys(self::PERMISOS))]];

        return $base + match ((string) $this->input('operacion')) {
            'responsable' => ['responsable_id' => ['present', 'nullable', 'integer', 'min:1']],
            'tipo' => ['tipo' => ['required', Rule::in(['reclamo', 'queja'])]],
            'nota' => ['nota' => ['required', 'string', 'max:2000']],
            'propuesta' => ['asunto' => ['required', 'string', 'max:200'], 'cuerpo' => ['required', 'string', 'max:10000']],
            'rechazo' => ['fecha_recepcion' => ['required', 'date_format:Y-m-d'], 'nota' => ['nullable', 'string', 'max:2000']],
            'aceptacion' => [
                'fecha_recepcion' => ['required', 'date_format:Y-m-d'],
                'medio' => ['required', Rule::in(['correo', 'otro'])],
                'constancia' => ['required', 'string', 'max:2000'],
            ],
            'respuesta' => [
                'respuesta' => ['required', 'string', 'max:10000'],
                'canal' => ['required', Rule::in(['correo', 'carta'])],
                'constancia' => ['required_if:canal,carta', 'nullable', 'string', 'max:255'],
            ],
            'reintento' => ['envio_id' => ['required', 'integer', 'min:1']],
            default => [],
        };
    }
}
