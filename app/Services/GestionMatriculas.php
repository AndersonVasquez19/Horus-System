<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\AuditoriaAcademica;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operaciones transaccionales del módulo de Matrículas.
 *
 * Centraliza la máquina de estados (`pendiente`, `pagada`, `anulada`), el protocolo de bloqueo
 * pesimista en orden fijo (cursos por id -> estudiantes por id -> matrícula), la verificación de
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

        return DB::transaction(function () use ($datos, $actor): Matricula {
            $curso = Curso::withTrashed()
                ->lockForUpdate()
                ->findOrFail($datos['curso_id']);

            // La validación HTTP puede haber quedado obsoleta antes de adquirir el lock.
            if ($curso->trashed() || $curso->estado !== 'activo') {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso seleccionado no está disponible.',
                ]);
            }

            $estudiante = Estudiante::withTrashed()->lockForUpdate()->findOrFail($datos['estudiante_id']);
            if ($estudiante->trashed() || $estudiante->estado !== 'activo') {
                throw ValidationException::withMessages([
                    'estudiante_id' => 'El estudiante seleccionado no está activo.',
                ]);
            }

            if ($datos['estado'] !== 'anulada') {
                $this->assertNoActiveDuplicate((int) $datos['estudiante_id'], (int) $curso->id);

                if ($curso->cupos_disponibles <= 0) {
                    throw ValidationException::withMessages([
                        'curso_id' => 'El curso no tiene cupos disponibles.',
                    ]);
                }

                $curso->decrement('cupos_disponibles');
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

        return DB::transaction(function () use ($matricula, $datos, $usuario, $actor): Matricula {
            $matricula = $this->lockMatricula($matricula->id, (int) $datos['curso_id'], (int) $datos['estudiante_id']);

            $estadoAnterior = (string) $matricula->estado;
            $anteriorActiva = $matricula->estado !== 'anulada';
            $nuevaActiva = $datos['estado'] !== 'anulada';
            if ($anteriorActiva && ! $nuevaActiva) {
                abort_unless($usuario?->can('matriculas.anular') === true, 403);
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

            $requiereCupoNuevo = $nuevaActiva
                && (! $anteriorActiva || $cursoAnteriorId !== $cursoNuevoId);

            if ($requiereCupoNuevo) {
                $this->assertEligibleForOccupation($estudiante, $cursoNuevo);
            }

            if ($requiereCupoNuevo && $cursoNuevo->cupos_disponibles <= 0) {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso no tiene cupos disponibles.',
                ]);
            }

            if ($anteriorActiva && (! $nuevaActiva || $cursoAnteriorId !== $cursoNuevoId) && $cursoAnterior !== null) {
                $cursoAnterior->increment('cupos_disponibles');
            }

            if ($requiereCupoNuevo) {
                $cursoNuevo->decrement('cupos_disponibles');
            }

            // Las diferencias se calculan sobre la fila bloqueada, antes de guardar. Anular mediante PUT produce
            // un único evento «matricula_anulada» con todas las diferencias permitidas, nunca dos eventos.
            $matricula->fill($datos);
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

    public function eliminar(Matricula $matricula, int $actor): void
    {
        DB::transaction(function () use ($matricula, $actor): void {
            $matricula = $this->lockMatricula($matricula->id);

            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);
                $curso->increment('cupos_disponibles');
            }

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

    public function anular(Matricula $matricula, int $actor): void
    {
        DB::transaction(function () use ($matricula, $actor): void {
            $matricula = $this->lockMatricula($matricula->id);

            // Repetir la anulación no cambia nada, no devuelve cupo otra vez y no genera otro evento.
            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);
                $curso->increment('cupos_disponibles');

                $estadoAnterior = (string) $matricula->estado;
                $matricula->fill(['estado' => 'anulada']);
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

                if ($curso->cupos_disponibles <= 0) {
                    throw ValidationException::withMessages([
                        'curso_id' => 'El curso no tiene cupos disponibles para restaurar esta matrícula.',
                    ]);
                }

                $curso->decrement('cupos_disponibles');
            }

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
