<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Todo permiso del servidor debe poder mostrarse en español en la pantalla de roles: su módulo está en `MODULOS` y su acción en
 * `ACCIONES` o en `ESPECIALES` de `apps/web/src/lib/permisos-etiquetas.ts`. Si no, la pantalla enseñaría un texto calculado.
 */
class EtiquetasPermisosWebTest extends TestCase
{
    public function test_todo_permiso_del_seeder_tiene_modulo_y_accion_con_etiqueta(): void
    {
        $archivo = dirname(__DIR__, 4).'/apps/web/src/lib/permisos-etiquetas.ts';
        $this->assertFileExists($archivo);
        $fuente = (string) file_get_contents($archivo);

        $bloque = function (string $nombre) use ($fuente): string {
            $this->assertSame(1, preg_match('/const '.$nombre.'[^=]*= \{(.*?)\n\};/s', $fuente, $m), "No se encontró {$nombre}.");

            return $m[1];
        };
        $modulos = $bloque('MODULOS');
        $acciones = $bloque('ACCIONES');
        $especiales = $bloque('ESPECIALES');

        $faltan = [];
        foreach (RolesAndPermissionsSeeder::PERMISSIONS as $clave) {
            [$modulo] = explode('.', $clave, 2);
            $accion = substr($clave, strlen($modulo) + 1);
            $moduloOk = (bool) preg_match('/^\s*'.preg_quote($modulo, '/').':/m', $modulos);
            $accionOk = str_contains($especiales, "'{$clave}'")
                || (bool) preg_match('/^\s*(\''.preg_quote($accion, '/').'\'|'.preg_quote($accion, '/').'):/m', $acciones);
            if (! $moduloOk || ! $accionOk) {
                $faltan[] = $clave;
            }
        }

        $this->assertSame([], $faltan, 'Permisos sin etiqueta en apps/web/src/lib/permisos-etiquetas.ts');
    }
}
