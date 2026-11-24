<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Cierre de caja: fecha, importe contado por método (código → importe) y observación (obligatoria si hay diferencia; lo valida el servicio). */
final class CerrarCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso `pagos.cerrar_caja` lo exige el middleware del controlador
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'declarado' => ['present', 'array', 'max:20'],
            'declarado.*' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'declarado.*.regex' => 'El importe contado debe ser un número con hasta 2 decimales.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
        ];
    }
}
