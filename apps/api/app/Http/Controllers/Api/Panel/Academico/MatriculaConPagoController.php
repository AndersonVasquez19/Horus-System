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
        $metodo = strtolower(trim((string) $request->input('metodo', 'yape'))) ?: 'yape';
        $requiereCaptura = in_array($metodo, ['yape', 'plin'], true);
        $reglasImagen = ComprobantesPago::reglas(permitirPdf: true);
        if (! $requiereCaptura) {
            $reglasImagen[0] = 'nullable';
        }

        $datos = $request->validate([
            'estudiante_id' => ['required', 'integer', Rule::exists('estudiantes', 'id')->where('estado', 'activo')->whereNull('deleted_at')],
            'convocatoria' => ['nullable', 'required_without:curso_id', 'string', 'uuid', Rule::exists('convocatorias', 'identificador')->whereNull('deleted_at')],
            'curso_id' => ['nullable', 'required_without:convocatoria', 'integer', Rule::exists('cursos', 'id')->where('estado', 'activo')->whereNull('deleted_at')],
            'metodo' => ['nullable', 'string', 'max:20', Rule::exists('metodos_pago', 'codigo')->where('activo', true)],
            'monto' => ['nullable'],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'referencia' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'imagen' => $reglasImagen,
        ], [
            'estudiante_id.required' => 'Selecciona el estudiante.',
            'estudiante_id.exists' => 'El estudiante seleccionado no está activo.',
            'convocatoria.required_without' => 'Selecciona una edición o un curso.',
            'convocatoria.exists' => 'La edición seleccionada no existe.',
            'curso_id.required_without' => 'Selecciona un curso o una edición.',
            'curso_id.exists' => 'El curso seleccionado no está activo.',
            'metodo.exists' => 'El método de pago seleccionado no está disponible.',
            'fecha_pago.required' => 'Indica la fecha del pago.',
            'fecha_pago.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'imagen.required' => 'Adjunta la captura del comprobante (obligatoria para Yape y Plin).',
            'imagen.mimes' => 'Sube una captura en JPG, PNG o WebP, o un PDF.',
            'imagen.mimetypes' => 'Sube una captura en JPG, PNG o WebP, o un PDF.',
            'imagen.max' => 'El comprobante no puede pasar de 3 MB.',
        ]);

        [$matricula, $obligacion, $creada] = $this->gestion->registrar($request, $datos, $request->file('imagen'), $request->header('Idempotency-Key'));

        $mensaje = ! $creada
            ? 'Esta matrícula con pago ya estaba registrada.'
            : ($matricula->estado === 'pagada'
                ? 'La matrícula se registró con pago completo confirmado.'
                : 'La matrícula se registró con abono inicial y saldo pendiente.');

        return response()->json([
            'message' => $mensaje,
            'data' => [
                'matricula_id' => $matricula->id,
                'estado' => $matricula->estado,
                'monto_pagado' => number_format((float) $matricula->monto_pagado, 2, '.', ''),
                'obligacion' => $obligacion->identificador,
            ],
        ], $creada ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }
}
