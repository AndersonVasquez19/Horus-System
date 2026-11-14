<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Consistencia del reporte de matrículas en /api/panel/v1/academico/reportes (listado, PDF y Excel). Sustituye a
 * `AdminReportConsistencyTest`, que ejercitaba la ruta Blade retirada el 14/11/2026: las reglas son las mismas
 * (ReportesMatriculas) y las afirmaciones sobre HTML pasaron a afirmaciones sobre el JSON del panel.
 *
 * DatabaseMigrations (no RefreshDatabase): varias pruebas descargan el Excel, que debe abrir su propio snapshot desde
 * el nivel 0; la transacción READ COMMITTED de RefreshDatabase la haría fallar en PostgreSQL. Esta clase es la que
 * cubre las exportaciones del panel en PostgreSQL (ver ReportesPanelTest).
 */
class ReportesConsistenciaPanelTest extends TestCase
{
    use DatabaseMigrations;

    private const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Storage::fake('local');
        config(['horus-web.service_token' => self::TOKEN]);
    }

    /** @param array<string, mixed> $datos */
    private function panel(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/academico/'.$ruta, $datos);
    }

    /** @param array<string, mixed> $filtros */
    private function listado(User $user, array $filtros = []): TestResponse
    {
        return $this->panel($user, 'GET', 'reportes'.($filtros === [] ? '' : '?'.http_build_query($filtros)));
    }

    /** @return list<int> */
    private function ids(TestResponse $respuesta): array
    {
        return array_map('intval', array_column((array) $respuesta->json('data'), 'id'));
    }

    public function test_pdf_batches_include_all_55_matches_without_overlap_and_show_their_scope(): void
    {
        $curso = $this->course();
        for ($i = 1; $i <= 55; $i++) {
            $this->enrollment($curso, $i);
        }
        $admin = $this->adminUser();
        $ids = [];
        $renderers = [1 => app('dompdf.wrapper'), 2 => app('dompdf.wrapper')];
        $panelIds = [];
        Pdf::shouldReceive('loadView')->twice()->andReturnUsing(function ($view, $data) use ($renderers, &$panelIds, &$ids) {
            $rows = $data['matriculas'];
            $lote = $rows->currentPage();
            $cantidad = $lote === 1 ? 50 : 5;
            $this->assertSame(55, $rows->total());
            $this->assertSame($cantidad, $rows->count());
            $this->assertSame($panelIds, $rows->pluck('id')->all());
            $ids = array_merge($ids, $rows->pluck('id')->all());
            $html = view($view, $data)->render();
            $this->assertStringContainsString('Total de coincidencias: 55', $html);
            $this->assertStringContainsString('Registros incluidos: '.$cantidad, $html);
            $this->assertStringContainsString('Lote '.$lote.' de 2', $html);
            $this->assertStringContainsString($lote === 1 ? 'Rango 1–50' : 'Rango 51–55', $html);

            return $renderers[$lote]->loadView($view, $data);
        });

        foreach ([1 => 50, 2 => 5] as $lote => $cantidad) {
            $panel = $this->listado($admin, ['lote' => $lote])->assertOk()
                ->assertJsonPath('meta.total', 55)->assertJsonPath('meta.lote', $lote)->assertJsonPath('meta.lotes', 2)
                ->assertJsonPath('meta.tamano_lote', 50);
            $this->assertCount($cantidad, (array) $panel->json('data'));
            $panelIds = $this->ids($panel);
            $response = $this->panel($admin, 'POST', 'reportes/exportar/pdf', ['lote' => $lote])->assertOk()->assertDownload();
            $path = $response->baseResponse->getFile()->getPathname();
            try {
                $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path));
            } finally {
                unlink($path);
            }
        }
        $this->assertSame(range(55, 1), $ids);
        $this->assertCount(55, array_unique($ids));
        $this->assertSame(2, AuditLog::where('action', 'report_pdf_exported')->count());
    }

    public function test_combined_filters_and_totals_agree_between_panel_pdf_and_excel(): void
    {
        $curso = $this->course();
        $otra = $this->course('OTRO');
        $match = $this->enrollment($curso, 1, 'pagada');
        $this->enrollment($curso, 2, 'pendiente');
        $this->enrollment($otra, 3, 'pagada');
        $this->enrollment($curso, 4, 'pagada')->delete();
        $filtros = ['busqueda' => 'ANA', 'curso_id' => (string) $curso->id, 'estado' => 'pagada', 'eliminados' => '0'];
        $admin = $this->adminUser();

        $panel = $this->listado($admin, $filtros)->assertOk()
            ->assertJsonPath('resumen.matriculas', 1)->assertJsonPath('resumen.ingresos', '150.00')
            ->assertJsonPath('meta.filtros.estado', 'pagada')->assertJsonPath('meta.filtros.busqueda', 'ANA');
        $this->assertSame([$match->id], $this->ids($panel));

        $renderer = app('dompdf.wrapper');
        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use ($renderer, $match, $filtros) {
            $this->assertSame([$match->id], $data['matriculas']->pluck('id')->all());
            $this->assertSame($filtros, $data['filtros']);
            $this->assertStringContainsString('ANA', view($view, $data)->render());

            return $renderer->loadView($view, $data);
        });
        $pdf = $this->panel($admin, 'POST', 'reportes/exportar/pdf', $filtros)->assertOk()->assertDownload();
        unlink($pdf->baseResponse->getFile()->getPathname());

        $excel = $this->panel($admin, 'POST', 'reportes/exportar/excel', $filtros)->assertOk()->assertDownload();
        $path = $excel->baseResponse->getFile()->getPathname();
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame(2, $sheet->getHighestDataRow());
            $this->assertSame($match->id, $sheet->getCell('A2')->getValue());
        } finally {
            unlink($path);
        }
    }

    public function test_deleted_enrollments_are_reported_only_when_selected_even_if_their_relations_are_deleted(): void
    {
        $curso = $this->course();
        $row = $this->enrollment($curso, 1, 'pagada');
        $row->delete();
        $curso->delete();
        $row->estudiante->delete();
        $admin = $this->adminUser();

        $this->listado($admin)->assertOk()->assertJsonPath('meta.total', 0);
        $respuesta = $this->listado($admin, ['eliminados' => '1', 'busqueda' => 'ANA', 'curso_id' => $curso->id])->assertOk()
            ->assertJsonPath('data.0.curso.nombre', 'Curso ficticio');
        $this->assertSame([$row->id], $this->ids($respuesta));
        $this->assertStringContainsString('Ana', (string) $respuesta->json('data.0.estudiante'));
    }

    public function test_batch_metadata_preserves_filters_and_the_next_batch_continues_the_listing(): void
    {
        $curso = $this->course();
        for ($i = 1; $i <= 51; $i++) {
            $this->enrollment($curso, $i);
        }
        $filtros = ['busqueda' => 'Ana', 'curso_id' => (string) $curso->id, 'estado' => 'pendiente'];
        $admin = $this->adminUser();

        $primero = $this->listado($admin, $filtros)->assertOk()
            ->assertJsonPath('meta.lotes', 2)->assertJsonPath('meta.tamano_lote', 50)->assertJsonPath('meta.filtros.estado', 'pendiente');
        $segundo = $this->listado($admin, $filtros + ['lote' => '2'])->assertOk()
            ->assertJsonPath('meta.lote', 2)->assertJsonPath('meta.filtros.busqueda', 'Ana');

        $this->assertCount(50, (array) $primero->json('data'));
        $this->assertCount(1, (array) $segundo->json('data'));
        $this->assertCount(51, array_unique(array_merge($this->ids($primero), $this->ids($segundo))), 'Los lotes no se solapan.');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFilters(): iterable
    {
        yield 'search array' => [['busqueda' => ['Ana']]];
        yield 'unknown state' => [['estado' => 'activo']];
        yield 'course array' => [['curso_id' => ['1']]];
        yield 'unknown course' => [['curso_id' => '999999']];
        yield 'invalid batch' => [['lote' => '0']];
        yield 'deleted array' => [['eliminados' => ['1']]];
    }

    /** @param array<string, mixed> $filtros */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_by_panel_and_both_export_endpoints(array $filtros): void
    {
        $admin = $this->adminUser();
        $this->listado($admin, $filtros)->assertUnprocessable();
        $this->panel($admin, 'POST', 'reportes/exportar/pdf', $filtros)->assertUnprocessable();
        $this->panel($admin, 'POST', 'reportes/exportar/excel', $filtros)->assertUnprocessable();
        $this->assertSame(0, AuditLog::whereIn('action', ['report_pdf_exported', 'report_excel_exported'])->count());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function exportPermissions(): iterable
    {
        yield 'neither' => [[]];
        yield 'pdf only' => [['reportes.exportar.pdf']];
        yield 'excel only' => [['reportes.exportar.excel']];
        yield 'both' => [['reportes.exportar.pdf', 'reportes.exportar.excel']];
    }

    /** @param list<string> $permissions */
    #[DataProvider('exportPermissions')]
    public function test_export_endpoints_require_their_own_permission(array $permissions): void
    {
        $this->adminUser();
        $user = User::factory()->create();
        $user->assignRole('secretaria');
        $user->givePermissionTo($permissions);
        $this->listado($user)->assertOk();
        foreach (['pdf', 'excel'] as $format) {
            $allowed = in_array('reportes.exportar.'.$format, $permissions, true);
            $export = $this->panel($user, 'POST', 'reportes/exportar/'.$format);
            if ($allowed) {
                $export->assertOk()->assertDownload();
                unlink($export->baseResponse->getFile()->getPathname());
            } else {
                $export->assertForbidden();
            }
        }
    }

    public function test_nonexistent_batch_is_rejected_instead_of_producing_an_empty_partial_report(): void
    {
        $admin = $this->adminUser();
        $this->listado($admin, ['lote' => 2])->assertNotFound();
        $this->panel($admin, 'POST', 'reportes/exportar/pdf', ['lote' => 2])->assertNotFound();
    }

    private function course(string $codigo = 'CUR-REP'): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => 'Curso ficticio', 'descripcion' => 'Datos de prueba',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 150,
            'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 100, 'cupos_disponibles' => 100, 'estado' => 'activo',
        ]);
    }

    private function enrollment(Curso $curso, int $number, string $estado = 'pendiente'): Matricula
    {
        $estudiante = Estudiante::create([
            'dni' => (string) (71000000 + $number), 'nombres' => 'Ana', 'apellidos' => 'Prueba '.$number, 'estado' => 'activo',
        ]);

        return Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => $estado, 'monto_pagado' => 150,
        ]);
    }
}
