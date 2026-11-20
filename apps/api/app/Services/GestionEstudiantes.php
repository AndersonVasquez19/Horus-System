<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Estudiante;
use App\Support\AuditoriaAcademica;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones transaccionales del módulo de Estudiantes (extraídas de Admin\EstudianteController sin cambiar
 * su comportamiento). Las usan el panel Blade y la API de los paneles de apps/web.
 *
 * - La auditoría es siempre la última escritura de la transacción: si falla, la operación se revierte.
 * - Actualizar, eliminar y restaurar releen la fila con `lockForUpdate()`.
 * - Eliminar (desactivar) está prohibido mientras el estudiante tenga matrículas vigentes (`pendiente` o
 *   `pagada`), evaluado bajo el bloqueo del estudiante: seguiría ocupando cupos de sus cursos.
 */
final class GestionEstudiantes
{
    /** Estados de matrícula que ocupan cupo e impiden desactivar al estudiante. */
    private const ESTADOS_VIGENTES = ['pendiente', 'pagada'];

    /**
     * @param  array<string, mixed>  $datos  datos ya validados (StoreEstudianteRequest)
     */
    public function crear(array $datos, int $actor): Estudiante
    {
        return DB::transaction(function () use ($datos, $actor): Estudiante {
            $estudiante = Estudiante::create($datos);

            AuditoriaAcademica::registrarCreacion('estudiante_creado', $estudiante, AuditoriaAcademica::ESTUDIANTE, $actor);

            return $estudiante;
        });
    }

    /**
     * @param  array<string, mixed>  $datos  datos ya validados (UpdateEstudianteRequest)
     */
    public function actualizar(Estudiante $estudiante, array $datos, int $actor): Estudiante
    {
        return DB::transaction(function () use ($estudiante, $datos, $actor): Estudiante {
            // Se relee la fila bajo bloqueo para que «antes» sea el valor real y no el del enlace de ruta.
            $bloqueado = Estudiante::query()->lockForUpdate()->findOrFail($estudiante->id);
            $bloqueado->fill($datos);
            $diferencias = AuditoriaAcademica::diferencias($bloqueado, AuditoriaAcademica::ESTUDIANTE);
            $bloqueado->save();

            AuditoriaAcademica::registrarActualizacion('estudiante_actualizado', $bloqueado, $diferencias, $actor);

            return $bloqueado;
        });
    }

    /**
     * Eliminación lógica. Devuelve el número de matrículas vigentes que la impidieron (0 si se eliminó o si otra
     * petición ya lo había eliminado: en ese caso no hay operación efectiva ni auditoría).
     */
    public function eliminar(Estudiante $estudiante, int $actor): int
    {
        return DB::transaction(function () use ($estudiante, $actor): int {
            // Sin withTrashed(): si otra petición ya lo eliminó, no hay operación efectiva ni auditoría.
            $bloqueado = Estudiante::query()->lockForUpdate()->find($estudiante->id);

            if ($bloqueado === null) {
                return 0;
            }

            $vigentes = $bloqueado->matriculas()->whereIn('estado', self::ESTADOS_VIGENTES)->count();
            if ($vigentes > 0) {
                return $vigentes;
            }

            $bloqueado->delete();

            AuditoriaAcademica::registrarOperacion('estudiante_eliminado', $bloqueado, 'eliminacion_logica', $actor);

            return 0;
        });
    }

    /** Restaurar un estudiante no eliminado no es una operación efectiva: no se audita (evita duplicados). */
    public function restaurar(int|string $estudianteId, int $actor): Estudiante
    {
        return DB::transaction(function () use ($estudianteId, $actor): Estudiante {
            $bloqueado = Estudiante::withTrashed()->lockForUpdate()->findOrFail($estudianteId);

            if (! $bloqueado->trashed()) {
                return $bloqueado;
            }

            $bloqueado->restore();

            AuditoriaAcademica::registrarOperacion('estudiante_restaurado', $bloqueado, 'restauracion', $actor);

            return $bloqueado;
        });
    }

    public static function mensajeMatriculasVigentes(int $vigentes): string
    {
        return 'No se puede desactivar al estudiante: tiene '.$vigentes.' matrícula(s) vigente(s). Anúlalas primero para liberar sus cupos.';
    }
}
