<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\VerificacionRegistroService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DescartarSesionSinVerificar
{
    public function handle(Request $request, Closure $next): Response
    {
        if (VerificacionRegistroService::enabled()) {
            $user = $request->user();
            if ($user instanceof User && (! $user->hasVerifiedEmail()
                || $request->session()->get('auth.verification_user') !== $user->getKey()
                || $request->session()->get('auth.verification_version') !== (int) $user->jwt_version)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Unauthenticated.'], 401)
                    : redirect()->guest(route('login'));
            }
        }

        return $next($request);
    }
}
