<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Curso;
use App\Support\AuditoriaAcademica;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operaciones transaccionales del módulo de Cursos (extraídas de Admin\CursoController sin cambiar su
 * comportamiento). Las usan el panel Blade y la API de los paneles de apps/web.
 *
 * - La auditoría es siempre la última escritura de la transacción: si falla, la operación se revierte.
 * - Actualizar, archivar y restaurar releen la fila con `lockForUpdate()`.
 * - Archivar está prohibido mientras el curso tenga matrículas vigentes (`pendiente` o `pagada`).
 */
final class GestionCursos
{
    private const ESTADOS_VIGENTES = ['pendiente', 'pagada'];

    /** @param  array<string, mixed>  $datos  datos ya validados (StoreCursoRequest) */
    public function crear(array $datos, int $actor): Curso
    {
        $datos['cupos_disponibles'] = $datos['cupos_maximos'];

        return DB::transaction(function () use ($datos, $actor): Curso {
            $curso = Curso::create($datos);

            AuditoriaAcademica::registrarCreacion('curso_creado', $curso, AuditoriaAcademica::CURSO, $actor);

            return $curso;
        });
    }

    /**
     * No se puede reducir el cupo máximo por debajo de las matrículas no anuladas (ValidationException).
     *
     * @param  array<string, mixed>  $datos  datos ya validados (UpdateCursoRequest)
     */
    public function actualizar(Curso $curso, array $datos, int $actor): Curso
    {
        return DB::transaction(function () use ($curso, $datos, $actor): Curso {
            $bloqueado = Curso::query()->lockForUpdate()->findOrFail($curso->getKey());
            $matriculasActivas = $bloqueado->matriculas()->where('estado', '!=', 'anulada')->count();

            if ($datos['cupos_maximos'] < $matriculasActivas) {
                throw ValidationException::withMessages([
                    'cupos_maximos' => "No puedes reducir los cupos por debajo de las {$matriculasActivas} matrículas activas.",
                ]);
            }

            $datos['cupos_disponibles'] = $datos['cupos_maximos'] - $matriculasActivas;
            $bloqueado->fill($datos);
            $diferencias = AuditoriaAcademica::diferencias($bloqueado, AuditoriaAcademica::CURSO);
            $bloqueado->save();

            AuditoriaAcademica::registrarActualizacion('curso_actualizado', $bloqueado, $diferencias, $actor);

            return $bloqueado;
        });
    }

    /**
     * Archivado lógico. Devuelve las matrículas vigentes que lo impidieron (0 si se archivó o si otra petición
     * ya lo había archivado: en ese caso no hay operación efectiva ni auditoría).
     */
    public function archivar(Curso $curso, int $actor): int
    {
        return DB::transaction(function () use ($curso, $actor): int {
            $bloqueado = Curso::query()->lockForUpdate()->find($curso->getKey());

            if ($bloqueado === null) {
                return 0;
            }

            $vigentes = $bloqueado->matriculas()->whereIn('estado', self::ESTADOS_VIGENTES)->count();
            if ($vigentes > 0) {
                return $vigentes;
            }

            $bloqueado->delete();

            AuditoriaAcademica::registrarOperacion('curso_archivado', $bloqueado, 'archivado_logico', $actor);

            return 0;
        });
    }

    public function restaurar(int|string $curso, int $actor): Curso
    {
        return DB::transaction(function () use ($curso, $actor): Curso {
            $bloqueado = Curso::withTrashed()->lockForUpdate()->findOrFail($curso);

            // Restaurar un curso no archivado no es una operación efectiva: no se audita (evita duplicados).
            if ($bloqueado->trashed()) {
                $bloqueado->restore();

                AuditoriaAcademica::registrarOperacion('curso_restaurado', $bloqueado, 'restauracion', $actor);
            }

            return $bloqueado;
        });
    }

    public static function mensajeMatriculasVigentes(int $vigentes): string
    {
        return 'No se puede archivar el curso: tiene '.$vigentes.' matrícula(s) vigente(s). Anúlalas primero para liberar sus cupos.';
    }
}
