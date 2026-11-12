<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AprobacionInterna;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAprobacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aprobacion = $this->route('aprobacion');
        $actor = $this->user();

        if ($aprobacion instanceof AprobacionInterna && $actor instanceof User) {
            abort_if($aprobacion->estado !== AprobacionInterna::ESTADO_PENDIENTE, 409, 'No se puede modificar una solicitud que ya ha sido resuelta.');
            abort_unless($aprobacion->esSolicitante($actor) || $actor->hasRole('admin'), 403, 'Solo el solicitante o Gerencia pueden modificar esta solicitud.');
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'solicitante_id' => ['nullable', 'integer', 'exists:users,id'],
            'aprobador_id' => ['nullable', 'integer', 'exists:users,id'],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'string'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $aprobacion = $this->route('aprobacion');
                if (! $aprobacion instanceof AprobacionInterna) {
                    return;
                }

                $estado = $this->input('estado');
                if ($estado !== null && $estado !== $aprobacion->estado) {
                    $validator->errors()->add('estado', 'El estado no puede modificarse mediante edición ordinaria. Utilice las operaciones específicas de aprobación, rechazo o reapertura.');
                }

                if ($this->input('tipo') !== $aprobacion->tipo) {
                    $validator->errors()->add('tipo', 'El tipo de solicitud no puede cambiarse: determina la autoridad que la resuelve.');
                }

                $solicitanteId = $this->input('solicitante_id');
                if ($solicitanteId !== null && (int) $solicitanteId !== (int) $aprobacion->solicitante_id) {
                    $validator->errors()->add('solicitante_id', 'No se puede sustituir al solicitante original de una solicitud.');
                }

                $aprobadorId = $this->input('aprobador_id');
                if ($aprobadorId !== null && (int) $aprobadorId !== (int) $aprobacion->aprobador_id) {
                    $validator->errors()->add('aprobador_id', 'El responsable de la decisión solo se registra al aprobar o rechazar.');
                }
            },
        ];
    }
}
