<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Email;
use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        Email::normalizeRequest($this);
    }

    /**
     * @return array<string, list<string|Closure|Password>>
     */
    public function rules(): array
    {
        /** @var User $actual */
        $actual = $this->user();

        $cambiaCorreo = $this->has('email') && (string) $this->input('email') !== $actual->email;
        $cambiaClave = $this->filled('password');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Email::uniqueRule((int) $actual->getKey())],
            'password' => ['nullable', 'string', 'confirmed', PasswordPolicy::rule()],
            // Solo se pide (y se comprueba contra la contraseña guardada) al cambiar credenciales.
            'current_password' => $cambiaCorreo || $cambiaClave ? ['required', 'string', 'current_password'] : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'current_password.required' => 'Escribe tu contraseña actual para cambiar el correo o la contraseña.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ] + PasswordPolicy::messages();
    }
}
