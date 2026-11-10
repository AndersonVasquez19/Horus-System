<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 1 de la auditoría del panel: la exportación de auditoría se transmite por bloques (sin cargar la
 * tabla completa) y las exportaciones del panel comparten un límite de velocidad por usuario.
 *
 * SQLite en memoria: no mide consumo real de memoria ni PostgreSQL.
 */
class ExportacionesPanelRobustezTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    public function test_la_exportacion_de_auditoria_es_json_valido_y_ordena_del_mas_reciente_al_mas_antiguo(): void
    {
        $admin = $this->adminUser();

        // Más de un bloque de 500 para ejercitar el cambio de página de lazyByIdDesc.
        $filas = [];
        for ($i = 1; $i <= 1100; $i++) {
            $filas[] = [
                'user_id' => null,
                'action' => 'prueba_masiva_'.$i,
                'model_type' => 'App\\Models\\Curso',
                'model_id' => $i,
                'ip_address' => '127.0.0.1',
                'changes' => null,
                'created_at' => now()->subMinutes(2000 - $i)->toDateTimeString(),
            ];
        }
        foreach (array_chunk($filas, 200) as $bloque) {
            DB::table('audit_logs')->insert($bloque);
        }

        $json = $this->actingAs($admin)->get(route('admin.auditoria.exportar'))->assertOk()->streamedContent();

        $datos = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($datos);

        $ids = array_column($datos, 'id');
        $this->assertCount(1100, $ids, 'El registro audit_exported propio no debe formar parte de la exportación.');
        $ordenado = $ids;
        rsort($ordenado);
        $this->assertSame($ordenado, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));

        $registro = AuditLog::where('action', 'audit_exported')->sole();
        $this->assertSame(1100, $registro->changes['records'] ?? null);
    }

    public function test_la_exportacion_de_auditoria_vacia_sigue_siendo_json_valido(): void
    {
        $json = $this->actingAs($this->adminUser())->get(route('admin.auditoria.exportar'))->assertOk()->streamedContent();

        $this->assertSame([], json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_un_identificador_no_numerico_responde_404_en_las_rutas_del_panel(): void
    {
        $admin = $this->adminUser();

        foreach (['estudiantes', 'cursos', 'matriculas', 'usuarios'] as $recurso) {
            $this->actingAs($admin)->get('/admin/'.$recurso.'/abc')->assertNotFound();
        }
        $this->actingAs($admin)->patch('/admin/estudiantes/abc/restaurar')->assertNotFound();
        $this->actingAs($admin)->patch('/admin/cursos/1;DROP/restaurar')->assertNotFound();
    }

    public function test_el_respaldo_no_deja_csv_temporales_y_sigue_incluyendo_cada_tabla(): void
    {
        $admin = $this->adminUser();
        $antes = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'horus_csv_*') ?: [];

        $respuesta = $this->actingAs($admin)->get(route('admin.respaldo.exportar'))->assertOk();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('audit_logs.csv', $nombres);
        $this->assertContains('manifest.json', $nombres);
        $this->assertSame($antes, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'horus_csv_*') ?: []);
    }

    public function test_las_exportaciones_del_panel_comparten_un_limite_de_velocidad_por_usuario(): void
    {
        $admin = $this->adminUser();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($admin)->get(route('admin.auditoria.exportar'))->assertOk()->streamedContent();
        }

        $this->actingAs($admin)->get(route('admin.auditoria.exportar'))->assertTooManyRequests();
        // Otra exportación del mismo usuario comparte el cupo.
        $this->actingAs($admin)->get(route('admin.matriculas.csv'))->assertTooManyRequests();
    }
}
