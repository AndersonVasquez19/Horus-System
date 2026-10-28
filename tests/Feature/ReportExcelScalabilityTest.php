<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Settings;
use RuntimeException;
use Tests\TestCase;

class ReportExcelScalabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_batches_preserve_every_row_and_order_without_n_plus_one(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminUser());
        $this->seedRows(1203);
        $expected = Matricula::orderByDesc('fecha_matricula')->orderByDesc('id')->pluck('id')->all();
        $queries = 0;
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            if (str_starts_with($event->sql, 'select') && preg_match('/from "(matriculas|estudiantes|cursos)"/', $event->sql)) {
                $queries++;
            }
        });
        $cache = Settings::getCache();
        $response = $this->post(route('admin.reportes.exportar.excel'))->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $this->assertSame($cache, Settings::getCache());
            $this->assertLessThanOrEqual(12, $queries);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame('Matrículas', $sheet->getTitle());
            $this->assertSame(1204, $sheet->getHighestDataRow());
            $actual = array_column($sheet->rangeToArray('A2:A1204', null, false, false), 0);
            $this->assertSame($expected, $actual);
            $this->assertCount(1203, array_unique($actual));
            $this->assertSame(1, AuditLog::where('action', 'report_excel_exported')->count());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_query_failure_restores_cache_and_returns_no_download_or_success_audit(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminUser());
        $this->seedRows(501);
        $cache = Settings::getCache();
        $batches = 0;
        DB::listen(function (QueryExecuted $event) use (&$batches): void {
            if (str_starts_with($event->sql, 'select') && str_contains($event->sql, 'from "matriculas"') && ++$batches === 2) {
                throw new RuntimeException('Injected batch failure');
            }
        });

        $this->postJson(route('admin.reportes.exportar.excel'))->assertStatus(500)->assertHeaderMissing('Content-Disposition');
        $this->assertSame($cache, Settings::getCache());
        $this->assertSame([], Storage::disk('local')->allFiles('exports'));
        $this->assertSame(0, AuditLog::where('action', 'report_excel_exported')->count());
    }

    public function test_writer_failure_does_not_return_a_download_or_success_audit(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminUser());
        $this->seedRows(1);
        $disk = Storage::disk('local');
        $disk->makeDirectory('exports');
        // Intentar escribir el XLSX sobre un directorio produce un fallo real de fopen.
        $target = $disk->path('exports');
        $failingDisk = Mockery::mock(FilesystemAdapter::class);
        $failingDisk->shouldReceive('makeDirectory')->with('exports')->once()->andReturn(true);
        $failingDisk->shouldReceive('path')->once()->andReturn($target);
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);
        $cache = Settings::getCache();

        $this->postJson(route('admin.reportes.exportar.excel'))->assertStatus(500)->assertHeaderMissing('Content-Disposition');
        $this->assertSame($cache, Settings::getCache());
        $this->assertSame([], $disk->allFiles('exports'));
        $this->assertSame(0, AuditLog::where('action', 'report_excel_exported')->count());
    }

    private function seedRows(int $count): void
    {
        $course = Curso::create([
            'codigo' => 'S03', 'nombre' => 'Curso ficticio', 'descripcion' => 'Datos sintéticos', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 150, 'modalidad' => 'virtual', 'horario' => 'Lunes 18:00',
            'cupos_maximos' => $count, 'cupos_disponibles' => 0, 'estado' => 'activo',
        ]);
        for ($start = 1; $start <= $count; $start += 250) {
            $students = $enrollments = [];
            for ($i = $start; $i < min($start + 250, $count + 1); $i++) {
                $students[] = ['id' => $i, 'dni' => sprintf('%08d', $i), 'nombres' => 'Estudiante '.$i, 'apellidos' => 'Ficticio', 'estado' => 'activo'];
                $enrollments[] = ['estudiante_id' => $i, 'curso_id' => $course->id, 'fecha_matricula' => '2026-10-'.sprintf('%02d', ($i % 28) + 1), 'estado' => 'pagada', 'monto_pagado' => 150.5];
            }
            Estudiante::insert($students);
            Matricula::insert($enrollments);
        }
    }
}
