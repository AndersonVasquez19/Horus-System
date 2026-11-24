<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Services\Panel\SaludSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Salud operativa del sistema. Es información de operación (no de negocio), por eso comparte el permiso de las operaciones de
 * sistema, `respaldo.exportar` (solo el rol admin por defecto): no se creó un permiso nuevo para no tocar bases con datos.
 */
final class SaludController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:respaldo.exportar')];
    }

    public function index(SaludSistema $salud): JsonResponse
    {
        return response()->json(['data' => $salud->evaluar()])->header('Cache-Control', 'no-store, private');
    }
}
