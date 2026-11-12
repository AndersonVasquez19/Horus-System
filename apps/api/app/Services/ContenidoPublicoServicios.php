<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\PaginasServicios;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Contenido dinámico de las tres páginas de Servicios Tecnológicos (cableado, videovigilancia, soporte).
 *
 * Devuelve null —y la vista usa su contenido estático original— si el catálogo está apagado, falta infraestructura
 * o la ficha no está activa, publicada, autorizada por un publicador vigente y dentro de su vigencia (America/Lima).
 * Con el catálogo apagado no consulta la base de datos. Nunca escribe.
 */
class ContenidoPublicoServicios
{
    private const MAX_TARJETAS = 60;

    /**
     * @return array{titulo: string, parrafos: list<string>, imagen_url: string|null, imagen_alt: string, titulo_tarjetas: string|null, tarjetas: list<array{titulo: string, descripcion: string, caracteristicas: list<string>, icono: string|null, variante: string|null, imagen_url: string|null, imagen_alt: string}>}|null
     */
    public function pagina(string $codigo): ?array
    {
        if (! (bool) config('catalogo-servicios.enabled', false) || ! in_array($codigo, PaginasServicios::codigos(), true)) {
            return null;
        }
        try {
            if (! Schema::hasTable('servicio_publicaciones') || ! Schema::hasTable('servicios_tecnologicos') || ! Schema::hasTable('servicio_publicacion_elementos')
                || ! Schema::hasColumn('servicio_publicaciones', 'imagen_principal_ruta')) {
                return null;
            }
            $autores = User::permission('servicios.publicar')->role(RolesAndPermissionsSeeder::STAFF_ROLES)->select('users.id');
            $hoy = now('America/Lima')->toDateString();
            $ficha = DB::table('servicio_publicaciones as p')->join('servicios_tecnologicos as s', 's.id', '=', 'p.servicio_tecnologico_id')
                ->where('s.codigo', $codigo)->where('s.estado', 'activo')->where('p.estado', 'publicada')
                ->where('p.vigente_desde', '<=', $hoy)->where('p.vigente_hasta', '>=', $hoy)
                ->whereNotNull('p.autorizado_at')->where('p.autorizado_at', '<=', now())
                ->whereIn('p.autorizado_por', $autores)
                ->first(['p.id', 'p.titulo', 'p.descripcion', 'p.titulo_tarjetas', 'p.imagen_principal_ruta', 'p.imagen_principal_alt']);
            if ($ficha === null || trim((string) $ficha->titulo) === '') {
                return null;
            }
            $filas = DB::table('servicio_publicacion_elementos')->where('servicio_publicacion_id', $ficha->id)->where('activo', true)
                ->orderBy('orden')->orderBy('id')->limit(self::MAX_TARJETAS)
                ->get(['titulo', 'descripcion', 'caracteristicas', 'icono', 'variante_imagen', 'imagen_ruta', 'imagen_alt']);
        } catch (PermissionDoesNotExist|RoleDoesNotExist|QueryException) {
            // Solo códigos seguros: nunca SQL, bindings ni contenido en logs.
            Log::warning('servicios_paginas_lectura_fallida');

            return null;
        }

        $tarjetas = [];
        foreach ($filas as $fila) {
            if (trim((string) $fila->titulo) === '') {
                continue;
            }
            $lista = json_decode((string) $fila->caracteristicas, true);
            $tarjetas[] = [
                'titulo' => (string) $fila->titulo,
                'descripcion' => (string) $fila->descripcion,
                'caracteristicas' => is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [],
                'icono' => $this->icono($fila->icono),
                'variante' => in_array($fila->variante_imagen, ['producto', 'escena'], true) ? (string) $fila->variante_imagen : null,
                'imagen_url' => ImagenesServicios::url($fila->imagen_ruta === null ? null : (string) $fila->imagen_ruta),
                'imagen_alt' => (string) ($fila->imagen_alt ?? ''),
            ];
        }
        $parrafos = preg_split('/\R{2,}/u', trim((string) $ficha->descripcion)) ?: [];

        return [
            'titulo' => (string) $ficha->titulo,
            'parrafos' => array_values(array_filter(array_map('trim', $parrafos), fn (string $texto): bool => $texto !== '')),
            'imagen_url' => ImagenesServicios::url($ficha->imagen_principal_ruta === null ? null : (string) $ficha->imagen_principal_ruta),
            'imagen_alt' => (string) ($ficha->imagen_principal_alt ?? ''),
            'titulo_tarjetas' => $ficha->titulo_tarjetas === null || trim((string) $ficha->titulo_tarjetas) === '' ? null : (string) $ficha->titulo_tarjetas,
            'tarjetas' => $tarjetas,
        ];
    }

    private function icono(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/\Afa[srb] fa-[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $valor) === 1 ? $valor : null;
    }
}
