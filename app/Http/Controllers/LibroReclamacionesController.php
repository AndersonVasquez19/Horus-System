<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreReclamacionRequest;
use App\Services\GestionReclamaciones;
use App\Support\AntispamReclamaciones;
use App\Support\Reclamaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Libro de Reclamaciones público (E1): muestra la hoja y recibe el alta.
 *
 * La recepción depende de {@see Reclamaciones::habilitado()} (flag en config/reclamaciones.php y correo real).
 * Apagado, la página se sigue mostrando y el POST responde 404 antes de leer el cuerpo
 * (middleware RecepcionReclamacionesHabilitada en la ruta). La respuesta del alta no incluye datos personales.
 */
class LibroReclamacionesController extends Controller
{
    public function show(): Response
    {
        $habilitado = Reclamaciones::habilitado();

        return response()
            ->view('pages.legales.libro-reclamaciones', [
                'habilitado' => $habilitado,
                // Solo se emiten cuando el alta está disponible: apagado no hay nada que firmar.
                'marca' => $habilitado ? AntispamReclamaciones::emitirMarca() : null,
                'tokenEnvio' => $habilitado ? (string) Str::uuid() : null,
                // La confirmación solo menciona la copia por correo si el envío está habilitado de verdad.
                'correoActivo' => $habilitado && Reclamaciones::envioHabilitado(),
            ])
            // La marca firmada y el token de envío son de un solo uso por visita: no deben quedar en caché.
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreReclamacionRequest $request, GestionReclamaciones $gestion): JsonResponse
    {
        ['reclamacion' => $reclamacion, 'repetida' => $repetida] = $gestion->registrarPublica(
            $request->datosReclamacion(),
            $request->tokenEnvio(),
        );

        return response()->json([
            'numero' => $reclamacion->numero,
            'tipo' => $reclamacion->tipo,
            'fecha_limite' => $reclamacion->fecha_limite->toDateString(),
            'repetida' => $repetida,
        ], $repetida ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }
}
