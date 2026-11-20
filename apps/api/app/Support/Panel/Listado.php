<?php

declare(strict_types=1);

namespace App\Support\Panel;

use App\Support\Entrada;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Convenciones de listado de /api/panel/v1 (contrato en packages/contracts/openapi):
 *
 *  - `page` (≥ 1) y `per_page` (1..100, por defecto 20).
 *  - `sort=campo` ascendente o `sort=-campo` descendente, solo con campos de la lista blanca del recurso; un valor
 *    desconocido usa el orden por defecto (no es un error: la URL puede venir de un marcador antiguo).
 *  - Filtros con nombre propio (`busqueda`, `estado`…); los valores fuera de lista se ignoran (ListFilter).
 *  - Respuesta: `{ data: [...], meta: { pagina, por_pagina, total, paginas, orden } }`.
 *
 * El orden siempre termina en `id` para que la paginación sea estable.
 */
final class Listado
{
    public const POR_PAGINA = 20;

    public const MAX_POR_PAGINA = 100;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $ordenables  nombre público => columna
     */
    public static function ordenar(Builder $query, Request $request, array $ordenables, string $porDefecto): string
    {
        $solicitado = $request->query('sort');
        $orden = is_string($solicitado) && array_key_exists(ltrim($solicitado, '-'), $ordenables) ? $solicitado : $porDefecto;
        $campo = ltrim($orden, '-');

        $query->orderBy($ordenables[$campo], str_starts_with($orden, '-') ? 'desc' : 'asc')
            ->orderBy($query->getModel()->getQualifiedKeyName(), str_starts_with($orden, '-') ? 'desc' : 'asc');

        return $orden;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginar(Builder $query, Request $request): LengthAwarePaginator
    {
        $porPagina = filter_var($request->query('per_page'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_POR_PAGINA],
        ]);
        $pagina = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $query->paginate(perPage: $porPagina ?: self::POR_PAGINA, page: $pagina ?: 1);
    }

    /**
     * Cuerpo de una respuesta de {@see responder()} como arreglo, para añadirle opciones a `meta` (filtros, totales).
     *
     * @return array{data: mixed, meta: array<mixed>}
     */
    public static function contenido(JsonResponse $respuesta): array
    {
        $contenido = Entrada::lista($respuesta->getData(true));

        return ['data' => $contenido['data'] ?? [], 'meta' => Entrada::lista($contenido['meta'] ?? [])];
    }

    /**
     * @template TModelo
     *
     * @param  LengthAwarePaginator<int, TModelo>  $paginas
     * @param  Closure(TModelo): array<string, mixed>  $representar
     * @param  array<string, mixed>  $filtros  filtros efectivamente aplicados (eco para la interfaz)
     */
    public static function responder(LengthAwarePaginator $paginas, Closure $representar, string $orden, array $filtros = []): JsonResponse
    {
        return response()->json([
            'data' => array_map($representar, $paginas->items()),
            'meta' => [
                'pagina' => $paginas->currentPage(),
                'por_pagina' => $paginas->perPage(),
                'total' => $paginas->total(),
                'paginas' => $paginas->lastPage(),
                'orden' => $orden,
                'filtros' => Arr::where($filtros, fn ($valor): bool => $valor !== '' && $valor !== null && $valor !== false),
            ],
        ]);
    }
}
