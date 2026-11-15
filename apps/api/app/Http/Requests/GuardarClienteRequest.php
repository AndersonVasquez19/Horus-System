<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Cliente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos de un cliente. La revisión (`_revision`) la traslada `PrecondicionesPanel`; aquí solo se validan los datos.
 * `estado`, `revision`, `identificador` y `responsable_id` no se aceptan: los fijan el servicio y sus operaciones.
 */
class GuardarClienteRequest extends FormRequest
{
    public const MAX_CONTACTOS = 10;

    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'clientes.crear' : 'clientes.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['nombre', 'documento_tipo', 'documento_numero', 'email', 'telefono', 'direccion', 'tipo'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        foreach (['email', 'documento_tipo', 'tipo'] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $datos[$campo] = strtolower($datos[$campo]);
            }
        }
        if (isset($datos['documento_numero']) && is_string($datos['documento_numero'])) {
            $datos['documento_numero'] = strtoupper(str_replace(' ', '', $datos['documento_numero']));
        }
        if (is_array($this->input('contactos'))) {
            $datos['contactos'] = array_map(function (mixed $contacto) use ($limpiar): mixed {
                if (! is_array($contacto)) {
                    return $contacto;
                }
                foreach (['nombre', 'cargo', 'email', 'telefono'] as $campo) {
                    if (array_key_exists($campo, $contacto)) {
                        $contacto[$campo] = $limpiar($contacto[$campo]);
                    }
                }
                if (isset($contacto['email']) && is_string($contacto['email'])) {
                    $contacto['email'] = strtolower($contacto['email']);
                }

                return $contacto;
            }, $this->input('contactos'));
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(Cliente::TIPOS)],
            'nombre' => ['required', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'documento_tipo' => ['nullable', 'string', Rule::in(Cliente::DOCUMENTOS), 'required_with:documento_numero'],
            'documento_numero' => ['nullable', 'string', 'max:20', 'regex:/\A[A-Z0-9-]+\z/D', 'required_with:documento_tipo'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/\A[0-9+() -]{6,30}\z/D'],
            'direccion' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/u'],
            'contactos' => ['sometimes', 'array', 'max:'.self::MAX_CONTACTOS],
            'contactos.*' => ['array'],
            'contactos.*.nombre' => ['required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'contactos.*.cargo' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'contactos.*.email' => ['nullable', 'string', 'email:rfc', 'max:160'],
            'contactos.*.telefono' => ['nullable', 'string', 'max:30', 'regex:/\A[0-9+() -]{6,30}\z/D'],
            'contactos.*.principal' => ['sometimes', 'boolean'],
            'estado' => ['missing'],
            'revision' => ['missing'],
            'identificador' => ['missing'],
            'responsable_id' => ['missing'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validador): void {
            $datos = $validador->getData();

            // Formato del documento según su tipo (DNI: 8 dígitos; RUC: 11 dígitos).
            $tipo = $datos['documento_tipo'] ?? null;
            $numero = $datos['documento_numero'] ?? null;
            if (is_string($tipo) && is_string($numero)) {
                if ($tipo === 'dni' && preg_match('/\A[0-9]{8}\z/D', $numero) !== 1) {
                    $validador->errors()->add('documento_numero', 'El DNI debe tener 8 dígitos.');
                }
                if ($tipo === 'ruc' && preg_match('/\A[0-9]{11}\z/D', $numero) !== 1) {
                    $validador->errors()->add('documento_numero', 'El RUC debe tener 11 dígitos.');
                }
            }

            // Una empresa no se identifica con DNI.
            if (($datos['tipo'] ?? null) === 'empresa' && $tipo === 'dni') {
                $validador->errors()->add('documento_tipo', 'Una empresa no se identifica con DNI.');
            }

            $contactos = $datos['contactos'] ?? [];
            if (is_array($contactos) && count(array_filter($contactos, fn (mixed $c): bool => is_array($c) && filter_var($c['principal'] ?? false, FILTER_VALIDATE_BOOLEAN))) > 1) {
                $validador->errors()->add('contactos', 'Solo un contacto puede ser el principal.');
            }
        }];
    }
}
