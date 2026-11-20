<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Curso;
use App\Support\AuditoriaAcademica;
use App\Support\Entrada;
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
            $bloqueado = Curso::query()->lockForUpdate()->findOrFail($curso->id);
            $matriculasActivas = $bloqueado->matriculas()->where('estado', '!=', 'anulada')->count();

            if ($datos['cupos_maximos'] < $matriculasActivas) {
                throw ValidationException::withMessages([
                    'cupos_maximos' => "No puedes reducir los cupos por debajo de las {$matriculasActivas} matrículas activas.",
                ]);
            }

            $datos['cupos_disponibles'] = Entrada::entero($datos['cupos_maximos']) - $matriculasActivas;
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
            $bloqueado = Curso::query()->lockForUpdate()->find($curso->id);

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

    /** Duplica la definición del curso en estado inactivo, con código único y sin copiar el enlace de WhatsApp. */
    public function duplicar(Curso $origen, int $actor): Curso
    {
        return DB::transaction(function () use ($origen, $actor): Curso {
            $base = mb_substr(trim($origen->codigo), 0, 12);
            $candidato = $base.'-COPIA';
            $intento = 2;
            while (Curso::withTrashed()->where('codigo', $candidato)->exists()) {
                $sufijo = '-C'.$intento;
                $candidato = mb_substr($origen->codigo, 0, 20 - strlen($sufijo)).$sufijo;
                $intento++;
            }

            $nombre = trim($origen->nombre);
            $sufijoNombre = ' (copia)';
            if (mb_strlen($nombre) + mb_strlen($sufijoNombre) > 150) {
                $nombre = mb_substr($nombre, 0, 150 - mb_strlen($sufijoNombre));
            }

            $copia = Curso::create([
                'codigo' => $candidato,
                'nombre' => $nombre.$sufijoNombre,
                'descripcion' => $origen->descripcion,
                'categoria' => $origen->categoria,
                'duracion_horas' => $origen->duracion_horas,
                'precio' => $origen->precio,
                'modalidad' => $origen->modalidad,
                'horario' => $origen->horario,
                'enlace_grupo_whatsapp' => null,
                'cupos_maximos' => $origen->cupos_maximos,
                'cupos_disponibles' => $origen->cupos_maximos,
                'estado' => 'inactivo',
            ]);

            AuditoriaAcademica::registrarCreacion('curso_creado', $copia, AuditoriaAcademica::CURSO, $actor);

            return $copia;
        });
    }
}
