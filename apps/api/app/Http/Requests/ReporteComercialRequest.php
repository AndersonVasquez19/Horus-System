<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ReportesComerciales;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Filtros del reporte comercial: periodo de días completos de Lima (`desde` y `hasta` incluidos, como máximo
 * {@see ReportesComerciales::MAX_DIAS} días; por defecto los últimos 30) y, opcionalmente, un responsable.
 */
class ReporteComercialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'reportes-comerciales.exportar' : 'reportes-comerciales.ver') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpio = [];
        foreach (['desde', 'hasta', 'responsable_id'] as $campo) {
            $valor = $this->input($campo);
            $limpio[$campo] = is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        }
        $this->merge($limpio);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'responsable_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validador): void {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }
            $periodo = ReportesComerciales::periodo($this->validated('desde'), $this->validated('hasta'));
            if ($periodo['desde']->diffInDays($periodo['hasta']) >= ReportesComerciales::MAX_DIAS) {
                $validador->errors()->add('hasta', 'El periodo no puede pasar de '.ReportesComerciales::MAX_DIAS.' días.');
            }
        }];
    }

    /** @return array{desde: CarbonImmutable, hasta: CarbonImmutable} */
    public function periodo(): array
    {
        return ReportesComerciales::periodo($this->validated('desde'), $this->validated('hasta'));
    }

    public function responsable(): ?int
    {
        $valor = $this->validated('responsable_id');

        return $valor === null ? null : (int) $valor;
    }
}
