<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Forma uniforme de los errores de /api/panel/v1/*: `{ message, code, errors? }`.
 *
 * `code` es estable y lo usa el BFF para decidir (401 → iniciar sesión, 419 → renovar token, 403 → sin permiso).
 * Las validaciones conservan la forma de Laravel (`errors` por campo). Un error interno nunca expone detalles
 * fuera del modo de depuración. Los errores se siguen registrando como siempre: esto solo cambia la respuesta.
 */
final class ErroresApiPanel
{
    private const CODIGOS = [
        400 => ['solicitud_invalida', 'La solicitud no es válida.'],
        401 => ['no_autenticado', 'Inicia sesión para continuar.'],
        403 => ['prohibido', 'No tienes permiso para realizar esta acción.'],
        404 => ['no_encontrado', 'El recurso solicitado no existe.'],
        405 => ['metodo_no_permitido', 'Método no permitido.'],
        409 => ['conflicto', 'La operación entra en conflicto con el estado actual.'],
        412 => ['precondicion_fallida', 'El recurso cambió desde que lo consultaste. Recarga e inténtalo de nuevo.'],
        413 => ['contenido_demasiado_grande', 'El contenido enviado es demasiado grande.'],
        419 => ['csrf', 'La verificación de seguridad expiró. Recarga e inténtalo de nuevo.'],
        428 => ['precondicion_requerida', 'Falta la versión del recurso.'],
        429 => ['demasiadas_solicitudes', 'Demasiadas solicitudes. Espera un momento.'],
        503 => ['no_disponible', 'Servicio no disponible temporalmente.'],
    ];

    /**
     * SQLSTATE de PostgreSQL que indican una carrera con otra transacción: fallo de serialización, interbloqueo y
     * bloqueo no disponible (lock_timeout). No es un error del sistema: el usuario puede reintentar.
     */
    private const SQLSTATE_CONCURRENCIA = ['40001', '40P01', '55P03'];

    /** Estados en los que se respeta el mensaje explícito de la excepción. */
    private const MENSAJE_PROPIO = [400, 409, 412, 422, 428];

    public static function registrar(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $error, Request $request): ?JsonResponse {
            return $request->is('api/panel/*') ? self::responder($error) : null;
        });
    }

    public static function responder(Throwable $error): ?JsonResponse
    {
        if ($error instanceof ValidationException) {
            return new JsonResponse([
                'message' => $error->getMessage(),
                'code' => 'validacion',
                'errors' => $error->errors(),
            ], $error->status);
        }

        if ($error instanceof AuthenticationException) {
            return self::json(401);
        }

        // Carreras que el protocolo de bloqueo deja a la base de datos (p. ej. el índice único parcial
        // `matriculas_vigente_unica` o el DNI único): la transacción se revirtió entera y no se expone SQL.
        if ($error instanceof UniqueConstraintViolationException) {
            return new JsonResponse([
                'message' => 'Otro usuario registró los mismos datos al mismo tiempo. Recarga e inténtalo de nuevo.',
                'code' => 'conflicto_concurrencia',
            ], 409);
        }

        if ($error instanceof DeadlockException
            || ($error instanceof QueryException && in_array(self::sqlstate($error), self::SQLSTATE_CONCURRENCIA, true))) {
            return new JsonResponse([
                'message' => 'Otra operación estaba modificando estos datos. No se guardó ningún cambio; inténtalo de nuevo.',
                'code' => 'conflicto_concurrencia',
            ], 409);
        }

        if ($error instanceof HttpExceptionInterface) {
            $estado = $error->getStatusCode();
            // Un mensaje de negocio (abort(409, '...')) se conserva. En 401/403/404/405/419/429 el framework pone
            // textos técnicos (p. ej. el nombre del modelo o de la ruta), así que siempre se usa el genérico.
            $mensaje = in_array($estado, self::MENSAJE_PROPIO, true) && $error->getMessage() !== ''
                ? $error->getMessage()
                : null;

            return self::json($estado, $mensaje, $error->getHeaders());
        }

        if (config('app.debug')) {
            return null;
        }

        return new JsonResponse(['message' => 'Error interno del servidor.', 'code' => 'error_interno'], 500);
    }

    private static function sqlstate(QueryException $error): string
    {
        $estado = $error->errorInfo[0] ?? $error->getCode();

        return is_scalar($estado) ? (string) $estado : '';
    }

    /** @param array<string, string> $cabeceras */
    private static function json(int $estado, ?string $mensaje = null, array $cabeceras = []): JsonResponse
    {
        [$codigo, $predeterminado] = self::CODIGOS[$estado] ?? [$estado >= 500 ? 'error_interno' : 'error', 'No se pudo completar la solicitud.'];

        return new JsonResponse(['message' => $mensaje ?? $predeterminado, 'code' => $codigo], $estado, $cabeceras);
    }
}
