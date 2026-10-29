<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CatalogoPublicoServicios;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;

class ServiciosPublicosController extends Controller
{
    public function __invoke(Request $request, CatalogoPublicoServicios $catalogo): Response
    {
        $habilitado = (bool) config('catalogo-servicios.enabled', false);
        $servicios = [];
        $total = 0;
        $pagina = 1;
        $porPagina = 12;
        $fallo = false;
        if ($habilitado) {
            $datos = $request->validate(['servicios_pagina' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
            $pagina = (int) ($datos['servicios_pagina'] ?? 1);
            try {
                $tablas = array_unique(array_merge(['servicios_tecnologicos', 'servicio_publicaciones', 'users'], array_values(config('permission.table_names', []))));
                foreach ($tablas as $tabla) {
                    if (! Schema::hasTable($tabla)) {
                        $fallo = true;
                        Log::warning('servicios_web_esquema_incompleto');
                        break;
                    }
                }
                if (! $fallo) {
                    Permission::findByName('servicios.publicar', 'web');
                    $resultado = $catalogo->pagina($pagina, $porPagina);
                    $total = $resultado['total'];
                    foreach ($resultado['servicios'] as $ficha) {
                        // Defensa de presentación ante registros heredados ajenos al editor.
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
                }
            } catch (RoleDoesNotExist|PermissionDoesNotExist) {
                $fallo = true;
                $total = 0;
                $servicios = [];
                Log::warning('servicios_web_provision_incompleta');
            } catch (QueryException) {
                // Solo errores SQL esperables; nunca SQL, bindings, excepciones o contenido en logs.
                $fallo = true;
                $total = 0;
                $servicios = [];
                Log::warning('servicios_web_lectura_fallida');
            }
        }

        return response()->view('pages.tecnologias.soporte-mantenimiento', [
            'catalogoServiciosHabilitado' => $habilitado, 'catalogoServiciosFallo' => $fallo,
            'serviciosPublicados' => $servicios, 'serviciosTotal' => $total,
            'serviciosPagina' => $pagina, 'serviciosPorPagina' => $porPagina,
        ])->header('Cache-Control', 'no-store, private');
    }
}
