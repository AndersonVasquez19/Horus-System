<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\VerificacionRegistroService;
use App\Support\Email;
use Illuminate\Foundation\Http\FormRequest;

class ReenviarRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(VerificacionRegistroService::enabled(), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        Email::normalizeRequest($this);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255']];
    }
}
