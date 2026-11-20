<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Services\ComprobantesPago;
use App\Services\GestionMatriculaConPago;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * «Matricular con pago»: matrícula + obligación + pago Yape + captura + confirmación en un solo paso atómico (ver
 * {@see GestionMatriculaConPago}). Exige los permisos de crear matrículas y de planificar, registrar y confirmar pagos.
 */
final class MatriculaConPagoController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionMatriculaConPago $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:matriculas.crear'),
            new Middleware('permission:pagos.registrar'),
            new Middleware('permission:pagos.confirmar'),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'estudiante_id' => ['required', 'integer', Rule::exists('estudiantes', 'id')->where('estado', 'activo')->whereNull('deleted_at')],
            'convocatoria' => ['required', 'string', 'uuid', Rule::exists('convocatorias', 'identificador')->whereNull('deleted_at')],
            'monto' => ['nullable'],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'referencia' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'imagen' => ComprobantesPago::reglas(),
        ], [
            'estudiante_id.required' => 'Selecciona el estudiante.',
            'estudiante_id.exists' => 'El estudiante seleccionado no está activo.',
            'convocatoria.required' => 'Selecciona la edición.',
            'convocatoria.exists' => 'La edición seleccionada no existe.',
            'fecha_pago.required' => 'Indica la fecha del pago.',
            'fecha_pago.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'imagen.required' => 'Adjunta la captura del comprobante de pago.',
            'imagen.image' => 'El comprobante debe ser una imagen.',
            'imagen.mimes' => 'Sube una captura en JPG, PNG o WebP.',
            'imagen.mimetypes' => 'Sube una captura en JPG, PNG o WebP.',
            'imagen.max' => 'La captura no puede pasar de 3 MB.',
            'imagen.dimensions' => 'La captura debe medir entre 100 y 8000 píxeles por lado.',
        ]);

        [$matricula, $obligacion, $creada] = $this->gestion->registrar($request, $datos, $request->file('imagen'), $request->header('Idempotency-Key'));

        return response()->json([
            'message' => $creada ? 'La matrícula se registró con su pago confirmado.' : 'Esta matrícula con pago ya estaba registrada.',
            'data' => [
                'matricula_id' => $matricula->id,
                'estado' => $matricula->estado,
                'monto_pagado' => number_format((float) $matricula->monto_pagado, 2, '.', ''),
                'obligacion' => $obligacion->identificador,
            ],
        ], $creada ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }
}
