<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use Illuminate\Support\Str;

/**
 * Los mensajes de validación que recibe Next.js salen en español y nombran los campos con palabras, no con
 * identificadores de base de datos (`contacto_nombre`, `vigente_desde`…). Las reglas no cambian.
 */
final class MensajesEspanolPanelTest extends PanelApiTestCase
{
    public function test_los_errores_de_validacion_del_panel_no_exponen_nombres_internos_de_campo(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $rutas = [
            ['POST', 'academico/estudiantes'], ['POST', 'academico/cursos'], ['POST', 'academico/matriculas'],
            ['PUT', 'academico/cursos/1/publicacion'],
        ];

        foreach ($rutas as [$metodo, $ruta]) {
            $respuesta = $this->actingAs($admin)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->json($metodo, '/api/panel/v1/'.$ruta, []);
            $respuesta->assertUnprocessable();

            foreach ($respuesta->json('errors') as $campo => $mensajes) {
                foreach ($mensajes as $mensaje) {
                    $this->assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $mensaje, "{$ruta} · {$campo}: {$mensaje}");
                    $this->assertMatchesRegularExpression('/obligatori|Debes|debe/i', $mensaje, "{$ruta} · {$campo}: {$mensaje}");
                }
            }
        }
    }
}
