<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Filtros de auditoría por rango de fechas (días completos en hora de Lima) en el listado y en la
 * exportación, que comparte los mismos filtros. SQLite en memoria.
 */
class AuditoriaFiltrosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    /** `created_at` se guarda en UTC. */
    private function registro(string $accion, string $utc, ?User $user = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $user?->id,
            'action' => $accion,
            'model_type' => 'App\\Models\\Curso',
            'model_id' => 1,
            'created_at' => $utc,
        ]);
    }

    private function sembrar(): void
    {
        $this->registro('antes_del_rango', '2026-11-03 04:59:59');   // 2026-11-02 23:59:59 en Lima
        $this->registro('inicio_del_rango', '2026-11-03 05:00:00');  // 2026-11-03 00:00:00 en Lima
        $this->registro('noche_en_lima', '2026-11-05 04:30:00');     // 2026-11-04 23:30:00 en Lima
        $this->registro('despues_del_rango', '2026-11-05 05:00:00'); // 2026-11-05 00:00:00 en Lima
    }

    /** @return list<string> */
    private function accionesListadas(array $query): array
    {
        $respuesta = $this->actingAs($this->adminUser())->get(route('admin.auditoria.index', $query))->assertOk();

        return $respuesta->viewData('registros')->getCollection()->pluck('action')->sort()->values()->all();
    }

    public function test_el_rango_usa_dias_completos_de_lima(): void
    {
        $this->sembrar();

        $this->assertSame(
            ['inicio_del_rango', 'noche_en_lima'],
            $this->accionesListadas(['desde' => '2026-11-03', 'hasta' => '2026-11-04']),
        );
        $this->assertSame(['despues_del_rango'], $this->accionesListadas(['desde' => '2026-11-05']));
        $this->assertSame(['antes_del_rango'], $this->accionesListadas(['hasta' => '2026-11-02']));
    }

    public function test_fechas_invalidas_se_ignoran_como_filtro_vacio(): void
    {
        $this->sembrar();

        foreach (['2026-02-30', '03/11/2026', '2026-11-3', 'x', ''] as $valor) {
            $this->assertCount(4, $this->accionesListadas(['desde' => $valor]), "desde={$valor}");
        }
        // Un parámetro con forma de array no provoca error 500.
        $this->assertCount(4, $this->accionesListadas(['hasta' => ['2026-11-03']]));
    }

    public function test_la_pantalla_muestra_los_filtros_de_fecha_la_hora_de_lima_y_exporta_con_los_filtros(): void
    {
        $this->registro('noche_en_lima', '2026-11-05 04:30:00');

        $html = (string) $this->actingAs($this->adminUser())
            ->get(route('admin.auditoria.index', ['desde' => '2026-11-04', 'hasta' => '2026-11-04', 'accion' => 'noche']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="desde" type="date" value="2026-11-04"', $html);
        $this->assertStringContainsString('name="hasta" type="date" value="2026-11-04"', $html);
        // 04:30 UTC del día 5 = 23:30 del día 4 en Lima.
        $this->assertStringContainsString('04/11/2026 23:30:00', $html);
        // La exportación va por POST: formulario con CSRF y los filtros vigentes como campos ocultos.
        $this->assertStringContainsString('action="'.e(route('admin.auditoria.exportar')).'"', $html);
        foreach (['accion' => 'noche', 'desde' => '2026-11-04', 'hasta' => '2026-11-04'] as $nombre => $valor) {
            $this->assertStringContainsString('<input type="hidden" name="'.$nombre.'" value="'.$valor.'">', $html);
        }
        $this->assertStringContainsString('Exportar JSON (filtros actuales)', $html);
    }

    public function test_la_exportacion_aplica_los_mismos_filtros_y_registra_cuales_se_usaron(): void
    {
        $this->sembrar();
        $admin = $this->adminUser();

        $json = $this->actingAs($admin)
            ->post(route('admin.auditoria.exportar', ['desde' => '2026-11-03', 'hasta' => '2026-11-04', 'usuario' => 'ana@example.test']))
            ->assertOk()
            ->streamedContent();

        // Ningún registro sembrado tiene usuario: el filtro por usuario los excluye a todos.
        $this->assertSame([], json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        $json = $this->actingAs($admin)
            ->post(route('admin.auditoria.exportar', ['desde' => '2026-11-03', 'hasta' => '2026-11-04']))
            ->assertOk()
            ->streamedContent();
        $acciones = array_column(json_decode($json, true, flags: JSON_THROW_ON_ERROR), 'accion');
        sort($acciones);
        $this->assertSame(['inicio_del_rango', 'noche_en_lima'], $acciones);

        $registros = AuditLog::where('action', 'audit_exported')->orderBy('id')->get();
        $this->assertCount(2, $registros);
        $this->assertSame(0, $registros[0]->changes['records']);
        $this->assertTrue($registros[0]->changes['filtros']['usuario']);
        $this->assertStringNotContainsString('ana@example.test', (string) json_encode($registros[0]->getAttributes()));
        $this->assertSame(2, $registros[1]->changes['records']);
        $this->assertSame('2026-11-03', $registros[1]->changes['filtros']['desde']);
        $this->assertFalse($registros[1]->changes['filtros']['usuario']);
    }
}
