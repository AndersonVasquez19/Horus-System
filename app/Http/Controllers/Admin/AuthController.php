<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Email;
use App\Support\PasswordPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        Email::normalizeRequest($request);
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email', 'max:255', Email::uniqueRule(null, 'Ya existe una cuenta con este correo.')],
                'password' => ['required', 'confirmed', PasswordPolicy::rule()],
                'terms' => ['accepted'],
            ], [
                'name.required' => 'Escribe tu nombre para continuar.',
                'email.required' => 'El correo electrónico es obligatorio.',
                'email.email' => 'Ingresa un correo electrónico válido.',
                'terms.accepted' => 'Debes aceptar el aviso de privacidad para crear tu cuenta.',
            ] + PasswordPolicy::messages());
        } catch (ValidationException $error) {
            // Reponer solo campos del formulario; nunca credenciales o tokens inesperados.
            $request->replace($request->only('name', 'email', 'terms'));
            $request->query->replace([]);
            throw $error;
        }

        // El registro público nunca asigna roles. El acceso al panel se concede por consola:
        // `php artisan horus:promover-admin` (local) o `horus:crear-usuario`.
        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return redirect()->route('login')->with('success', 'Tu cuenta está lista. Inicia sesión para continuar.');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        Email::normalizeRequest($request);
        $credentials = $request->validate(
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ],
            [
                'email.required' => 'El correo electrónico es obligatorio.',
                'email.email' => 'Ingresa un correo electrónico válido.',
                'password.required' => 'La contraseña es obligatoria.',
            ],
        );

        if (! Auth::attempt($credentials)) {
            return back()
                ->withErrors(['email' => 'Las credenciales ingresadas no son válidas.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        if ($request->user()->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES)) {
            return redirect()->intended(route('admin.dashboard'));
        }

        // Sin rol de panel: no arrastrar una URL "intended" (p. ej. /admin) a un inicio de sesión posterior.
        $request->session()->forget('url.intended');

        return redirect()->route('home');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->user()->increment('jwt_version');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Has cerrado sesión correctamente.');
    }
}
