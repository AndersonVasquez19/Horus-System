<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Email;
use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Email::uniqueRule()],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
            'roles' => ['nullable', 'array', 'max:20'],
            'roles.*' => ['string', 'max:100', 'distinct', 'exists:roles,name'],
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
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ] + PasswordPolicy::messages();
    }
}
