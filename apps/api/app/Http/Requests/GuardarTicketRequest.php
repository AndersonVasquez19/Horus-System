<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TicketSoporte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de un ticket. La revisión (`_revision`) la traslada `PrecondicionesPanel`. En el alta se indica el `cliente`
 * (UUID); después no cambia. `estado`, `codigo`, `asignado_a`, `vence_at`, `revision` e `identificador` no se aceptan:
 * los fijan las operaciones del servicio.
 */
class GuardarTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'soporte.crear' : 'soporte.gestionar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['asunto', 'descripcion', 'prioridad', 'canal', 'cliente', 'proyecto'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        foreach (['prioridad', 'canal', 'cliente', 'proyecto'] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $datos[$campo] = strtolower($datos[$campo]);
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asunto' => ['required', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'descripcion' => ['nullable', 'string', 'max:5000', 'not_regex:/[<>]/u'],
            'prioridad' => ['required', 'string', Rule::in(TicketSoporte::PRIORIDADES)],
            'canal' => ['required', 'string', Rule::in(TicketSoporte::CANALES)],
            'proyecto' => ['nullable', 'uuid'],
            'cliente' => $this->isMethod('POST') ? ['required', 'uuid'] : ['missing'],
            'estado' => ['missing'],
            'codigo' => ['missing'],
            'asignado_a' => ['missing'],
            'vence_at' => ['missing'],
            'revision' => ['missing'],
            'identificador' => ['missing'],
        ];
    }
}
