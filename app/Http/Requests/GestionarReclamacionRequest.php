<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\GestionReclamaciones;
use App\Support\Reclamaciones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de las operaciones de gestión del panel sobre una reclamación (E4,
 * `POST admin/reclamaciones/{uuid}/gestion`). El campo `operacion` selecciona las reglas y el permiso; una
 * operación desconocida no se autoriza (403). No existen operaciones de edición ni eliminación: los datos del
 * consumidor son inmutables. El servicio {@see GestionReclamaciones} vuelve a comprobar permiso,
 * estado y reglas bajo bloqueo: este request solo filtra la entrada.
 *
 * Cuando el envío de correos del libro está activo, las operaciones que remiten un correo real al consumidor
 * (propuesta, respuesta por correo y reintento) exigen `confirmar_envio`.
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

    /** La operación enviará un correo real al consumidor si se completa. */
    public function enviaCorreoReal(): bool
    {
        if (! Reclamaciones::envioHabilitado()) {
            return false;
        }

        return match ((string) $this->input('operacion')) {
            'propuesta', 'reintento' => true,
            'respuesta' => $this->input('canal') === 'correo',
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = ['operacion' => ['required', Rule::in(array_keys(self::PERMISOS))]];
        $confirmacion = $this->enviaCorreoReal() ? ['confirmar_envio' => ['accepted']] : [];

        return $base + $confirmacion + match ((string) $this->input('operacion')) {
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmar_envio.accepted' => 'Confirma que deseas enviar un correo real al consumidor.',
            'responsable_id.integer' => 'Selecciona un responsable de la lista.',
            'constancia.required_if' => 'Para una respuesta por carta indica fecha, medio y cargo o guía.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'responsable_id' => 'responsable',
            'tipo' => 'tipo de registro',
            'nota' => 'nota',
            'asunto' => 'asunto',
            'cuerpo' => 'texto de la propuesta',
            'fecha_recepcion' => 'fecha de recepción',
            'medio' => 'medio',
            'constancia' => 'constancia',
            'respuesta' => 'respuesta',
            'canal' => 'canal',
            'envio_id' => 'envío',
        ];
    }
}
