<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accesibilidad del layout del panel: enlace para saltar al contenido, menú lateral controlable, un solo <h1>
 * por página, confirmación con <dialog> nativo y avisos de error sin cierre automático. Comprueba el marcado
 * que entrega el servidor; el comportamiento en el navegador lo cubren tests/frontend y los smokes.
 */
class AccesibilidadPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->admin = $this->adminUser();
    }

    public function test_el_layout_ofrece_saltar_al_contenido_y_un_menu_lateral_controlable(): void
    {
        $html = (string) $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="#admin-main-content"[^>]*data-saltar-contenido/', $html);
        $this->assertLessThan(strpos($html, 'id="admin-sidebar"'), strpos($html, 'data-saltar-contenido'));
        $this->assertStringContainsString('<main id="admin-main-content" tabindex="-1"', $html);
        $this->assertStringContainsString('id="admin-sidebar"', $html);
        $this->assertStringContainsString(':inert="menuInerte"', $html);
        $this->assertStringContainsString('aria-controls="admin-sidebar"', $html);
        $this->assertStringContainsString('x-data="adminShell"', $html);
    }

    public function test_cada_pagina_de_formulario_tiene_un_solo_h1_y_el_dialogo_se_rotula_con_su_titulo(): void
    {
        $curso = Curso::create([
            'codigo' => 'COD-A11Y-1', 'nombre' => 'Curso de prueba', 'descripcion' => 'x', 'categoria' => 'tecnologia',
            'duracion_horas' => 10, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'x',
            'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
        ]);
        $estudiante = Estudiante::create(['dni' => '70000555', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'estado' => 'activo']);
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0,
        ]);

        $rutas = [
            route('admin.estudiantes.create'), route('admin.estudiantes.edit', $estudiante),
            route('admin.cursos.create'), route('admin.cursos.edit', $curso), route('admin.cursos.publicacion.edit', $curso),
            route('admin.matriculas.create'), route('admin.matriculas.edit', $matricula),
            route('admin.usuarios.create'), route('admin.usuarios.edit', $this->admin),
            route('admin.configuracion.index'),
        ];

        foreach ($rutas as $ruta) {
            $html = (string) $this->actingAs($this->admin)->get($ruta)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "Un solo <h1> en {$ruta}");
            $this->assertSame(1, substr_count($html, 'id="admin-form-title"'), "Título del formulario en {$ruta}");
            $this->assertStringContainsString('aria-labelledby="admin-form-title"', $html, $ruta);
        }
    }

    public function test_un_listado_tiene_un_solo_h1(): void
    {
        $html = (string) $this->actingAs($this->admin)->get(route('admin.cursos.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_la_confirmacion_es_un_dialogo_nativo_con_titulo_y_descripcion(): void
    {
        $html = (string) $this->actingAs($this->admin)->get(route('admin.cursos.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<dialog\s+x-data="confirmModal"/', $html);
        $this->assertStringContainsString('aria-labelledby="confirm-modal-title"', $html);
        $this->assertStringContainsString('aria-describedby="confirm-modal-message"', $html);
        $this->assertStringContainsString('id="confirm-modal-message"', $html);
        $this->assertStringNotContainsString('role="dialog"', $html);
    }

    public function test_los_avisos_de_error_y_exito_llevan_su_tipo_y_ninguno_programa_un_cierre_en_linea(): void
    {
        $error = (string) $this->actingAs($this->admin)->withSession(['error' => 'No se pudo <guardar>.'])
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('role="alert"', $error);
        $this->assertStringContainsString('data-tipo="error"', $error);
        $this->assertStringContainsString('data-mensaje="No se pudo &lt;guardar&gt;."', $error);
        $this->assertStringNotContainsString('No se pudo <guardar>.', $error);
        $this->assertStringNotContainsString('setTimeout', $error);

        $exito = (string) $this->actingAs($this->admin)->withSession(['success' => 'Guardado.'])
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('role="status"', $exito);
        $this->assertStringContainsString('data-tipo="success"', $exito);
        $this->assertStringNotContainsString('setTimeout', $exito);
    }
}
