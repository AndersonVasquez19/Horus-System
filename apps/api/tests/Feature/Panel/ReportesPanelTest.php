<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reporte de matrículas en /api/panel/v1/academico/reportes: misma consulta, lotes, exportaciones y auditoría que el
 * reporte Blade (ReportesMatriculas), con los permisos de siempre.
 */
final class ReportesPanelTest extends PanelApiTestCase
{
    use DatosAcademicos;

    private function matricula(string $dni, string $codigo, string $estado, string $monto): void
    {
        $estudiante = $this->estudiante($dni);
        $curso = $this->curso($codigo);
        Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-0'.substr($dni, -1),
            'estado' => $estado, 'monto_pagado' => $monto,
        ]);
    }

    public function test_el_listado_filtra_pagina_y_entrega_ingresos_solo_al_admin(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->matricula('11111111', 'CUR-1', 'pagada', '100');
        $this->matricula('22222222', 'CUR-2', 'pendiente', '50');
        $this->matricula('33333333', 'CUR-3', 'anulada', '70');

        $todo = $this->como($admin, 'GET', 'reportes')->assertOk();
        $todo->assertJsonPath('meta.total', 3)->assertJsonPath('meta.lote', 1)->assertJsonPath('meta.lotes', 1)
            ->assertJsonPath('resumen.matriculas', 3)->assertJsonPath('resumen.estudiantes', 3)->assertJsonPath('resumen.ingresos', '100.00')
            ->assertJsonPath('data.0.estado', 'anulada');

        $this->como($admin, 'GET', 'reportes?estado=pagada')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.filtros.estado', 'pagada')
            ->assertJsonPath('data.0.curso.codigo', 'CUR-1');
        $this->como($admin, 'GET', 'reportes?busqueda=CUR-2')->assertJsonPath('meta.total', 1);
        $this->como($admin, 'GET', 'reportes?estado=inventado')->assertUnprocessable();
        $this->como($admin, 'GET', 'reportes?lote=2')->assertNotFound();

        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $this->como($secretaria, 'GET', 'reportes')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonMissingPath('resumen.ingresos');
    }

    public function test_las_exportaciones_exigen_su_permiso_y_se_auditan_sin_texto_de_busqueda(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->matricula('11111111', 'CUR-1', 'pagada', '100');

        $lector = User::factory()->create();
        $lector->givePermissionTo(['panel.academico.acceder', 'reportes.ver']);
        $this->como($lector, 'GET', 'reportes')->assertOk();
        $this->como($lector, 'POST', 'reportes/exportar/excel')->assertForbidden();
        $this->como($lector, 'POST', 'reportes/exportar/pdf')->assertForbidden();
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['report_excel_exported', 'report_pdf_exported'])->count());

        $exportador = User::factory()->create();
        $exportador->givePermissionTo(['panel.academico.acceder', 'reportes.exportar.excel', 'reportes.exportar.pdf']);
        $this->como($exportador, 'GET', 'reportes')->assertForbidden();

        $excel = $this->como($exportador, 'POST', 'reportes/exportar/excel', ['busqueda' => 'Nombre', 'estado' => 'pagada'])->assertOk()->assertDownload();
        $this->verificarArchivo($excel, function (string $ruta): void {
            $hoja = IOFactory::load($ruta)->getActiveSheet();
            $this->assertSame(2, $hoja->getHighestDataRow());
        });

        $pdf = $this->como($exportador, 'POST', 'reportes/exportar/pdf')->assertOk()->assertDownload();
        $this->verificarArchivo($pdf, fn (string $ruta) => $this->assertStringStartsWith('%PDF-', (string) file_get_contents($ruta)));

        $this->assertSame(1, AuditLog::query()->where('action', 'report_excel_exported')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'report_pdf_exported')->count());
        $auditoria = (string) json_encode(AuditLog::query()->where('action', 'report_excel_exported')->sole()->changes);
        $this->assertStringContainsString('"con_busqueda":true', $auditoria);
        $this->assertStringNotContainsString('Nombre', $auditoria);
    }

    private function verificarArchivo(TestResponse $respuesta, callable $comprobar): void
    {
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        try {
            $comprobar($ruta);
        } finally {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
    }
}
