<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Models\User;
use App\Services\GestionCertificados;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ConfiguracionSistema;
use App\Support\HoraLocal;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Certificados académicos (`certificados.ver|emitir|anular`).
 * Emisión individual o por lote, descarga de diploma PDF horizontal y anulación auditada.
 */
final class CertificadoController extends Controller implements HasMiddleware
{
    private const ORDENABLES = [
        'emitido_en' => 'emitido_en',
        'codigo' => 'codigo',
        'estado' => 'estado',
    ];

    public function __construct(private readonly GestionCertificados $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:certificados.ver', only: ['index', 'show', 'pdf']),
            new Middleware('permission:certificados.emitir', only: ['emitir', 'emitirLote']),
            new Middleware('permission:certificados.anular', only: ['anular']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'buscar' => ListFilter::text($request, 'buscar', 80),
            'estado' => ListFilter::in($request, 'estado', Certificado::ESTADOS),
            'convocatoria' => ListFilter::text($request, 'convocatoria', 40),
        ];

        $consulta = Certificado::query()->with(['estudiante', 'curso', 'convocatoria', 'emisor', 'anulador']);

        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }

        if ($filtros['convocatoria'] !== '') {
            $identificador = strtolower($filtros['convocatoria']);
            $consulta->whereHas('convocatoria', fn (Builder $q): Builder => $q->where('identificador', $identificador)->orWhere('codigo', $filtros['convocatoria']));
        }

        if ($filtros['buscar'] !== '') {
            $texto = $filtros['buscar'];
            $consulta->where(function (Builder $q) use ($texto): void {
                BusquedaLike::contiene($q, ['codigo'], $texto);
                $q->orWhereHas('estudiante', function (Builder $est) use ($texto): void {
                    BusquedaLike::contiene($est, ['dni', 'nombres', 'apellidos'], $texto);
                })->orWhereHas('curso', function (Builder $curso) use ($texto): void {
                    BusquedaLike::contiene($curso, ['codigo', 'nombre'], $texto);
                });
            });
        }

        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'emitido_en', 'desc');

        return Listado::responder(
            Listado::paginar($consulta, $request),
            fn (Certificado $c): array => $this->representar($c),
            $orden,
            $filtros,
        )->header('Cache-Control', 'no-store, private');
    }

    public function show(string $certificado): JsonResponse
    {
        $modelo = $this->buscar($certificado);

        return response()->json(['data' => $this->representar($modelo)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function emitir(Request $request, int $matricula): JsonResponse
    {
        $datos = $request->validate([
            'motivo_excepcion' => ['nullable', 'string', 'min:5', 'max:300', 'not_regex:/[<>]/u'],
        ], [
            'motivo_excepcion.min' => 'El motivo de excepción debe tener al menos 5 caracteres.',
            'motivo_excepcion.max' => 'El motivo de excepción admite hasta 300 caracteres.',
            'motivo_excepcion.not_regex' => 'El motivo no puede contener etiquetas HTML.',
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $modeloMatricula = Matricula::query()->findOrFail($matricula);
        $certificado = $this->gestion->emitirParaMatricula(
            $modeloMatricula,
            $actor,
            isset($datos['motivo_excepcion']) ? (string) $datos['motivo_excepcion'] : null,
        );

        return response()->json([
            'message' => "Certificado {$certificado->codigo} emitido correctamente.",
            'data' => $this->representar($certificado),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function emitirLote(Request $request, string $identificador): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $convocatoria = Convocatoria::query()->where('identificador', strtolower($identificador))->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();
        $resumen = $this->gestion->emitirLoteEdicion($convocatoria, $actor);

        $mensaje = $resumen['emitidos'] > 0
            ? "Se emitieron {$resumen['emitidos']} certificado(s) nuevos."
            : 'No hubo certificados nuevos por emitir en esta edición.';

        return response()->json([
            'message' => $mensaje,
            'data' => $resumen,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function anular(Request $request, string $certificado): JsonResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:300', 'not_regex:/[<>]/u'],
        ], [
            'motivo.required' => 'Indica el motivo de la anulación.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo admite hasta 300 caracteres.',
            'motivo.not_regex' => 'El motivo no puede contener etiquetas HTML.',
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $modelo = $this->buscar($certificado);
        $anulado = $this->gestion->anular($modelo, $actor, (string) $datos['motivo']);

        return response()->json([
            'message' => "El certificado {$anulado->codigo} se anuló.",
            'data' => $this->representar($anulado),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function pdf(Request $request, string $certificado): Response
    {
        $modelo = $this->buscar($certificado);

        $qrPayload = sprintf('HORUS-CERT|%s|%s', $modelo->codigo, $modelo->estado->value);
        $qrDataUri = (new \chillerlan\QRCode\QRCode)->render($qrPayload);

        AuditLog::record(
            'certificado_pdf_descargado',
            Certificado::class,
            $modelo->id,
            ['codigo' => $modelo->codigo],
            AuditoriaAcademica::actorDe($request),
        );

        $pdf = Pdf::loadView('pdf.certificado', [
            'certificado' => $modelo,
            'empresa' => ConfiguracionSistema::nombreEmpresa(),
            'qrDataUri' => $qrDataUri,
            'fechaGeneracion' => HoraLocal::ahora()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"certificado-{$modelo->codigo}.pdf\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function buscar(string $identificador): Certificado
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Certificado::query()
            ->with(['estudiante', 'curso', 'convocatoria', 'emisor', 'anulador'])
            ->where('identificador', strtolower($identificador))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function representar(Certificado $c): array
    {
        return [
            'identificador' => $c->identificador,
            'codigo' => $c->codigo,
            'estado' => $c->estado->value,
            'estado_etiqueta' => $c->estado->etiqueta(),
            'matricula_id' => $c->matricula_id,
            'estudiante' => [
                'id' => $c->estudiante?->id,
                'dni' => $c->estudiante?->dni ?? '',
                'nombre' => $c->estudiante ? trim($c->estudiante->nombres.' '.$c->estudiante->apellidos) : 'No registrado',
            ],
            'curso' => [
                'id' => $c->curso?->id,
                'codigo' => $c->curso?->codigo ?? '',
                'nombre' => $c->curso?->nombre ?? '',
                'duracion_horas' => $c->curso?->duracion_horas,
            ],
            'edicion' => $c->convocatoria ? [
                'identificador' => $c->convocatoria->identificador,
                'codigo' => $c->convocatoria->codigo,
                'nombre' => $c->convocatoria->nombre,
            ] : null,
            'porcentaje_asistencia' => $c->porcentaje_asistencia !== null ? (float) $c->porcentaje_asistencia : null,
            'nota_final' => $c->nota_final !== null ? (float) $c->nota_final : null,
            'emitido_en' => $c->emitido_en->format('Y-m-d H:i'),
            'emitido_por' => $c->emisor?->name,
            'motivo_excepcion' => $c->motivo_excepcion,
            'anulado_en' => $c->anulado_en?->format('Y-m-d H:i'),
            'anulado_por' => $c->anulador?->name,
            'motivo_anulacion' => $c->motivo_anulacion,
        ];
    }
}
