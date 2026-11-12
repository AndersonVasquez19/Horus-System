<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSolicitudMatriculaRequest;
use App\Services\GestionSolicitudesMatricula;
use App\Support\FormularioSolicitudMatricula;
use App\Support\LimitesEnvioPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Solicitud de matrícula para el BFF de apps/web (Fase 2c.2). Solo envuelve el módulo existente
 * ({@see GestionSolicitudesMatricula}, el mismo del formulario Blade): una solicitud pública es un CONTACTO que el
 * personal revisa en `/admin/solicitudes-matricula`; nunca crea estudiante ni matrícula, no confirma nada, no toca
 * cupos ni consulta si la persona ya es estudiante (no hay enumeración). La disponibilidad del curso se vuelve a
 * resolver en Laravel al registrar. No existe ruta pública para consultar solicitudes.
 */
final class SolicitudesMatriculaPublicoController extends Controller
{
    private static function habilitado(): bool
    {
        return (bool) config('catalogo-publico.enabled') && Schema::hasTable('solicitudes_matricula');
    }

    /** Cursos que aceptan solicitud (con su información editorial) y la marca de un solo uso del formulario. */
    public function formulario(GestionSolicitudesMatricula $gestion): JsonResponse
    {
        $habilitado = self::habilitado();

        return response()->json([
            'habilitado' => $habilitado,
            'marca' => $habilitado ? FormularioSolicitudMatricula::emitir() : null,
            'cursos' => $habilitado ? $gestion->cursosConDetalle() : [],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreSolicitudMatriculaRequest $request, GestionSolicitudesMatricula $gestion): JsonResponse
    {
        // Mismo criterio que el libro: los límites estrictos cuentan solo envíos ya validados.
        // Por visitante (HMAC del BFF): 5 por 10 min y 15 por día; por correo (hash): 5 por día (varios cursos por persona).
        $visitante = ChatPublicoController::visitante($request);
        $limites = [
            'matriculas-bff-v10:'.$visitante => [5, 600],
            'matriculas-bff-vdia:'.$visitante => [15, 86400],
            'matriculas-bff-correo:'.hash('sha256', (string) $request->input('email')) => [5, 86400],
        ];
        $superada = LimitesEnvioPublico::consumir($limites);
        if ($superada !== null) {
            return response()->json(
                ['error' => ['code' => 'demasiadas_solicitudes', 'message' => 'Se alcanzó el límite de solicitudes. Inténtalo más tarde.']],
                429,
                ['Retry-After' => (string) LimitesEnvioPublico::espera($superada), 'Cache-Control' => 'no-store, private'],
            );
        }

        try {
            ['solicitud' => $solicitud, 'repetida' => $repetida] = $gestion->registrarConEstado($request->validated());
        } catch (ValidationException $e) {
            // El servicio rechazó el envío (curso retirado, marca ya usada con otros datos): no cuenta como envío válido.
            LimitesEnvioPublico::devolver($limites);

            throw $e;
        }
        if ($repetida) {
            LimitesEnvioPublico::devolver($limites);
        }

        return response()->json([
            'codigo' => $solicitud->codigoSeguimiento(),
            'curso' => $solicitud->curso_titulo,
            'modalidad' => $solicitud->modalidad,
            'repetida' => $repetida,
        ], $repetida ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }
}
