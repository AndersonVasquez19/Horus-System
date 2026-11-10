<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Email;
use App\Support\PasswordPolicy;
use App\Support\SessionRevoker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    public function create(string $token): Response
    {
        // El token viaja en la URL: ni se envía como Referer ni se guarda en cachés compartidas o del navegador.
        return response()->view('auth.reset-password', [
            'token' => $token,
            'email' => request('email'),
        ])->withHeaders([
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Email::normalizeRequest($request);
        try {
            $validated = $request->validate([
                'token' => ['required', 'string'],
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
            ], [
                'token.required' => 'El token es obligatorio.',
                'email.required' => 'El correo electrónico es obligatorio.',
                'email.email' => 'Ingresa un correo electrónico válido.',
                'password.required' => 'La contraseña es obligatoria.',
                'password.confirmed' => 'La confirmación de contraseña no coincide.',
            ] + PasswordPolicy::messages());
        } catch (ValidationException $error) {
            // El manejador de validación repone la entrada de ESTA petición al redirigir.
            $request->replace($request->only('email'));
            throw $error;
        }

        $status = DB::transaction(fn () => Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function ($user, string $password) {
                // jwt_version + 1 revoca los JWT emitidos antes del cambio; SessionRevoker cierra las sesiones web.
                // El reset NO verifica el correo (Sprint 14: no debe saltarse la verificación de registro).
                $user->forceFill([
                    'password' => Hash::make($password),
                    'jwt_version' => (int) $user->jwt_version + 1,
                ])->setRememberToken(Str::random(60));

                $user->save();
                SessionRevoker::revokeSessions($user);

                // Actor explícito: en el reset no hay sesión autenticada. Sin la contraseña ni el token.
                AuditLog::record('password_reset', $user::class, $user->getKey(), [], (int) $user->getKey());

                event(new PasswordReset($user));
            },
        ));

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', 'Contraseña actualizada correctamente.');
        }

        // Mensaje único para correo inexistente, token inválido o limitación: no revelar qué cuentas existen.
        return back()->withErrors(['email' => __(Password::INVALID_TOKEN)])->onlyInput('email');
    }
}
