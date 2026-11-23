<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Services\GestionCertificados;
use Illuminate\Http\JsonResponse;

/**
 * Verificación pública de certificados por código (`GET /api/public/v1/certificados/{codigo}`).
 * Nunca expone DNI, correo, teléfono ni nombre completo del estudiante.
 */
final class CertificadoPublicoController extends Controller
{
    public function __construct(private readonly GestionCertificados $gestion) {}

    public function verificar(string $codigo): JsonResponse
    {
        $resultado = $this->gestion->verificarPublico($codigo);
        abort_if($resultado === null, 404, 'No se encontró un certificado con ese código.');

        return response()->json(['data' => $resultado])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
