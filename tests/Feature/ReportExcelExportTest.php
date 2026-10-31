<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\SafeSpreadsheet;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El Excel de matrículas debe guardar los textos de usuario como cadenas, nunca como fórmulas.
 * Todos los datos son ficticios.
 *
 * DatabaseMigrations y no RefreshDatabase: la exportación debe recibir la petición sin transacción
 * abierta para crear su propio snapshot (en PostgreSQL, REPEATABLE READ, READ ONLY). La transacción
 * READ COMMITTED de RefreshDatabase la haría fallar por su salvaguarda, que no se relaja.
 */
class ReportExcelExportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();

        Storage::fake('local');
    }

    public function test_excel_export_runs_in_its_own_transaction_and_releases_it(): void
    {
        // Independiente del driver: nivel de transacción antes, durante y después. No mide aislamiento.
        $observed = $this->exportObservingFirstEnrollmentRead('10000005');

        $this->assertSame(1, $observed['level'], 'La exportación debe abrir su propia transacción desde el nivel 0.');
        $this->assertSame(0, DB::connection()->transactionLevel(), 'La transacción debe cerrarse al terminar.');
    }

    /**
     * Evidencia exclusiva de PostgreSQL (filtro independiente: ver docs/postgresql-testing-pg-test-04.md §12).
     * En SQLite se omite a propósito: SQLite no tiene REPEATABLE READ ni READ ONLY por transacción.
     */
    public function test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo el perfil PostgreSQL aislado demuestra REPEATABLE READ, READ ONLY; SQLite no es evidencia.');
        }

        $observed = $this->exportObservingFirstEnrollmentRead('10000003');

        $this->assertContains('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY', $observed['statements']);
        $this->assertSame(1, $observed['level'], 'La exportación debe abrir su propia transacción desde el nivel 0.');
        $this->assertSame('repeatable read', $observed['isolation']);
        $this->assertSame('on', $observed['read_only']);

        // Cierre: sin transacción abierta y la sesión vuelve a sus valores por defecto (SET TRANSACTION
        // solo dura hasta el COMMIT/ROLLBACK de la exportación).
        $this->assertSame(0, DB::connection()->transactionLevel(), 'El snapshot debe liberarse al terminar.');
        $after = (array) DB::selectOne(<<<'SQL'
            SELECT current_setting('transaction_isolation') AS isolation,
                current_setting('default_transaction_isolation') AS default_isolation,
                current_setting('transaction_read_only') AS read_only,
                current_setting('default_transaction_read_only') AS default_read_only
            SQL);
        $this->assertSame($after['default_isolation'], $after['isolation']);
        $this->assertNotSame('repeatable read', $after['isolation']);
        $this->assertSame($after['default_read_only'], $after['read_only']);
    }

    /**
     * Ejecuta la exportación Excel y devuelve lo observado en la primera lectura de matrículas.
     *
     * @return array{level: int, statements: list<string>, isolation?: string, read_only?: string}
     */
    private function exportObservingFirstEnrollmentRead(string $dni): array
    {
        $this->makeEnrollment($this->makeStudent($dni, 'Ana', 'Ficticia'), $this->makeCourse('Curso ficticio'));
        $admin = $this->adminUser();
        $this->assertSame(0, DB::connection()->transactionLevel(), 'La petición no debe llegar dentro de una transacción de prueba.');

        $observed = null;
        $statements = [];
        DB::listen(function (QueryExecuted $event) use (&$observed, &$statements): void {
            $statements[] = $event->sql;
            if ($observed !== null || ! str_starts_with($event->sql, 'select') || ! str_contains($event->sql, 'from "matriculas"')) {
                return;
            }
            // Primer lote leído por la exportación: se asigna antes de consultar para no reentrar.
            $observed = ['level' => $event->connection->transactionLevel()];
            if ($event->connection->getDriverName() === 'pgsql') {
                $observed += (array) $event->connection->selectOne(
                    "SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only"
                );
            }
        });

        $response = $this->actingAs($admin)->post(route('admin.reportes.exportar.excel'))->assertOk()->assertDownload();
        unlink($response->baseResponse->getFile()->getPathname());

        $this->assertNotNull($observed, 'La exportación no leyó matrículas.');

        return $observed + ['statements' => $statements];
    }

    public function test_coordinador_downloads_the_excel_report_with_the_seeded_permissions(): void
    {
        $this->makeEnrollment($this->makeStudent('10000004', 'Luis', 'Ficticio'), $this->makeCourse('Curso ficticio'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $coordinador = User::factory()->create();
        $coordinador->assignRole('coordinador');

        $response = $this->actingAs($coordinador)->post(route('admin.reportes.exportar.excel'))->assertOk()->assertDownload();
        unlink($response->baseResponse->getFile()->getPathname());

        $this->assertSame(1, AuditLog::where('action', 'report_excel_exported')->count());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulaLikeNames(): iterable
    {
        yield 'igual' => ['=HYPERLINK("http://example.invalid","x")', 'Prueba'];
        yield 'suma' => ['+1+1', 'Prueba'];
        yield 'resta' => ['-2+3', 'Prueba'];
        yield 'arroba' => ['@SUM(1,1)', 'Prueba'];
        yield 'dde' => ['=cmd|\' /C calc\'!A0', 'Prueba'];
    }

    #[DataProvider('formulaLikeNames')]
    public function test_formula_like_student_names_are_stored_as_literal_text(string $nombres, string $apellidos): void
    {
        $this->makeEnrollment($this->makeStudent('10000001', $nombres, $apellidos), $this->makeCourse('=1+1'));

        $sheet = $this->exportedSheet();
        $estudiante = $sheet->getCell('B2');
        $curso = $sheet->getCell('C2');

        $this->assertSame(DataType::TYPE_STRING, $estudiante->getDataType());
        $this->assertSame($nombres.' '.$apellidos, $estudiante->getValue());
        $this->assertTrue($sheet->getStyle('B2')->getQuotePrefix());

        $this->assertSame(DataType::TYPE_STRING, $curso->getDataType());
        $this->assertSame('=1+1', $curso->getValue());
        $this->assertTrue($sheet->getStyle('C2')->getQuotePrefix());
    }

    public function test_ordinary_values_are_preserved_with_their_types(): void
    {
        $this->makeEnrollment(
            $this->makeStudent('01234567', 'María José', "O'Neil-Ramírez"),
            $this->makeCourse('Ofimática 2026'),
            ['estado' => 'pagada', 'monto_pagado' => 150.5, 'fecha_matricula' => '2026-03-15'],
        );

        $sheet = $this->exportedSheet();

        $this->assertSame(['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto'], $sheet->rangeToArray('A1:F1')[0]);

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertSame("María José O'Neil-Ramírez", $sheet->getCell('B2')->getValue());
        $this->assertFalse($sheet->getStyle('B2')->getQuotePrefix());
        $this->assertSame('Ofimática 2026', $sheet->getCell('C2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D2')->getDataType());
        $this->assertSame('15/03/2026', $sheet->getCell('D2')->getValue());
        $this->assertSame('pagada', $sheet->getCell('E2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('F2')->getDataType());
        $this->assertEqualsWithDelta(150.5, $sheet->getCell('F2')->getValue(), 0.0001);

        $this->assertSame(1, AuditLog::where('action', 'report_excel_exported')->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function exportRoutes(): iterable
    {
        yield 'excel' => ['admin.reportes.exportar.excel'];
        yield 'pdf' => ['admin.reportes.exportar.pdf'];
    }

    #[DataProvider('exportRoutes')]
    public function test_temporary_export_is_removed_when_the_request_fails_after_writing_it(string $route): void
    {
        $this->makeEnrollment($this->makeStudent('10000002', 'Ana', 'Ficticia'), $this->makeCourse('Curso ficticio'));
        $admin = $this->adminUser();
        $directories = [Storage::disk('local')->path('exports'), storage_path('app/exports')];
        $before = $this->exportFiles($directories);

        // La auditoría falla después de que el archivo ya está en disco.
        Schema::drop('audit_logs');

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->post(route($route));
            $this->fail('La exportación debía fallar sin tabla de auditoría.');
        } catch (QueryException) {
            // Esperado.
        }

        $this->assertSame($before, $this->exportFiles($directories));
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    private function exportFiles(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }

    public function test_writer_keeps_numeric_looking_text_as_text(): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();

        SafeSpreadsheet::writeRow($sheet, 1, ['01234567', '-15', 42, -3.5, null]);

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertSame('01234567', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B1')->getDataType());
        $this->assertTrue($sheet->getStyle('B1')->getQuotePrefix());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C1')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D1')->getDataType());
        $this->assertNull($sheet->getCell('E1')->getValue());
    }

    private function exportedSheet(): Worksheet
    {
        $response = $this->actingAs($this->adminUser())
            ->post(route('admin.reportes.exportar.excel'))
            ->assertOk()
            ->assertDownload();

        // En pruebas la respuesta no se envía, así que el archivo temporal sigue en disco.
        $path = $response->baseResponse->getFile()->getPathname();

        try {
            return IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    private function makeStudent(string $dni, string $nombres, string $apellidos): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'estado' => 'activo',
        ]);
    }

    private function makeCourse(string $nombre): Curso
    {
        return Curso::create([
            'codigo' => 'CUR-XLS',
            'nombre' => $nombre,
            'descripcion' => 'Curso ficticio para pruebas de exportación.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 5,
            'cupos_disponibles' => 5,
            'estado' => 'activo',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEnrollment(Estudiante $estudiante, Curso $curso, array $attributes = []): Matricula
    {
        return Matricula::create(array_replace([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pendiente',
            'monto_pagado' => null,
        ], $attributes));
    }
}
