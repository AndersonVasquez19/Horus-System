<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_pdf_batches_include_all_55_matches_without_overlap_and_show_their_scope(): void
    {
        $curso = $this->course();
        for ($i = 1; $i <= 55; $i++) {
            $this->enrollment($curso, $i);
        }
        $this->actingAs($this->adminUser());
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
            $panel = $this->get(route('admin.reportes.index', ['lote' => $lote]))->assertOk();
            $panel->assertViewHas('matriculas', fn ($rows) => $rows->total() === 55 && $rows->count() === $cantidad);
            $panelIds = $panel->viewData('matriculas')->pluck('id')->all();
            $response = $this->post(route('admin.reportes.exportar.pdf'), ['lote' => $lote])->assertOk()->assertDownload();
            $path = $response->baseResponse->getFile()->getPathname();
            try {
                $this->assertStringStartsWith('%PDF-', file_get_contents($path));
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
        $this->actingAs($this->adminUser());
        $panel = $this->get(route('admin.reportes.index', $filtros))->assertOk();
        $panel->assertViewHas('resumen', fn ($r) => $r['matriculas'] === 1 && (float) $r['ingresos'] === 150.0)
            ->assertViewHas('matriculas', fn ($rows) => $rows->pluck('id')->all() === [$match->id])
            ->assertSee('name="estado" value="pagada"', false)
            ->assertSee('name="busqueda" value="ANA"', false);

        $renderer = app('dompdf.wrapper');
        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use ($renderer, $match, $filtros) {
            $this->assertSame([$match->id], $data['matriculas']->pluck('id')->all());
            $this->assertSame($filtros, $data['filtros']);
            $this->assertStringContainsString('ANA', view($view, $data)->render());

            return $renderer->loadView($view, $data);
        });
        $pdf = $this->post(route('admin.reportes.exportar.pdf'), $filtros)->assertOk()->assertDownload();
        unlink($pdf->baseResponse->getFile()->getPathname());

        $excel = $this->post(route('admin.reportes.exportar.excel'), $filtros)->assertOk()->assertDownload();
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
        $this->actingAs($this->adminUser());
        $this->get(route('admin.reportes.index'))->assertOk()
            ->assertViewHas('matriculas', fn ($rows) => $rows->total() === 0);
        $this->get(route('admin.reportes.index', ['eliminados' => '1', 'busqueda' => 'ANA', 'curso_id' => $curso->id]))
            ->assertOk()->assertSee('Ana')->assertSee('Curso ficticio')
            ->assertViewHas('matriculas', fn ($rows) => $rows->pluck('id')->all() === [$row->id]);
    }

    public function test_pagination_links_preserve_filters_and_filtering_resets_the_batch(): void
    {
        $curso = $this->course();
        for ($i = 1; $i <= 51; $i++) {
            $this->enrollment($curso, $i);
        }
        $filtros = ['busqueda' => 'Ana', 'curso_id' => (string) $curso->id, 'estado' => 'pendiente'];
        $panel = $this->actingAs($this->adminUser())->get(route('admin.reportes.index', $filtros))->assertOk();
        parse_str(parse_url($panel->viewData('matriculas')->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame($filtros + ['lote' => '2'], $query);
        $panel->assertSee('Máximo 50 registros por PDF');
        $this->get(route('admin.reportes.index', $filtros + ['lote' => '2']))->assertOk()
            ->assertSee('name="lote" value="2"', false);
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

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_by_panel_and_both_export_endpoints(array $filtros): void
    {
        $this->actingAs($this->adminUser());
        $this->getJson(route('admin.reportes.index', $filtros))->assertUnprocessable();
        $this->postJson(route('admin.reportes.exportar.pdf'), $filtros)->assertUnprocessable();
        $this->postJson(route('admin.reportes.exportar.excel'), $filtros)->assertUnprocessable();
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

    #[DataProvider('exportPermissions')]
    public function test_export_buttons_and_endpoints_require_their_own_permission(array $permissions): void
    {
        $this->adminUser();
        $user = User::factory()->create();
        $user->assignRole('secretaria');
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
        $panel = $this->get(route('admin.reportes.index'))->assertOk();
        foreach (['pdf', 'excel'] as $format) {
            $allowed = in_array('reportes.exportar.'.$format, $permissions, true);
            $action = 'action="'.route('admin.reportes.exportar.'.$format).'"';
            $allowed ? $panel->assertSee($action, false) : $panel->assertDontSee($action, false);
            $export = $this->post(route('admin.reportes.exportar.'.$format));
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
        $this->actingAs($this->adminUser());
        $this->get(route('admin.reportes.index', ['lote' => 2]))->assertNotFound();
        $this->post(route('admin.reportes.exportar.pdf'), ['lote' => 2])->assertNotFound();
    }

    public function test_student_summary_distinguishes_pending_paid_and_cancelled_and_excludes_deleted(): void
    {
        $pendiente = $this->enrollment($this->course('PEN'), 1);
        foreach (['pagada', 'anulada'] as $index => $estado) {
            Matricula::create([
                'estudiante_id' => $pendiente->estudiante_id, 'curso_id' => $this->course('CUR'.$index)->id,
                'fecha_matricula' => '2026-10-28', 'estado' => $estado,
            ]);
        }
        $deleted = Matricula::create([
            'estudiante_id' => $pendiente->estudiante_id, 'curso_id' => $this->course('DEL')->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente',
        ]);
        $deleted->delete();
        $this->actingAs($this->adminUser())->get(route('admin.estudiantes.show', $pendiente->estudiante_id))
            ->assertOk()->assertSee('3 registros · 2 activas')
            ->assertSee('Pendientes: 1')->assertSee('Pagadas: 1')->assertSee('Anuladas: 1');
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
