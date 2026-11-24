<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SeguimientoCobranza;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Contacto de cobro: canal, resultado, nota y, solo si el resultado es `promesa_pago`, fecha (y monto opcional) de la promesa. */
final class RegistrarSeguimientoCobranzaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `pagos.registrar` lo exige el middleware del controlador
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'canal' => ['required', 'string', Rule::in(SeguimientoCobranza::CANALES)],
            'resultado' => ['required', 'string', Rule::in(SeguimientoCobranza::RESULTADOS)],
            'nota' => ['nullable', 'string', 'max:300'],
            'promesa_fecha' => ['required_if:resultado,promesa_pago', 'prohibited_unless:resultado,promesa_pago', 'nullable', 'date_format:Y-m-d'],
            'promesa_monto' => ['prohibited_unless:resultado,promesa_pago', 'nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'promesa_fecha.required_if' => 'Indica la fecha en que el estudiante promete pagar.',
            'promesa_fecha.prohibited_unless' => 'La fecha de promesa solo aplica cuando el resultado es «promesa de pago».',
            'promesa_monto.prohibited_unless' => 'El monto de promesa solo aplica cuando el resultado es «promesa de pago».',
            'promesa_monto.regex' => 'El monto prometido debe ser un número con hasta 2 decimales.',
        ];
    }
}
