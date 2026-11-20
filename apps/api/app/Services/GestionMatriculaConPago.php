<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ComprobantePago;
use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Support\AuditoriaAcademica;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Matricular con pago» (Horus Academic, caja del administrador). En UNA transacción: matrícula + obligación de pago completo +
 * pago Yape + captura del comprobante + confirmación. Si cualquier paso falla no queda nada en la base y la imagen guardada se borra.
 * Reutiliza las reglas de {@see GestionMatriculas} (cupo, duplicado, elegibilidad) y de {@see GestionPagos} (importes, saldo, libro),
 * sin duplicarlas.
 *
 *  - exige a la vez `matriculas.crear`, `pagos.planificar`, `pagos.registrar` y `pagos.confirmar` (hoy, solo `admin`);
 *  - idempotente por `Idempotency-Key`: misma clave y mismos datos devuelven lo ya creado; con otros datos, 409;
 *  - el importe es el indicado o, si se omite, el precio del curso; el pago cubre el total (pago completo).
 */
class GestionMatriculaConPago
{
    public function __construct(
        private readonly GestionMatriculas $matriculas,
        private readonly GestionPagos $pagos,
        private readonly ComprobantesPago $comprobantes,
    ) {}

    /**
     * @param  array{estudiante_id: int, convocatoria: string, fecha_pago: string, monto?: mixed, referencia?: ?string}  $datos
     * @return array{0: Matricula, 1: ObligacionPago, 2: bool} matrícula, obligación y si se creó ahora (false = repetición idempotente)
     */
    public function registrar(Request $request, array $datos, UploadedFile $imagen, ?string $clave): array
    {
        if ($clave === null || ! preg_match('/\A[A-Za-z0-9_-]{8,64}\z/', $clave)) {
            abort(428, 'Envía una Idempotency-Key (8 a 64 caracteres) por cada intención de matrícula con pago.');
        }
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User, 403);
        foreach (['matriculas.crear', 'pagos.planificar', 'pagos.registrar', 'pagos.confirmar'] as $permiso) {
            abort_unless($actor->can($permiso), 403);
        }

        $convocatoria = Convocatoria::query()->with('curso')->where('identificador', strtolower((string) $datos['convocatoria']))->first();
        if ($convocatoria === null || $convocatoria->curso === null) {
            throw ValidationException::withMessages(['convocatoria' => 'La convocatoria no existe.']);
        }
        $monto = GestionPagos::centimos($datos['monto'] ?? $convocatoria->curso->precio, 'monto');
        $referencia = isset($datos['referencia']) ? (trim((string) $datos['referencia']) ?: null) : null;
        $huella = GestionPagos::huella('yape', $monto, $datos['fecha_pago'], $referencia, false);

        // Repetición: la misma clave del mismo operador ya generó una transacción de pago.
        $previa = TransaccionPago::query()->where('clave_idempotencia', $clave)->where('registrado_por', $actor->id)->first();
        if ($previa !== null) {
            abort_unless(hash_equals((string) $previa->huella, $huella), 409, 'Esa Idempotency-Key ya se usó con otros datos.');
            $obligacion = ObligacionPago::query()->findOrFail($previa->obligacion_pago_id);

            return [Matricula::query()->findOrFail($obligacion->matricula_id), $obligacion, false];
        }

        // La imagen se guarda antes (decodifica y vuelve a codificar; rechaza lo corrupto) y se borra si algo falla después.
        $guardado = $this->comprobantes->guardar($imagen);

        // Aquí el pago se confirma en el acto: no queda una persona que revise después, así que la misma captura de otro pago vigente se
        // rechaza (en los demás flujos solo se avisa, porque confirmar es otro paso).
        $repetida = $this->pagos->imagenYaUsada($guardado['sha256']);
        if ($repetida !== null) {
            $this->comprobantes->borrar($guardado['ruta']);

            throw ValidationException::withMessages(['imagen' => "Esta captura ya se usó en un pago de la matrícula #{$repetida['matricula_id']} ({$repetida['fecha_pago']}). Sube el comprobante de este pago."]);
        }

        try {
            return DB::transaction(function () use ($request, $datos, $clave, $convocatoria, $monto, $referencia, $guardado, $actor): array {
                $matricula = $this->matriculas->crear([
                    'estudiante_id' => (int) $datos['estudiante_id'],
                    'curso_id' => $convocatoria->curso_id,
                    'convocatoria' => $convocatoria->identificador,
                    'fecha_matricula' => CarbonImmutable::now('America/Lima')->toDateString(),
                    'estado' => 'pendiente',
                    'monto_pagado' => 0,
                    'observaciones' => null,
                ], AuditoriaAcademica::actorDe($request));

                $concepto = mb_substr('Matrícula '.$convocatoria->codigo.' · '.$convocatoria->curso->nombre, 0, 120);
                $obligacion = $this->pagos->crearObligacion($request, $matricula->id, ['concepto' => $concepto, 'plan' => 'completo', 'monto_total' => GestionPagos::formato($monto)]);
                [$t] = $this->pagos->registrarPago($request, $obligacion->identificador, [
                    'metodo' => 'yape', 'monto' => GestionPagos::formato($monto), 'fecha_pago' => $datos['fecha_pago'], 'referencia' => $referencia, 'confirmar' => false,
                ], $clave);

                ComprobantePago::query()->create([
                    'transaccion_pago_id' => $t->id, 'ruta' => $guardado['ruta'], 'mime' => $guardado['mime'], 'tamano' => $guardado['tamano'],
                    'sha256' => $guardado['sha256'], 'subido_por' => $actor->id,
                ]);
                AuditLog::record('comprobante_adjuntado', TransaccionPago::class, $t->id, ['obligacion' => $obligacion->id, 'reemplazo' => false], $actor->id);

                $this->pagos->confirmar($request, $t->identificador);

                return [$matricula->refresh(), $obligacion->refresh(), true];
            });
        } catch (Throwable $error) {
            $this->comprobantes->borrar($guardado['ruta']);

            throw $error;
        }
    }
}
