<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Política única de contraseñas de cuentas web/API/panel, para que ninguna vía de entrada
 * (registro, reset, alta desde el panel) acepte una contraseña más débil que las demás.
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        // bcrypt solo considera los primeros 72 bytes: más allá de eso la contraseña se truncaría sin avisar.
        return Password::min(10)->max(72)->letters()->mixedCase()->numbers();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.max' => 'La contraseña no puede superar los 72 caracteres.',
            'password.letters' => 'La contraseña debe incluir letras.',
            'password.mixed' => 'Combina mayúsculas y minúsculas en tu contraseña.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
        ];
    }
}
