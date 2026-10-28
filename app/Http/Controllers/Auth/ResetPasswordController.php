<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Email;
use App\Support\PasswordPolicy;
use App\Support\SessionRevoker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ResetPasswordController extends Controller
{
    public function create(string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => request('email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Email::normalizeRequest($request);
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

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function ($user, string $password) {
                // jwt_version + 1 revoca los JWT emitidos antes del cambio; SessionRevoker cierra las sesiones web.
                $user->forceFill([
                    'password' => Hash::make($password),
                    'jwt_version' => (int) $user->jwt_version + 1,
                ])->setRememberToken(Str::random(60));

                $user->save();
                SessionRevoker::revokeSessions($user);

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', 'Contraseña actualizada correctamente.');
        }

        return back()->withErrors(['email' => __($status)])->withInput();
    }
}
