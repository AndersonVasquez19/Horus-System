<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Exceptions\ConsultaDniException;
use App\Http\Controllers\Controller;
use App\Models\Estudiante;
use App\Models\User;
use App\Services\JsonPeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Consulta de DNI para el alta de estudiantes. Solo consulta: no
 * registra a nadie. Next.js muestra los datos, la persona los confirma y recién entonces se registra. Devuelve únicamente
 * los campos necesarios y, si quien consulta puede ver estudiantes, si el DNI ya está registrado (para no duplicar).
 */
final class ConsultaDniController extends Controller implements HasMiddleware
{
    public function __construct(private readonly JsonPeService $jsonPe) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:estudiantes.crear'),
        ];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(
            ['dni' => ['required', 'string', 'regex:/\A[0-9]{8}\z/D']],
            [
                'dni.required' => 'El DNI es obligatorio.',
                'dni.string' => 'El DNI debe ser texto de 8 dígitos.',
                'dni.regex' => 'El DNI debe tener exactamente 8 dígitos.',
            ],
        );

        try {
            $persona = $this->jsonPe->consultarDni($datos['dni']);
        } catch (ConsultaDniException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->codigoError], $e->estado)
                ->header('Cache-Control', 'no-store, private');
        }

        /** @var User $usuario */
        $usuario = $request->user();
        $respuesta = [
            ...$persona,
            'apellidos' => trim($persona['apellido_paterno'].' '.$persona['apellido_materno']),
        ];
        // Se informa si ya está registrado solo a quien puede consultar estudiantes.
        if ($usuario->can('estudiantes.ver')) {
            $respuesta['ya_registrado_como_estudiante'] = Estudiante::withTrashed()->where('dni', $datos['dni'])->exists();
        }

        return response()->json(['data' => $respuesta])->header('Cache-Control', 'no-store, private');
    }
}
