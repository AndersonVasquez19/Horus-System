<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateJwt
{
    public function __construct(private readonly JwtTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return new JsonResponse(['message' => 'Falta el token Bearer.'], 401);
        }

        try {
            $claims = $this->tokens->decode($token);
            $user = User::query()->find($claims->sub ?? null);

            if (! $user || (int) ($claims->ver ?? -1) !== (int) $user->jwt_version) {
                return new JsonResponse(['message' => 'El token no es válido o fue revocado.'], 401);
            }
        } catch (Throwable) {
            return new JsonResponse(['message' => 'El token no es válido o ha expirado.'], 401);
        }

        $request->attributes->set('jwt_user', $user);

        return $next($request);
    }
}
