<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Services\CatalogoPublicoCursos;
use App\Services\CatalogoPublicoServicios;
use App\Services\ContenidoPublicoServicios;
use App\Support\Entrada;
use App\Support\PaginasServicios;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;

/**
 * Catálogo público en JSON para el BFF de apps/web (solo lectura). Mismos servicios y mismas defensas de
 * presentación que las vistas Blade (`CursosPublicosController`, `ServiciosPublicosController`): nada de precio,
 * cupos ni datos administrativos. Un fallo técnico responde 503 con un código genérico, sin SQL ni contenido.
 */
final class CatalogoPublicoController extends Controller
{
    private const MODALIDADES = ['presencial', 'semipresencial', 'virtual', 'hibrido'];

    /** Mapa segmento de la URL → código de servicio. */
    private const PAGINAS = [
        'cableado-estructurado' => PaginasServicios::CABLEADO,
        'camaras-seguridad' => PaginasServicios::VIDEOVIGILANCIA,
        'soporte-mantenimiento' => PaginasServicios::SOPORTE,
    ];

    public function cursos(Request $request, CatalogoPublicoCursos $catalogo): JsonResponse
    {
        $datos = $request->validate(['modalidad' => ['sometimes', 'string', 'in:'.implode(',', self::MODALIDADES)]]);
        $modalidad = $datos['modalidad'] ?? null;
        $habilitado = (bool) config('catalogo-publico.enabled', false);
        $cursos = [];
        $total = 0;
        if ($habilitado) {
            try {
                if (! Schema::hasTable('curso_publicaciones')) {
                    Log::warning('catalogo_web_esquema_incompleto');

                    return $this->noDisponible();
                }
                foreach ($catalogo->paraWeb()['cursos'] as $ficha) {
                    if (trim($ficha['titulo']) === '' || mb_strlen($ficha['titulo']) > 120
                        || trim($ficha['resumen']) === '' || mb_strlen($ficha['resumen']) > 500
                        || ! in_array($ficha['modalidad'], self::MODALIDADES, true)
                        || mb_strlen($ficha['horario'] ?? '') > 200 || mb_strlen($ficha['inscripcion'] ?? '') > 400) {
                        Log::warning('catalogo_web_ficha_invalida');

                        continue;
                    }
                    if ($modalidad !== null && $ficha['modalidad'] !== $modalidad) {
                        continue;
                    }
                    $total++;
                    if (count($cursos) < 50) {
                        $cursos[] = $ficha;
                    }
                }
            } catch (QueryException) {
                Log::warning('catalogo_web_lectura_fallida');

                return $this->noDisponible();
            }
        }

        return response()->json(['habilitado' => $habilitado, 'modalidad' => $modalidad, 'total' => $total, 'cursos' => $cursos]);
    }

    public function servicios(Request $request, CatalogoPublicoServicios $catalogo): JsonResponse
    {
        $habilitado = (bool) config('catalogo-servicios.enabled', false);
        $datos = $request->validate(['pagina' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $pagina = (int) ($datos['pagina'] ?? 1);
        $porPagina = 12;
        $servicios = [];
        $total = 0;
        if ($habilitado) {
            try {
                $tablas = array_unique(array_merge(['servicios_tecnologicos', 'servicio_publicaciones', 'users'], Entrada::listaDeTexto(config('permission.table_names', []))));
                foreach ($tablas as $tabla) {
                    if (! Schema::hasTable($tabla)) {
                        Log::warning('servicios_web_esquema_incompleto');

                        return $this->noDisponible();
                    }
                }
                Permission::findByName('servicios.publicar', 'web');
                $resultado = $catalogo->pagina($pagina, $porPagina);
                $total = $resultado['total'];
                foreach ($resultado['servicios'] as $ficha) {
                    foreach (['titulo' => 120, 'resumen' => 500, 'descripcion' => 5000] as $campo => $maximo) {
                        if (! is_string($ficha[$campo] ?? null) || trim($ficha[$campo]) === '' || mb_strlen($ficha[$campo]) > $maximo) {
                            Log::warning('servicios_web_ficha_invalida');

                            continue 2;
                        }
                    }
                    if (isset($ficha['categoria']) && mb_strlen($ficha['categoria']) > 80) {
                        Log::warning('servicios_web_ficha_invalida');

                        continue;
                    }
                    $servicios[] = $ficha;
                }
            } catch (RoleDoesNotExist|PermissionDoesNotExist) {
                Log::warning('servicios_web_provision_incompleta');

                return $this->noDisponible();
            } catch (QueryException) {
                Log::warning('servicios_web_lectura_fallida');

                return $this->noDisponible();
            }
        }

        return response()->json(['habilitado' => $habilitado, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'servicios' => $servicios]);
    }

    /** Contenido editorial de una de las tres páginas de tecnologías; `contenido: null` = usar el texto estático de Next. */
    public function pagina(string $pagina, ContenidoPublicoServicios $contenido): JsonResponse
    {
        abort_unless(isset(self::PAGINAS[$pagina]), 404);

        return response()->json(['pagina' => $pagina, 'contenido' => $contenido->pagina(self::PAGINAS[$pagina])]);
    }

    private function noDisponible(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'catalogo_no_disponible', 'message' => 'El catálogo no está disponible.']], 503);
    }
}
