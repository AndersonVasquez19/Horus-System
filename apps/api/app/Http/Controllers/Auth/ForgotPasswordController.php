<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Email;
use App\Support\RestablecimientoContrasena;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Un fallo del correo se ve igual que el éxito (ver RestablecimientoContrasena::solicitar).
        RestablecimientoContrasena::solicitar((string) $request->input('email'));

        // También el throttle del broker depende de que exista la cuenta: no revelar ese estado.
        return back()->with('status', RestablecimientoContrasena::MENSAJE_SOLICITUD);
    }
}
