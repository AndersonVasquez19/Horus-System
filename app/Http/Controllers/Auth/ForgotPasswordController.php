<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Email;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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

        try {
            Password::sendResetLink($request->only('email'));
        } catch (TransportExceptionInterface) {
            // No exponer ni registrar el destinatario, token o detalle del proveedor.
            Log::warning('auth.password_reset', ['outcome' => 'delivery_unavailable']);
        }

        // También el throttle del broker depende de que exista la cuenta: no revelar ese estado.
        return back()->with('status', 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja.');
    }
}
