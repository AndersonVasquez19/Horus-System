<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class SolicitudCotizacionPublicaController extends Controller
{
    /**
     * Fase 1: marcador de posición. Aun con el flag encendido no hay formulario ni recepción;
     * no lee la petición, no valida, no consulta ni escribe en la base de datos.
     */
    public function show(): JsonResponse
    {
        return $this->noDisponible();
    }

    public function store(): JsonResponse
    {
        return $this->noDisponible();
    }

    private function noDisponible(): JsonResponse
    {
        return response()->json(['mensaje' => 'La recepción de solicitudes no está disponible.'], 503)
            ->header('Cache-Control', 'no-store, private');
    }
}
