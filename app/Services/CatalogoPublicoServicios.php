<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class CatalogoPublicoServicios
{
    /**
     * Lector preparado, sin endpoint ni consumidores públicos. No escribe ni retiene snapshots.
     *
     * @return array{version: string, habilitado: bool, total: int, pagina: int, por_pagina: int, servicios: list<array<string, string|null>>}
     */
    public function pagina(int $pagina = 1, int $porPagina = 20): array
    {
        if ($pagina < 1 || $pagina > 1000000 || $porPagina < 1 || $porPagina > 50) {
            throw new InvalidArgumentException('Página entre 1 y 1000000; tamaño entre 1 y 50.');
        }
        $habilitado = (bool) config('catalogo-servicios.enabled', false);
        $resultado = ['version' => '1.0', 'habilitado' => $habilitado, 'total' => 0, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'servicios' => []];
        if (! $habilitado || ! Schema::hasTable('servicio_publicaciones') || ! Schema::hasTable('servicios_tecnologicos')) {
            return $resultado;
        }
        try {
            $autores = User::permission('servicios.publicar')->role(RolesAndPermissionsSeeder::STAFF_ROLES)->select('users.id');
        } catch (PermissionDoesNotExist) {
            return $resultado;
        }
        $hoy = now('America/Lima')->toDateString();
        $consulta = DB::table('servicio_publicaciones as p')->join('servicios_tecnologicos as s', 's.id', '=', 'p.servicio_tecnologico_id')
            ->where('s.estado', 'activo')->where('p.estado', 'publicada')
            ->where('p.vigente_desde', '<=', $hoy)->where('p.vigente_hasta', '>=', $hoy)
            ->whereNotNull('p.autorizado_at')->where('p.autorizado_at', '<=', now())
            ->whereIn('p.autorizado_por', $autores);
        $resultado['total'] = (clone $consulta)->count();
        $resultado['servicios'] = $consulta->orderBy('s.id')->offset(($pagina - 1) * $porPagina)->limit($porPagina)
            ->get(['s.identificador_publico', 'p.titulo', 'p.resumen', 'p.descripcion', 'p.categoria', 'p.vigente_desde', 'p.vigente_hasta'])
            ->map(fn (object $fila): array => [
                'identificador_publico' => (string) $fila->identificador_publico,
                'titulo' => (string) $fila->titulo, 'resumen' => (string) $fila->resumen,
                'descripcion' => (string) $fila->descripcion, 'categoria' => $fila->categoria === null ? null : (string) $fila->categoria,
                'vigente_desde' => substr((string) $fila->vigente_desde, 0, 10), 'vigente_hasta' => substr((string) $fila->vigente_hasta, 0, 10),
            ])->all();

        return $resultado;
    }
}
