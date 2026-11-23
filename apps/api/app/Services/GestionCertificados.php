<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoCertificado;
use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Models\User;
use App\Support\CodigoSecuencial;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Emisión, anulación y verificación pública de certificados académicos.
 *
 * Reglas:
 *  - Una matrícula anulada o eliminada no puede recibir certificado.
 *  - A lo sumo un certificado en estado `emitido` por matrícula (se bloquea la matrícula con `lockForUpdate`).
 *  - Código secuencial único `HORUS-AAAA-NNNN` tomado de `secuencias_codigo` dentro de la misma transacción.
 *  - Si hay sesiones realizadas con asistencia registrada menor al 80 %, o nota final desaprobatoria (< 11),
 *    solo un administrador puede emitir indicando un `motivo_excepcion`.
 *  - La verificación pública jamás expone DNI, correo, teléfono ni nombre completo (solo iniciales abreviadas).
 */
class GestionCertificados
{
    public const ASISTENCIA_MINIMA = 80.0;

    public const NOTA_APROBATORIA_MINIMA = 11.0;

    public function __construct(private readonly GestionAsistencias $asistencias) {}

    public function emitirParaMatricula(Matricula $matricula, User $actor, ?string $motivoExcepcion = null): Certificado
    {
        $motivoLimpio = $motivoExcepcion !== null ? trim($motivoExcepcion) : '';
        $motivo = $motivoLimpio !== '' ? $motivoLimpio : null;

        return DB::transaction(function () use ($matricula, $actor, $motivo): Certificado {
            /** @var Matricula $bloqueada */
            $bloqueada = Matricula::query()
                ->with(['estudiante', 'curso', 'convocatoria'])
                ->lockForUpdate()
                ->findOrFail($matricula->id);

            abort_if($bloqueada->estado === 'anulada', 409, 'No se puede emitir un certificado para una matrícula anulada.');

            $vigente = Certificado::query()
                ->where('matricula_id', $bloqueada->id)
                ->where('estado', EstadoCertificado::Emitido->value)
                ->first();

            abort_if($vigente !== null, 409, "Esta matrícula ya cuenta con el certificado vigente {$vigente?->codigo}.");

            $porcentajeAsistencia = $this->porcentajeAsistencia($bloqueada);
            $notaFinal = $bloqueada->getAttribute('nota_final') !== null
                ? (float) $bloqueada->getAttribute('nota_final')
                : null;

            $errores = $this->evaluarRequisitos($porcentajeAsistencia, $notaFinal);
            if ($errores !== []) {
                if ($motivo === null || ! $actor->hasRole('admin')) {
                    throw ValidationException::withMessages(['matricula' => implode(' ', $errores)]);
                }
            }

            $codigo = CodigoSecuencial::siguiente('HORUS');

            $certificado = Certificado::query()->create([
                'identificador' => (string) Str::uuid(),
                'codigo' => $codigo,
                'matricula_id' => $bloqueada->id,
                'convocatoria_id' => $bloqueada->convocatoria_id,
                'estudiante_id' => $bloqueada->estudiante_id,
                'curso_id' => $bloqueada->curso_id,
                'estado' => EstadoCertificado::Emitido,
                'porcentaje_asistencia' => $porcentajeAsistencia,
                'nota_final' => $notaFinal,
                'emitido_en' => HoraLocal::ahora(),
                'emitido_por' => $actor->id,
                'motivo_excepcion' => $errores !== [] ? $motivo : null,
            ]);

            AuditLog::record(
                'certificado_emitido',
                Certificado::class,
                $certificado->id,
                [
                    'codigo' => $certificado->codigo,
                    'matricula_id' => $bloqueada->id,
                    'convocatoria_id' => $bloqueada->convocatoria_id,
                    'con_excepcion' => $errores !== [],
                ],
                $actor->id,
            );

            return $certificado->load(['estudiante', 'curso', 'convocatoria', 'emisor']);
        });
    }

    /**
     * Emite en lote los certificados de todas las matrículas elegibles de una edición.
     *
     * @return array{emitidos: int, ya_emitidos: int, omitidos: int}
     */
    public function emitirLoteEdicion(Convocatoria $convocatoria, User $actor): array
    {
        $matriculas = Matricula::query()
            ->with(['estudiante', 'curso'])
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', '!=', 'anulada')
            ->get();

        $resumenAsistencia = collect($this->asistencias->resumen($convocatoria)['matriculas'])->keyBy('matricula_id');
        $emitidosIds = Certificado::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', EstadoCertificado::Emitido->value)
            ->pluck('matricula_id')
            ->flip();

        $emitidos = 0;
        $yaEmitidos = 0;
        $omitidos = 0;

        foreach ($matriculas as $matricula) {
            if ($emitidosIds->has($matricula->id)) {
                $yaEmitidos++;
                continue;
            }

            $porcentaje = $resumenAsistencia->get($matricula->id)['porcentaje'] ?? null;
            $notaFinal = $matricula->getAttribute('nota_final') !== null
                ? (float) $matricula->getAttribute('nota_final')
                : null;

            if ($this->evaluarRequisitos($porcentaje, $notaFinal) !== []) {
                $omitidos++;
                continue;
            }

            $this->emitirParaMatricula($matricula, $actor);
            $emitidos++;
        }

        return [
            'emitidos' => $emitidos,
            'ya_emitidos' => $yaEmitidos,
            'omitidos' => $omitidos,
        ];
    }

    public function anular(Certificado $certificado, User $actor, string $motivo): Certificado
    {
        $motivoLimpio = trim($motivo);
        if (mb_strlen($motivoLimpio) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Indica un motivo de anulación de al menos 5 caracteres.']);
        }

        return DB::transaction(function () use ($certificado, $actor, $motivoLimpio): Certificado {
            /** @var Certificado $bloqueado */
            $bloqueado = Certificado::query()->lockForUpdate()->findOrFail($certificado->id);

            abort_if($bloqueado->estado === EstadoCertificado::Anulado, 409, 'Este certificado ya fue anulado.');

            $bloqueado->estado = EstadoCertificado::Anulado;
            $bloqueado->anulado_en = HoraLocal::ahora();
            $bloqueado->anulado_por = $actor->id;
            $bloqueado->motivo_anulacion = $motivoLimpio;
            $bloqueado->save();

            AuditLog::record(
                'certificado_anulado',
                Certificado::class,
                $bloqueado->id,
                ['codigo' => $bloqueado->codigo, 'matricula_id' => $bloqueado->matricula_id],
                $actor->id,
            );

            return $bloqueado->load(['estudiante', 'curso', 'convocatoria', 'emisor', 'anulador']);
        });
    }

    /**
     * Consulta pública por código sin exponer datos personales (solo iniciales abreviadas).
     *
     * @return array{valido: bool, estado: string, codigo: string, curso: string, fecha_emision: string, titular_abreviado: string}|null
     */
    public function verificarPublico(string $codigo): ?array
    {
        $normalizado = strtoupper(trim($codigo));
        if (preg_match('/\A[A-Z0-9-]{4,32}\z/', $normalizado) !== 1) {
            return null;
        }

        $certificado = Certificado::query()
            ->with(['curso', 'estudiante'])
            ->where('codigo', $normalizado)
            ->first();

        if ($certificado === null) {
            return null;
        }

        return [
            'valido' => $certificado->estado === EstadoCertificado::Emitido,
            'estado' => $certificado->estado->value,
            'codigo' => $certificado->codigo,
            'curso' => $certificado->curso?->nombre ?? 'Curso institucional',
            'fecha_emision' => $certificado->emitido_en->format('Y-m-d'),
            'titular_abreviado' => $certificado->titularAbreviado(),
        ];
    }

    private function porcentajeAsistencia(Matricula $matricula): ?float
    {
        if ($matricula->convocatoria === null) {
            return null;
        }

        $resumen = $this->asistencias->resumen($matricula->convocatoria);
        foreach ($resumen['matriculas'] as $fila) {
            if ($fila['matricula_id'] === $matricula->id) {
                return $fila['porcentaje'];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function evaluarRequisitos(?float $porcentajeAsistencia, ?float $notaFinal): array
    {
        $errores = [];
        if ($porcentajeAsistencia !== null && $porcentajeAsistencia < self::ASISTENCIA_MINIMA) {
            $errores[] = sprintf(
                'La asistencia registrada (%.1f%%) es menor al mínimo requerido (%.0f%%).',
                $porcentajeAsistencia,
                self::ASISTENCIA_MINIMA,
            );
        }
        if ($notaFinal !== null && $notaFinal < self::NOTA_APROBATORIA_MINIMA) {
            $errores[] = sprintf(
                'La nota final (%.2f) es menor a la nota mínima aprobatoria (%.0f).',
                $notaFinal,
                self::NOTA_APROBATORIA_MINIMA,
            );
        }

        return $errores;
    }
}
