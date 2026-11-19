<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Panel\BusquedaAcademica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda global del panel académico. Solo exige el permiso de entrada al panel: cada grupo se incluye únicamente si la persona
 * tiene el permiso de su módulo ({@see BusquedaAcademica}). Sin caché y con límite de consultas por usuario.
 */
final class BusquedaController extends Controller
{
    public function __invoke(Request $request, BusquedaAcademica $busqueda): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:'.BusquedaAcademica::MAX]], ['q.max' => 'La búsqueda admite hasta '.BusquedaAcademica::MAX.' caracteres.']);
        $q = (string) ($datos['q'] ?? '');

        return response()->json(['data' => ['consulta' => trim($q), 'grupos' => $busqueda->buscar($user, $q)]])->header('Cache-Control', 'no-store, private');
    }
}
