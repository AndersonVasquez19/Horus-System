<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\VerificacionRegistroService;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnexpectedValueException;

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

            if (! $user || (int) ($claims->ver ?? -1) !== (int) $user->jwt_version
                || (VerificacionRegistroService::enabled() && ! $user->hasVerifiedEmail())) {
                return new JsonResponse(['message' => 'El token no es válido o fue revocado.'], 401);
            }
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException) {
            // Token malformado, firma inválida, expirado, aún no vigente o emisor/audiencia incorrectos.
            return new JsonResponse(['message' => 'El token no es válido o ha expirado.'], 401);
        } catch (Throwable $error) {
            // Fallo interno (secreto mal configurado, base de datos…): no se confunde con un token malo.
            // La respuesta no cambia, pero el fallo queda registrado sin incluir el token.
            report($error);

            return new JsonResponse(['message' => 'El token no es válido o ha expirado.'], 401);
        }

        $request->attributes->set('jwt_user', $user);

        return $next($request);
    }
}
