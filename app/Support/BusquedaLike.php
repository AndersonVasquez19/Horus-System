<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Búsqueda «contiene» de los listados del panel, con el texto del usuario tratado como texto literal.
 *
 * `whereLike('col', "%{$q}%")` deja que un `%` o un `_` escrito en el buscador actúe como comodín (buscar «%»
 * devolvería todo el listado). Aquí `!`, `%` y `_` del texto se escapan y la condición declara `ESCAPE '!'`,
 * que se comporta igual en PostgreSQL y en SQLite (que no define un escape implícito).
 *
 * Insensible a mayúsculas como antes: ILIKE en PostgreSQL y LIKE (ASCII) en SQLite. El operador y las columnas
 * son SQL fijo del código que llama (las columnas pasan por la gramática de la conexión); el texto del usuario
 * viaja siempre como parámetro.
 */
final class BusquedaLike
{
    public const ESCAPE = '!';

    /** Texto del usuario convertido en patrón «contiene», con `!`, `%` y `_` escapados. */
    public static function termino(string $texto): string
    {
        return '%'.strtr($texto, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }

    /**
     * Añade al constructor una condición agrupada: alguna de las columnas contiene el texto.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  list<string>  $columnas  nombres de columna fijos del código que llama, nunca entrada del usuario
     * @param  list<string>  $comoTexto  columnas no textuales (p. ej. `id`) que se comparan convertidas a texto
     * @param  bool  $or  une la condición con OR en lugar de AND
     */
    public static function contiene(EloquentBuilder|QueryBuilder $query, array $columnas, string $texto, array $comoTexto = [], bool $or = false): void
    {
        if ($texto === '' || $columnas === []) {
            return;
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $gramatica = $base->getGrammar();
        // PostgreSQL distingue mayúsculas en LIKE; ILIKE conserva la búsqueda insensible de los listados.
        $operador = $base->getConnection() instanceof PostgresConnection ? 'ILIKE' : 'LIKE';
        $patron = self::termino($texto);

        $grupo = static function (EloquentBuilder|QueryBuilder $grupo) use ($columnas, $comoTexto, $gramatica, $operador, $patron): void {
            foreach ($columnas as $columna) {
                $expresion = $gramatica->wrap($columna);
                if (in_array($columna, $comoTexto, true)) {
                    $expresion = "CAST({$expresion} AS TEXT)";
                }

                $grupo->orWhereRaw("{$expresion} {$operador} ? ESCAPE '".self::ESCAPE."'", [$patron]);
            }
        };

        $or ? $query->orWhere($grupo) : $query->where($grupo);
    }
}
