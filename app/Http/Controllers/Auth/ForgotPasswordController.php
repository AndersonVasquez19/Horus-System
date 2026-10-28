<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Email;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        Email::normalizeRequest($request);
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Misma respuesta exista o no la cuenta: no revela qué correos están registrados.
        // Solo el límite de frecuencia se informa como error, porque no depende de la cuenta.
        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => __($status)]);
        }

        return back()->with('status', 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja.');
    }
}
