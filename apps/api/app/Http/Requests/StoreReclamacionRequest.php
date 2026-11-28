<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\GestionReclamaciones;
use App\Support\AntispamReclamaciones;
use App\Support\Email;
use App\Support\Entrada;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del alta pública del Libro de Reclamaciones (Anexo I / art. 5). Sin login. Aún no está conectada a
 * ninguna ruta (Fase 3). Incluye honeypot, marca de tiempo firmada y un token de envío (UUID) para idempotencia.
 * Si falta nombre, documento, domicilio, correo o detalle, la reclamación se considera no presentada.
 */
class StoreReclamacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = fn (mixed $v): mixed => is_string($v) ? trim($v) : $v;
        $campos = ['nombres', 'apellidos', 'numero_documento', 'domicilio', 'telefono', 'representante_nombre',
            'representante_domicilio', 'representante_telefono', 'bien_descripcion', 'detalle', 'pedido'];
        $mezcla = [];
        foreach ($campos as $campo) {
            if ($this->has($campo)) {
                $mezcla[$campo] = $limpiar($this->input($campo));
            }
        }
        if ($this->has('email')) {
            $mezcla['email'] = Email::normalize($this->input('email'));
        }
        if ($this->has('representante_email')) {
            $mezcla['representante_email'] = Email::normalize($this->input('representante_email'));
        }
        if ($this->has('numero_documento') && is_string($this->input('numero_documento'))) {
            $mezcla['numero_documento'] = strtoupper(preg_replace('/\s+/', '', $this->input('numero_documento')) ?? '');
        }
        $this->merge($mezcla);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $menor = fn () => $this->boolean('es_menor');
        $reclamo = fn () => $this->input('tipo') === 'reclamo';
        $honeypot = Entrada::texto(config('reclamaciones.antispam.honeypot', 'sitio_web'));

        return [
            $honeypot => ['nullable', 'max:0'],
            'marca' => ['required', 'string'],
            'token_envio' => ['required', 'uuid'],
            'tipo' => ['required', Rule::in(['reclamo', 'queja'])],
            'nombres' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u', 'regex:/\A[\p{L}\s\'\-.]+\z/u', 'regex:/\p{L}/u'],
            'apellidos' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u', 'regex:/\A[\p{L}\s\'\-.]+\z/u', 'regex:/\p{L}/u'],
            'tipo_documento' => ['required', Rule::in(['dni', 'ce', 'pasaporte', 'ruc'])],
            'numero_documento' => ['required', 'string', 'max:20'],
            'domicilio' => ['required', 'string', 'min:5', 'max:255', 'not_regex:/[<>]/u'],
            'telefono' => ['required', 'string', 'max:30', 'regex:/\A[+0-9() .-]{5,30}\z/D'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'canal_respuesta' => ['sometimes', Rule::in(['correo', 'carta'])],
            'es_menor' => ['sometimes', 'boolean'],
            'representante_nombre' => [Rule::requiredIf($menor), 'nullable', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u', 'regex:/\A[\p{L}\s\'\-.]+\z/u', 'regex:/\p{L}/u'],
            'representante_domicilio' => [Rule::requiredIf($menor), 'nullable', 'string', 'max:255', 'not_regex:/[<>]/u'],
            'representante_telefono' => [Rule::requiredIf($menor), 'nullable', 'string', 'max:30', 'regex:/\A[+0-9() .-]{5,30}\z/D'],
            'representante_email' => [Rule::requiredIf($menor), 'nullable', 'email:rfc', 'max:254'],
            'bien_tipo' => [Rule::requiredIf($reclamo), 'nullable', Rule::in(['producto', 'servicio'])],
            'bien_descripcion' => ['required_with:bien_tipo', Rule::requiredIf($reclamo), 'nullable', 'string', 'min:5', 'max:500', 'not_regex:/[<>]/u', 'regex:/\p{L}/u'],
            'monto_no_aplica' => ['sometimes', 'boolean'],
            'monto_bien' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'detalle' => ['required', 'string', 'min:10', 'max:3000', 'not_regex:/[<>]/u', 'regex:/\p{L}/u'],
            'pedido' => ['required', 'string', 'min:5', 'max:1500', 'not_regex:/[<>]/u', 'regex:/\p{L}/u'],
            // Campos que el servidor asigna: el cliente no puede enviarlos.
            'estado' => ['prohibited'], 'numero' => ['prohibited'], 'identificador' => ['prohibited'],
            'fecha_limite' => ['prohibited'], 'responsable_id' => ['prohibited'], 'respuesta' => ['prohibited'],
            'recibida_at' => ['prohibited'], 'canal' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombres.regex' => 'Ingresa nombres válidos usando solo letras.',
            'apellidos.regex' => 'Ingresa apellidos válidos usando solo letras.',
            'representante_nombre.regex' => 'Ingresa un nombre válido del representante usando solo letras.',
            'bien_descripcion.min' => 'Describe el producto o servicio con al menos 5 caracteres.',
            'bien_descripcion.regex' => 'La descripción del bien debe incluir texto válido.',
            'detalle.regex' => 'El detalle debe incluir una descripción legible.',
            'pedido.regex' => 'El pedido debe incluir una descripción legible.',
            'monto_bien.decimal' => 'El monto admite hasta dos decimales.',
            'bien_tipo.required' => 'Indica si el reclamo es sobre un producto o un servicio.',
            'bien_descripcion.required' => 'Describe el producto o servicio.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! AntispamReclamaciones::marcaValida($this->input('marca'))) {
                $v->errors()->add('marca', 'El formulario caducó o se envió demasiado rápido. Inténtalo de nuevo.');
            }
            $numero = Entrada::texto($this->input('numero_documento'));
            $valido = match ($this->input('tipo_documento')) {
                'dni' => preg_match('/\A\d{8}\z/D', $numero) === 1 && $numero !== '00000000',
                'ruc' => self::rucPeruanoValido($numero),
                'ce' => preg_match('/\A[A-Z0-9]{9,12}\z/D', $numero) === 1,
                'pasaporte' => preg_match('/\A[A-Z0-9]{6,12}\z/D', $numero) === 1,
                default => true,
            };
            if (! $valido && ! $v->errors()->has('numero_documento')) {
                $v->errors()->add('numero_documento', 'El número de documento no tiene el formato del tipo elegido.');
            }
            foreach (['telefono', 'representante_telefono'] as $campoTelefono) {
                $valorTel = $this->input($campoTelefono);
                if (is_string($valorTel) && $valorTel !== '' && ! $v->errors()->has($campoTelefono)) {
                    $digitos = preg_replace('/\D+/', '', $valorTel) ?? '';
                    if (strlen($digitos) < 7 || strlen($digitos) > 15) {
                        $v->errors()->add($campoTelefono, 'Ingresa un teléfono válido de entre 7 y 15 dígitos.');
                    }
                }
            }
            // Reclamo: monto obligatorio (> 0) salvo que se marque «no aplica».
            if ($this->input('tipo') === 'reclamo' && ! $this->boolean('monto_no_aplica') && ! $v->errors()->has('monto_bien')) {
                $monto = $this->input('monto_bien');
                if (blank($monto)) {
                    $v->errors()->add('monto_bien', 'Indica el monto o marca «no aplica».');
                } elseif (is_numeric($monto) && (float) $monto <= 0.0) {
                    $v->errors()->add('monto_bien', 'El monto debe ser mayor a cero o marca «no aplica».');
                }
            }
        });
    }

    /** Valida RUC peruano (11 dígitos, prefijo SUNAT válido y dígito verificador módulo 11). */
    private static function rucPeruanoValido(string $ruc): bool
    {
        if (preg_match('/\A(10|15|16|17|20)\d{9}\z/D', $ruc) !== 1) {
            return false;
        }
        $factores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += ((int) $ruc[$i]) * $factores[$i];
        }
        $complemento = 11 - ($suma % 11);
        $verificador = match ($complemento) {
            10 => 0,
            11 => 1,
            default => $complemento,
        };

        return $verificador === (int) $ruc[10];
    }

    /**
     * Datos listos para {@see GestionReclamaciones}: aplica «no aplica» (monto nulo, nunca 0 inventado).
     *
     * @return array<string, mixed>
     */
    public function datosReclamacion(): array
    {
        $datos = $this->safe()->except(['marca', 'token_envio', 'monto_no_aplica', Entrada::texto(config('reclamaciones.antispam.honeypot', 'sitio_web'))]);
        if ($this->boolean('monto_no_aplica') || blank($datos['monto_bien'] ?? null)) {
            $datos['monto_bien'] = null;
        }
        // Booleanos reales para el servicio: boolean() interpreta "1", "true", "on", "yes" como true y
        // "0", "false", "off", "no", vacío o ausente como false (la regla «boolean» ya rechazó el resto).
        $datos['es_menor'] = $this->boolean('es_menor');

        return $datos;
    }

    public function tokenEnvio(): string
    {
        return Entrada::texto($this->validated('token_envio'));
    }
}
