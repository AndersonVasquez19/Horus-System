<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Models\User;
use App\Support\AuditoriaAcademica;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operaciones transaccionales del módulo de Matrículas.
 *
 * Centraliza la máquina de estados (`pendiente`, `pagada`, `anulada`), el protocolo de bloqueo
 * pesimista en orden fijo (cursos por id -> convocatoria -> estudiantes por id -> matrícula; una matrícula con
 * convocatoria descuenta el cupo de ella y no del curso), la verificación de
 * elegibilidad y duplicados vigentes, y el registro de auditoría fail-closed.
 */
final class GestionMatriculas
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, int $actor): Matricula
    {
        $datos = $this->conIdentificadoresEnteros($datos);
        unset($datos['motivo']);
        if (($datos['estado'] ?? null) === 'pagada') {
            throw ValidationException::withMessages([
                'estado' => 'Las matrículas nuevas solo pueden registrarse como pendientes o anuladas.',
            ]);
        }
        $datos['monto_pagado'] = 0;

        // La convocatoria llega por su identificador público; se resuelve bajo bloqueo, nunca desde la lectura previa.
        $identificador = $datos['convocatoria'] ?? null;
        unset($datos['convocatoria']);

        return DB::transaction(function () use ($datos, $actor, $identificador): Matricula {
            $curso = Curso::withTrashed()
                ->lockForUpdate()
                ->findOrFail($datos['curso_id']);

            // La validación HTTP puede haber quedado obsoleta antes de adquirir el lock.
            if ($curso->trashed() || $curso->estado !== 'activo') {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso seleccionado no está disponible.',
                ]);
            }

            // Orden de bloqueo: cursos -> convocatorias -> estudiantes -> matrícula.
            $convocatoria = null;
            if (is_string($identificador) && $identificador !== '') {
                $convocatoria = Convocatoria::withTrashed()->lockForUpdate()->where('identificador', strtolower($identificador))->first();
                if ($convocatoria === null || $convocatoria->trashed() || (int) $convocatoria->curso_id !== (int) $curso->id) {
                    throw ValidationException::withMessages([
                        'convocatoria' => 'La convocatoria no pertenece al curso seleccionado.',
                    ]);
                }
                $datos['convocatoria_id'] = $convocatoria->id;
            }

            $estudiante = Estudiante::withTrashed()->lockForUpdate()->findOrFail($datos['estudiante_id']);
            if ($estudiante->trashed() || $estudiante->estado !== 'activo') {
                throw ValidationException::withMessages([
                    'estudiante_id' => 'El estudiante seleccionado no está activo.',
                ]);
            }

            if ($datos['estado'] !== 'anulada') {
                $this->assertNoActiveDuplicate((int) $datos['estudiante_id'], (int) $curso->id);

                if ($convocatoria !== null) {
                    $this->ocuparCupoConvocatoria($convocatoria);
                } else {
                    if ($curso->cupos_disponibles <= 0) {
                        throw ValidationException::withMessages([
                            'curso_id' => 'El curso no tiene cupos disponibles.',
                        ]);
                    }

                    $curso->decrement('cupos_disponibles');
                }
            }

            $matricula = Matricula::create($datos);

            // Última escritura de la transacción: si la auditoría falla se revierten matrícula, cupo e intención de aviso.
            AuditoriaAcademica::registrarCreacion(
                'matricula_creada',
                $matricula,
                AuditoriaAcademica::MATRICULA,
                $actor,
                AuditoriaAcademica::efectoCupo(null, null, (string) $datos['estado'], (int) $curso->id),
            );

            return $matricula;
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Matricula $matricula, array $datos, ?User $usuario, int $actor): Matricula
    {
        $datos = $this->conIdentificadoresEnteros($datos);
        $motivo = is_string($datos['motivo'] ?? null) ? trim((string) $datos['motivo']) : '';
        unset($datos['monto_pagado'], $datos['motivo']);

        return DB::transaction(function () use ($matricula, $datos, $usuario, $actor, $motivo): Matricula {
            $matricula = $this->lockMatricula($matricula->id, (int) $datos['curso_id'], (int) $datos['estudiante_id']);

            $estadoAnterior = (string) $matricula->estado;
            if ($datos['estado'] === 'pagada' && $estadoAnterior !== 'pagada') {
                throw ValidationException::withMessages([
                    'estado' => 'El estado pagada se asigna automáticamente desde el libro de pagos.',
                ]);
            }
            if ($estadoAnterior === 'pagada' && $datos['estado'] === 'pendiente') {
                throw ValidationException::withMessages([
                    'estado' => 'Una matrícula pagada solo vuelve a pendiente al reversar su pago en Finanzas.',
                ]);
            }

            $anteriorActiva = $matricula->estado !== 'anulada';
            $nuevaActiva = $datos['estado'] !== 'anulada';
            if ($anteriorActiva && ! $nuevaActiva) {
                abort_unless($usuario?->can('matriculas.anular') === true, 403);
                if (mb_strlen($motivo) < 3) {
                    throw ValidationException::withMessages([
                        'motivo' => 'Debes indicar un motivo de al menos 3 caracteres para anular la matrícula.',
                    ]);
                }
                $this->asegurarSinPagosEnLibro($matricula);
            }
            $cursoAnteriorId = (int) $matricula->curso_id;
            $cursoNuevoId = (int) $datos['curso_id'];
            $cursos = Curso::withTrashed()
                ->whereIn('id', array_unique([$cursoAnteriorId, $cursoNuevoId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $cursoAnterior = $cursos->get($cursoAnteriorId);
            $cursoNuevo = $cursos->get($cursoNuevoId);

            if ($cursoNuevo === null || $cursoNuevo->trashed()) {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso seleccionado no está disponible.',
                ]);
            }

            $estudiante = Estudiante::withTrashed()->lockForUpdate()->findOrFail($datos['estudiante_id']);
            if ($estudiante->trashed()) {
                throw ValidationException::withMessages([
                    'estudiante_id' => 'El estudiante seleccionado no está disponible.',
                ]);
            }

            if ($nuevaActiva) {
                $this->assertNoActiveDuplicate(
                    (int) $datos['estudiante_id'],
                    $cursoNuevoId,
                    (int) $matricula->id,
                );
            }

            // Una matrícula con convocatoria descuenta de ella (ya bloqueada en lockMatricula) y no cambia de curso.
            $convocatoria = $matricula->convocatoria_id === null
                ? null
                : Convocatoria::withTrashed()->findOrFail($matricula->convocatoria_id);
            if ($convocatoria !== null && $cursoAnteriorId !== $cursoNuevoId) {
                throw ValidationException::withMessages([
                    'curso_id' => 'La matrícula pertenece a una convocatoria: no se puede cambiar de curso.',
                ]);
            }

            $requiereCupoNuevo = $nuevaActiva
                && (! $anteriorActiva || $cursoAnteriorId !== $cursoNuevoId);

            if ($requiereCupoNuevo) {
                $this->assertEligibleForOccupation($estudiante, $cursoNuevo);
            }

            if ($requiereCupoNuevo && $convocatoria === null && $cursoNuevo->cupos_disponibles <= 0) {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso no tiene cupos disponibles.',
                ]);
            }

            if ($anteriorActiva && (! $nuevaActiva || $cursoAnteriorId !== $cursoNuevoId)) {
                if ($convocatoria !== null) {
                    $convocatoria->increment('cupos_disponibles');
                } elseif ($cursoAnterior !== null) {
                    $cursoAnterior->increment('cupos_disponibles');
                }
            }

            if ($requiereCupoNuevo) {
                if ($convocatoria !== null) {
                    $this->ocuparCupoConvocatoria($convocatoria);
                } else {
                    $cursoNuevo->decrement('cupos_disponibles');
                }
            }

            // Las diferencias se calculan sobre la fila bloqueada, antes de guardar. Anular mediante PUT produce
            // un único evento «matricula_anulada» con todas las diferencias permitidas, nunca dos eventos.
            $matricula->fill($datos);
            if ($anteriorActiva && ! $nuevaActiva) {
                $matricula->motivo_anulacion = $motivo;
            } elseif (! $anteriorActiva && $nuevaActiva) {
                $matricula->motivo_anulacion = null;
            }
            $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);
            $matricula->save();

            AuditoriaAcademica::registrarActualizacion(
                $anteriorActiva && ! $nuevaActiva ? 'matricula_anulada' : 'matricula_actualizada',
                $matricula,
                $diferencias,
                $actor,
                AuditoriaAcademica::efectoCupo($estadoAnterior, $cursoAnteriorId, (string) $datos['estado'], $cursoNuevoId),
            );

            return $matricula;
        });
    }

    public function eliminar(Matricula $matricula, int $actor, string $motivo): void
    {
        $motivo = trim($motivo);
        DB::transaction(function () use ($matricula, $actor, $motivo): void {
            $matricula = $this->lockMatricula($matricula->id);
            $this->asegurarSinPagosEnLibro($matricula);

            if ($matricula->estado !== 'anulada') {
                $this->liberarCupo($matricula);
            }

            $matricula->motivo_anulacion = $motivo;
            $matricula->save();
            $matricula->delete();

            // Última escritura de la transacción. El efecto y el estado salen de la fila bloqueada: eliminar una
            // vigente libera su cupo; eliminar una anulada no mueve ninguno.
            AuditoriaAcademica::registrarOperacionMatricula(
                'matricula_eliminada',
                $matricula,
                AuditoriaAcademica::OPERACION_ELIMINACION,
                AuditoriaAcademica::efectoCupo((string) $matricula->estado, (int) $matricula->curso_id, null, null),
                $actor,
            );
        });
    }

    public function anular(Matricula $matricula, int $actor, string $motivo): void
    {
        $motivo = trim($motivo);
        DB::transaction(function () use ($matricula, $actor, $motivo): void {
            $matricula = $this->lockMatricula($matricula->id);
            $this->asegurarSinPagosEnLibro($matricula);

            // Repetir la anulación no cambia nada, no devuelve cupo otra vez y no genera otro evento.
            if ($matricula->estado !== 'anulada') {
                $curso = $this->liberarCupo($matricula);

                $estadoAnterior = (string) $matricula->estado;
                $matricula->fill(['estado' => 'anulada']);
                $matricula->motivo_anulacion = $motivo;
                $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);
                $matricula->save();

                AuditoriaAcademica::registrarActualizacion(
                    'matricula_anulada',
                    $matricula,
                    $diferencias,
                    $actor,
                    AuditoriaAcademica::efectoCupo($estadoAnterior, (int) $curso->id, 'anulada', (int) $curso->id),
                );
            }
        });
    }

    public function restaurar(int|string $matriculaId, int $actor): void
    {
        DB::transaction(function () use ($matriculaId, $actor): void {
            $matricula = $this->lockMatricula($matriculaId, withTrashed: true);

            abort_unless($matricula->trashed(), 404);

            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);

                $estudiante = Estudiante::withTrashed()->lockForUpdate()->findOrFail($matricula->estudiante_id);
                $this->assertEligibleForOccupation($estudiante, $curso);

                $this->assertNoActiveDuplicate(
                    (int) $matricula->estudiante_id,
                    (int) $curso->id,
                );

                if ($matricula->convocatoria_id !== null) {
                    $convocatoria = Convocatoria::withTrashed()->lockForUpdate()->findOrFail($matricula->convocatoria_id);
                    $this->ocuparCupoConvocatoria($convocatoria);
                } else {
                    if ($curso->cupos_disponibles <= 0) {
                        throw ValidationException::withMessages([
                            'curso_id' => 'El curso no tiene cupos disponibles para restaurar esta matrícula.',
                        ]);
                    }

                    $curso->decrement('cupos_disponibles');
                }
            }

            $matricula->motivo_anulacion = null;
            $matricula->restore();

            // Última escritura de la transacción: restaurar una vigente vuelve a ocupar cupo; una anulada no.
            AuditoriaAcademica::registrarOperacionMatricula(
                'matricula_restaurada',
                $matricula,
                AuditoriaAcademica::OPERACION_RESTAURACION,
                AuditoriaAcademica::efectoCupo(null, (int) $matricula->curso_id, (string) $matricula->estado, (int) $matricula->curso_id),
                $actor,
            );
        });
    }

    /**
     * `validated()` no convierte tipos: un formulario HTML envía los identificadores como cadena y los métodos
     * estrictos (`int $estudianteId`) los rechazarían. Se normalizan una sola vez, justo tras la validación.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function conIdentificadoresEnteros(array $datos): array
    {
        foreach (['estudiante_id', 'curso_id'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $datos[$campo] = (int) $datos[$campo];
            }
        }

        return $datos;
    }

    /**
     * Dentro de la transacción: cursos por id, estudiantes por id, matrícula.
     * La lectura inicial solo descubre las relaciones; la fila bloqueada decide.
     * Si cambiaron mientras esperábamos, se rechaza sin escribir ni reintentar.
     */
    private function lockMatricula(int|string $id, ?int $cursoDestino = null, ?int $estudianteDestino = null, bool $withTrashed = false): Matricula
    {
        $query = Matricula::query()->withTrashed($withTrashed);
        $snapshot = (clone $query)->findOrFail($id);

        Curso::withTrashed()
            ->whereIn('id', array_unique([$snapshot->curso_id, $cursoDestino ?? $snapshot->curso_id]))
            ->orderBy('id')->lockForUpdate()->get();
        if ($snapshot->convocatoria_id !== null) {
            // Después de los cursos y antes de los estudiantes. La convocatoria de una matrícula no cambia nunca.
            Convocatoria::withTrashed()->lockForUpdate()->findOrFail($snapshot->convocatoria_id);
        }
        Estudiante::withTrashed()
            ->whereIn('id', array_unique([$snapshot->estudiante_id, $estudianteDestino ?? $snapshot->estudiante_id]))
            ->orderBy('id')->lockForUpdate()->get();
        $matricula = $query->lockForUpdate()->findOrFail($id);

        if ($matricula->curso_id !== $snapshot->curso_id || $matricula->estudiante_id !== $snapshot->estudiante_id) {
            throw ValidationException::withMessages([
                'curso_id' => 'La matrícula cambió durante la operación. Recarga los datos antes de continuar.',
            ]);
        }

        return $matricula;
    }

    /**
     * Devuelve el cupo de una matrícula vigente: a su convocatoria si la tiene y, si no, al curso. Devuelve el curso
     * (ya bloqueado) para que quien llama pueda auditar.
     */
    private function liberarCupo(Matricula $matricula): Curso
    {
        $curso = Curso::withTrashed()
            ->lockForUpdate()
            ->findOrFail($matricula->curso_id);

        if ($matricula->convocatoria_id !== null) {
            Convocatoria::withTrashed()->lockForUpdate()->findOrFail($matricula->convocatoria_id)->increment('cupos_disponibles');
        } else {
            $curso->increment('cupos_disponibles');
        }

        return $curso;
    }

    /** Ocupa un cupo de la convocatoria (ya bloqueada): solo si admite matrículas y le queda alguno. */
    private function ocuparCupoConvocatoria(Convocatoria $convocatoria): void
    {
        if ($convocatoria->trashed() || ! in_array($convocatoria->estado, Convocatoria::MATRICULABLES, true)) {
            throw ValidationException::withMessages([
                'convocatoria' => 'La convocatoria no admite matrículas en su estado actual.',
            ]);
        }
        if ($convocatoria->cupos_disponibles <= 0) {
            throw ValidationException::withMessages([
                'convocatoria' => 'La convocatoria no tiene cupos disponibles.',
            ]);
        }

        $convocatoria->decrement('cupos_disponibles');
    }

    private function assertEligibleForOccupation(Estudiante $estudiante, Curso $curso): void
    {
        if ($estudiante->trashed() || $estudiante->estado !== 'activo') {
            throw ValidationException::withMessages([
                'estudiante_id' => 'El estudiante seleccionado no está activo.',
            ]);
        }
        if ($curso->trashed() || $curso->estado !== 'activo') {
            throw ValidationException::withMessages([
                'curso_id' => 'El curso seleccionado no está disponible.',
            ]);
        }
    }

    /** Una matrícula con pagos confirmados o por confirmar en el libro no se anula ni elimina hasta resolverlos. */
    private function asegurarSinPagosEnLibro(Matricula $matricula): void
    {
        $obligacion = ObligacionPago::query()
            ->where('matricula_id', $matricula->id)
            ->where('estado', 'vigente')
            ->first();
        if ($obligacion === null) {
            return;
        }
        $saldos = app(GestionPagos::class)->saldos($obligacion);
        abort_if(
            $saldos['confirmado'] > 0 || $saldos['pendiente'] > 0,
            409,
            'La matrícula tiene pagos: reviértelos o recházalos antes de anularla o eliminarla.',
        );
    }

    private function assertNoActiveDuplicate(int $estudianteId, int $cursoId, ?int $excluirId = null): void
    {
        $query = Matricula::query()
            ->where('estudiante_id', $estudianteId)
            ->where('curso_id', $cursoId)
            ->where('estado', '!=', 'anulada');

        if ($excluirId !== null) {
            $query->where('id', '!=', $excluirId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'estudiante_id' => 'El estudiante ya está matriculado en este curso.',
            ]);
        }
    }
}
