<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\Support\LlamaRespaldoDelPanel;
use Tests\TestCase;

/**
 * Las exportaciones del panel escriben auditoría: solo existen por POST (con CSRF), nunca por un GET que un
 * prefetch, un enlace ajeno o un favorito puedan disparar. Los permisos y el límite de velocidad no cambian.
 */
class ExportacionesPostTest extends TestCase
{
    use LlamaRespaldoDelPanel;
    use LlamaMatriculasDelPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function staff(string $rol): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    public function test_el_respaldo_del_panel_solo_acepta_post_esta_limitado_y_exige_sesion(): void
    {
        $ruta = Route::getRoutes()->getByName('api.panel.compartido.respaldo.exportar');

        $this->assertNotNull($ruta);
        $this->assertSame(['POST'], $ruta->methods());
        $this->assertContains('throttle:admin-export', $ruta->gatherMiddleware());

        $this->actingAs($this->staff('admin'))->panelRespaldo('GET', 'exportar')->assertStatus(405);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'data_export_generated']);
        auth()->forgetGuards();
        $this->panelRespaldo('POST', 'exportar')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->panelRespaldo('POST', 'exportar')->assertForbidden();
    }

    /**
     * Matrículas se exporta por la API del panel: solo POST, mismo límite de velocidad, 401/403 según corresponda y
     * los filtros viajan en el cuerpo. El formulario con campos ocultos era HTML de Blade (ahora lo hace Next).
     */
    public function test_la_exportacion_de_matriculas_del_panel_solo_acepta_post_y_esta_limitada(): void
    {
        $ruta = Route::getRoutes()->getByName('api.panel.academico.matriculas.exportar');

        $this->assertNotNull($ruta);
        $this->assertSame(['POST'], $ruta->methods());
        $this->assertContains('throttle:admin-export', $ruta->gatherMiddleware());

        $this->actingAs($this->staff('admin'))->panelMatriculas('GET', 'exportar')->assertStatus(405);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'matriculas_csv_exported']);
    }

    public function test_matriz_de_acceso_de_la_exportacion_de_matriculas_del_panel(): void
    {
        $this->panelMatriculas('POST', 'exportar')->assertUnauthorized();

        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->panelMatriculas('POST', 'exportar')->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->panelMatriculas('POST', 'exportar')->assertForbidden();

        $respuesta = $this->actingAs($this->staff('admin'))->panelMatriculas('POST', 'exportar')->assertOk();
        $this->assertTrue($respuesta->baseResponse->headers->has('Content-Disposition'));
    }

    public function test_la_exportacion_de_matriculas_ignora_los_filtros_con_forma_de_array(): void
    {
        $this->actingAs($this->staff('admin'))
            ->panelMatriculas('POST', 'exportar', ['busqueda' => ['x'], 'estado' => ['pagada'], 'curso_id' => ['7']])
            ->assertOk();

        $registro = AuditLog::query()->where('action', 'matriculas_csv_exported')->sole();
        $this->assertStringNotContainsString('pagada', (string) json_encode($registro->changes));
    }

    // La exportación de auditoría se retiró de Blade el 14/11/2026: su ruta POST con límite de velocidad y sus permisos
    // están en Panel\AuditoriaPanelTest (solo POST, `auditoria.exportar`) y ExportacionesPanelRobustezTest (cupo compartido).
}
