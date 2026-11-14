<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

/**
 * Las exportaciones del panel escriben auditoría: solo existen por POST (con CSRF), nunca por un GET que un
 * prefetch, un enlace ajeno o un favorito puedan disparar. Los permisos y el límite de velocidad no cambian.
 */
class ExportacionesPostTest extends TestCase
{
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

    /** @return array<string, array{0: string, 1: string}> */
    public static function exportaciones(): array
    {
        return [
            'auditoria.exportar' => ['admin.auditoria.exportar', 'auditoria/exportar'],
            'respaldo.exportar' => ['admin.respaldo.exportar', 'respaldo/exportar'],
        ];
    }

    #[DataProvider('exportaciones')]
    public function test_la_ruta_solo_acepta_post_y_pasa_por_el_grupo_web_con_csrf(string $nombre): void
    {
        $ruta = Route::getRoutes()->getByName($nombre);

        $this->assertNotNull($ruta);
        $this->assertSame(['POST'], $ruta->methods());
        $this->assertContains('web', $ruta->gatherMiddleware());
        $this->assertContains('throttle:admin-export', $ruta->gatherMiddleware());
    }

    #[DataProvider('exportaciones')]
    public function test_un_get_no_exporta_ni_deja_registro_de_auditoria(string $nombre, string $ruta): void
    {
        $this->actingAs($this->staff('admin'))
            ->get('/admin/'.$ruta)
            ->assertStatus(405);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'audit_exported']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'data_export_generated']);
        $this->assertSame(0, AuditLog::query()->where('action', 'like', '%export%')->count());
    }

    #[DataProvider('exportaciones')]
    public function test_matriz_de_acceso_de_la_exportacion(string $nombre): void
    {
        $this->post(route($nombre))->assertRedirect(route('login'));

        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->post(route($nombre))->assertForbidden();
        }

        $this->actingAs(User::factory()->create())->post(route($nombre))->assertForbidden();

        $respuesta = $this->actingAs($this->staff('admin'))->post(route($nombre))->assertOk();
        $this->assertTrue($respuesta->baseResponse->headers->has('Content-Disposition'));
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

    public function test_la_pantalla_de_auditoria_y_la_de_respaldo_exportan_con_un_formulario_post_con_token(): void
    {
        $admin = $this->staff('admin');

        $auditoria = (string) $this->actingAs($admin)->get(route('admin.auditoria.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#<form method="POST" action="'.preg_quote(e(route('admin.auditoria.exportar')), '#').'">\s*<input type="hidden" name="_token"#',
            $auditoria,
        );
        $this->assertStringNotContainsString('href="'.route('admin.auditoria.exportar'), $auditoria);

        $respaldo = (string) $this->get(route('admin.respaldo.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#<form method="POST" action="'.preg_quote(e(route('admin.respaldo.exportar')), '#').'">\s*<input type="hidden" name="_token"#',
            $respaldo,
        );
        $this->assertStringNotContainsString('href="'.route('admin.respaldo.exportar'), $respaldo);
    }

    public function test_la_pantalla_de_respaldo_no_ofrece_la_exportacion_a_quien_no_tiene_el_permiso(): void
    {
        $this->actingAs($this->staff('coordinador'))
            ->get(route('admin.respaldo.index'))
            ->assertOk()
            ->assertDontSee(route('admin.respaldo.exportar'), false);
    }
}
