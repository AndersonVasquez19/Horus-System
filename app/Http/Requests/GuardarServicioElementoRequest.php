<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ImagenesServicios;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crea o edita una tarjeta. Admite JSON o multipart (con fotografía). Las rutas de imagen jamás se aceptan del cliente.
 */
class GuardarServicioElementoRequest extends FormRequest
{
    public const MAX_CARACTERISTICAS = 12;

    public function authorize(): bool
    {
        return $this->user()?->can('servicios.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // En multipart, un campo vacío llega como cadena vacía; se normaliza a ausencia de valor.
        foreach (['icono', 'variante_imagen', 'imagen_alt'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
        // Una lista JSON, o un texto con una característica por línea (formulario multipart). Presente pero vacío = sin características.
        if ($this->has('caracteristicas')) {
            $lineas = $this->input('caracteristicas');
            if ($lineas === null || $lineas === '') {
                $lineas = [];
            } elseif (is_string($lineas)) {
                $lineas = preg_split('/\R/u', $lineas) ?: [];
            }
            if (is_array($lineas)) {
                $this->merge(['caracteristicas' => array_values(array_filter(array_map(
                    fn (mixed $linea): mixed => is_string($linea) ? trim($linea) : $linea, $lineas), fn (mixed $linea): bool => $linea !== '' && $linea !== null))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'descripcion' => ['required', 'string', 'max:1000', 'not_regex:/[<>]/u'],
            'caracteristicas' => ['nullable', 'array', 'max:'.self::MAX_CARACTERISTICAS],
            'caracteristicas.*' => ['string', 'max:200', 'not_regex:/[<>]/u'],
            'icono' => ['nullable', 'string', 'max:60', 'regex:/\Afa[srb] fa-[a-z0-9]+(?:-[a-z0-9]+)*\z/D'],
            'variante_imagen' => ['nullable', Rule::in(['producto', 'escena'])],
            'imagen_alt' => ['nullable', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'activo' => ['sometimes', 'boolean'],
            'imagen' => ImagenesServicios::reglas(false),
            'quitar_imagen' => ['sometimes', 'boolean'],
            'imagen_ruta' => ['missing'],
            'servicio_publicacion_id' => ['missing'],
        ];
    }
}
