<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\VerificacionRegistroService;
use App\Support\PasswordPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ConfirmarRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(VerificacionRegistroService::enabled(), 404);

        return true;
    }

    /** @return array<string, list<string|Password>> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
            'terms' => ['accepted']];
    }

    protected function failedValidation(Validator $validator): never
    {
        // Endpoints backend: JSON incluso si se invocan desde el grupo web, sin flash de secretos.
        throw new ValidationException($validator, response()->json(['message' => 'Los datos de confirmación no son válidos.',
            'errors' => $validator->errors()->messages()], 422));
    }
}
