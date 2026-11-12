<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Curso;
use App\Models\Estudiante;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opciones de los selectores de estudiante y curso del panel de matrículas (formularios y filtro del listado).
 *
 * Los selectores buscan en el servidor y devuelven como máximo {@see self::LIMITE} opciones en lugar de cargar
 * todos los registros en la página. Las reglas de elegibilidad son las de siempre y no se amplían: la validación
 * de escritura (`StoreMatriculaRequest`, `UpdateMatriculaRequest` y la relectura bajo bloqueo del controlador)
 * sigue siendo la autoridad; estas opciones solo proponen.
 *
 * Solo se expone lo que el selector ya mostraba: identificador y etiqueta (sin correo, teléfono ni descripciones).
 */
final class OpcionesMatricula
{
    public const LIMITE = 20;

    public const CREAR = 'crear';

    public const EDITAR = 'editar';

    public const FILTRO = 'filtro';

    public const CONTEXTOS = [self::CREAR, self::EDITAR, self::FILTRO];

    /** Columnas que usa la etiqueta del estudiante (incluye deleted_at para trashed()). */
    private const COLUMNAS_ESTUDIANTE = ['id', 'dni', 'nombres', 'apellidos', 'deleted_at'];

    /** Columnas que usa la etiqueta del curso (incluye deleted_at para trashed()). */
    private const COLUMNAS_CURSO = ['id', 'codigo', 'nombre', 'cupos_disponibles', 'deleted_at'];

    /**
     * @return list<array{id: int, texto: string, eliminado: bool}>
     */
    public static function estudiantes(string $contexto, string $texto): array
    {
        $consulta = self::consultaEstudiantes($contexto);
        BusquedaLike::contiene($consulta, ['dni', 'nombres', 'apellidos'], $texto);

        return $consulta
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->orderBy('id')
            ->limit(self::LIMITE)
            ->get(self::COLUMNAS_ESTUDIANTE)
            ->map(fn (Estudiante $estudiante): array => self::opcionEstudiante($estudiante))
            ->all();
    }

    /**
     * @return list<array{id: int, texto: string, eliminado: bool}>
     */
    public static function cursos(string $contexto, string $texto): array
    {
        $consulta = self::consultaCursos($contexto);
        BusquedaLike::contiene($consulta, ['codigo', 'nombre'], $texto);

        return $consulta
            ->orderBy('nombre')
            ->orderBy('id')
            ->limit(self::LIMITE)
            ->get(self::COLUMNAS_CURSO)
            ->map(fn (Curso $curso): array => self::opcionCurso($curso, $contexto))
            ->all();
    }

    /**
     * Opción inicial de un formulario o filtro (valor previo, preselección o registro actual de la matrícula).
     * Solo se resuelve lo que el selector habría ofrecido, más el registro actual aunque ya no sea elegible
     * (p. ej. un estudiante eliminado de una matrícula existente). Cualquier valor que no sea un identificador
     * entero, como un array manipulado, se trata como vacío.
     *
     * @return array{id: int, texto: string, eliminado: bool}|null
     */
    public static function estudiante(mixed $id, string $contexto, ?int $actualId = null): ?array
    {
        $id = self::identificador($id);
        if ($id === null) {
            return null;
        }

        $consulta = $id === $actualId ? Estudiante::withTrashed() : self::consultaEstudiantes($contexto);
        $estudiante = $consulta->whereKey($id)->first(self::COLUMNAS_ESTUDIANTE);

        return $estudiante instanceof Estudiante ? self::opcionEstudiante($estudiante) : null;
    }

    /**
     * @return array{id: int, texto: string, eliminado: bool}|null
     */
    public static function curso(mixed $id, string $contexto, ?int $actualId = null): ?array
    {
        $id = self::identificador($id);
        if ($id === null) {
            return null;
        }

        $consulta = $id === $actualId ? Curso::withTrashed() : self::consultaCursos($contexto);
        $curso = $consulta->whereKey($id)->first(self::COLUMNAS_CURSO);

        return $curso instanceof Curso ? self::opcionCurso($curso, $contexto) : null;
    }

    /** @return Builder<Estudiante> */
    private static function consultaEstudiantes(string $contexto): Builder
    {
        // Crear: solo estudiantes activos. Editar: cualquier estudiante no eliminado.
        return $contexto === self::CREAR
            ? Estudiante::query()->where('estado', 'activo')
            : Estudiante::query();
    }

    /** @return Builder<Curso> */
    private static function consultaCursos(string $contexto): Builder
    {
        return match ($contexto) {
            // Crear: cursos activos con cupos. Editar: cualquier curso no eliminado.
            self::CREAR => Curso::query()->where('estado', 'activo')->where('cupos_disponibles', '>', 0),
            // El filtro del listado incluye los cursos eliminados, como antes.
            self::FILTRO => Curso::withTrashed(),
            default => Curso::query(),
        };
    }

    /** @return array{id: int, texto: string, eliminado: bool} */
    private static function opcionEstudiante(Estudiante $estudiante): array
    {
        return [
            'id' => (int) $estudiante->getKey(),
            'texto' => "{$estudiante->dni} · {$estudiante->nombres} {$estudiante->apellidos}".($estudiante->trashed() ? ' (eliminado)' : ''),
            'eliminado' => $estudiante->trashed(),
        ];
    }

    /** @return array{id: int, texto: string, eliminado: bool} */
    private static function opcionCurso(Curso $curso, string $contexto): array
    {
        // Los formularios muestran los cupos; el filtro del listado, no.
        $cupos = $contexto === self::FILTRO ? '' : " ({$curso->cupos_disponibles} cupos)";

        return [
            'id' => (int) $curso->getKey(),
            'texto' => "{$curso->codigo} · {$curso->nombre}{$cupos}".($curso->trashed() ? ' (eliminado)' : ''),
            'eliminado' => $curso->trashed(),
        ];
    }

    private static function identificador(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        return is_string($id) && ctype_digit($id) && strlen($id) <= 18 && (int) $id > 0 ? (int) $id : null;
    }
}
