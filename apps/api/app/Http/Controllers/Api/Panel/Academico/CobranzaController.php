<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarSeguimientoCobranzaRequest;
use App\Models\User;
use App\Services\CobranzaAsistida;
use App\Support\Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cobranza asistida: cola priorizada de deuda vencida, historial de contactos y promesas de pago. Ver = `pagos.ver`; registrar un
 * contacto = `pagos.registrar`. No envía mensajes: los contactos son manuales.
 */
final class CobranzaController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CobranzaAsistida $cobranza) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:pagos.ver', only: ['cola', 'historial']),
            new Middleware('permission:pagos.registrar', only: ['registrar']),
        ];
    }

    public function cola(Request $request): JsonResponse
    {
        $tramo = Entrada::texto($request->query('tramo', ''));
        $promesa = Entrada::texto($request->query('promesa', ''));
        $resultado = $this->cobranza->cola([
            'tramo' => in_array($tramo, CobranzaAsistida::TRAMOS, true) ? $tramo : null,
            'promesa' => in_array($promesa, ['vigente', 'incumplida', 'ninguna'], true) ? $promesa : null,
            'busqueda' => mb_substr(trim(Entrada::texto($request->query('busqueda', ''))), 0, 100),
        ]);

        return response()->json(['data' => $resultado])->header('Cache-Control', 'no-store, private');
    }

    public function historial(string $obligacion): JsonResponse
    {
        return response()->json(['data' => $this->cobranza->historial($obligacion)])->header('Cache-Control', 'no-store, private');
    }

    public function registrar(RegistrarSeguimientoCobranzaRequest $request, string $obligacion): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 401);

        return response()->json(['data' => $this->cobranza->registrar($usuario, $obligacion, $request->validated())], 201);
    }
}
