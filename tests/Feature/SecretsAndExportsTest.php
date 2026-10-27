<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\RespaldoController;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SensitiveData;
use ArrayObject;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * L1.1: secretos fuera de la base de datos y del panel; exportaciones minimizadas, auditadas y sin copias persistentes.
 */
class SecretsAndExportsTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_SECRET = 're_SECRETO_HISTORICO_9f8e7d6c';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    // ---- Resend / configuración -------------------------------------------------------------

    public function test_the_application_never_reads_resend_from_system_settings(): void
    {
        // El transporte de correo sale del entorno, no de la tabla de ajustes.
        $this->assertSame(env('RESEND_API_KEY'), config('services.resend.key'));

        $codigo = '';
        foreach (['app', 'config', 'resources', 'routes'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $archivo) {
                if ($archivo->isFile() && in_array($archivo->getExtension(), ['php'], true)) {
                    $codigo .= file_get_contents($archivo->getPathname());
                }
            }
        }

        $this->assertStringNotContainsString("value('api_resend'", $codigo);
        $this->assertStringNotContainsString('settings[api_resend]', $codigo);
    }

    public function test_settings_page_does_not_expose_the_legacy_resend_secret(): void
    {
        SystemSetting::create(['key' => 'api_resend', 'value' => self::LEGACY_SECRET, 'description' => 'Clave API de Resend']);

        $this->actingAs($this->staff('admin'))
            ->get(route('admin.configuracion.index'))
            ->assertOk()
            ->assertSee('Guardar configuración')
            ->assertDontSee(self::LEGACY_SECRET)
            ->assertDontSee('api_resend')
            ->assertDontSee('API de Resend');
    }

    public function test_settings_update_ignores_and_never_persists_or_audits_a_resend_key(): void
    {
        $nuevo = 're_NUEVO_SECRETO_a1b2c3';

        $this->actingAs($this->staff('admin'))
            ->put(route('admin.configuracion.update'), [
                'settings' => [
                    'moneda' => 'USD',
                    'api_resend' => $nuevo,
                    'clave_inventada' => 'x',
                ],
            ])
            ->assertRedirect(route('admin.configuracion.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('USD', SystemSetting::value('moneda'));
        $this->assertDatabaseMissing('system_settings', ['key' => 'api_resend']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'clave_inventada']);
        $this->assertNoSecretInDatabase($nuevo);

        $log = AuditLog::where('action', 'settings_updated')->firstOrFail();
        $this->assertSame(['moneda' => 'USD'], $log->changes);
    }

    public function test_an_existing_legacy_resend_row_is_left_untouched_by_the_panel(): void
    {
        SystemSetting::create(['key' => 'api_resend', 'value' => self::LEGACY_SECRET, 'description' => 'Clave API de Resend']);

        $this->actingAs($this->staff('admin'))
            ->put(route('admin.configuracion.update'), ['settings' => ['telefono' => '+51 900 111 222']])
            ->assertRedirect(route('admin.configuracion.index'));

        // La limpieza de claves históricas es un procedimiento manual (docs/seguridad-l1.1.md): no se borra sola.
        $this->assertSame(self::LEGACY_SECRET, SystemSetting::where('key', 'api_resend')->value('value'));
        $this->assertSame('+51 900 111 222', SystemSetting::value('telefono'));
    }

    public function test_base_seeders_no_longer_create_the_resend_setting(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('system_settings', ['key' => 'api_resend']);
        $this->assertDatabaseHas('system_settings', ['key' => 'moneda']);
    }

    public function test_settings_require_their_own_permissions(): void
    {
        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->get(route('admin.configuracion.index'))->assertForbidden();
            $this->put(route('admin.configuracion.update'), ['settings' => ['moneda' => 'X']])->assertForbidden();
        }

        $soloLectura = $this->staff('coordinador');
        $soloLectura->givePermissionTo('configuracion.ver');

        $this->actingAs($soloLectura)->get(route('admin.configuracion.index'))->assertOk();
        $this->put(route('admin.configuracion.update'), ['settings' => ['moneda' => 'X']])->assertForbidden();
        $this->assertNotSame('X', SystemSetting::value('moneda'));

        $this->actingAs(User::factory()->create())->get(route('admin.configuracion.index'))->assertForbidden();
    }

    // ---- Auditoría --------------------------------------------------------------------------

    public function test_audit_log_masks_sensitive_values_on_write(): void
    {
        $log = AuditLog::record('prueba', null, null, [
            'api_resend' => self::LEGACY_SECRET,
            'password' => 'Clave-Humana-1',
            'nested' => ['access_token' => 'tok_123', 'nombre' => 'visible'],
            'moneda' => 'S/',
        ]);

        $this->assertSame(SensitiveData::MASK, $log->changes['api_resend']);
        $this->assertSame(SensitiveData::MASK, $log->changes['password']);
        $this->assertSame(SensitiveData::MASK, $log->changes['nested']['access_token']);
        $this->assertSame('visible', $log->changes['nested']['nombre']);
        $this->assertSame('S/', $log->changes['moneda']);
        $this->assertNoSecretInDatabase(self::LEGACY_SECRET);
        $this->assertNoSecretInDatabase('Clave-Humana-1');
    }

    public function test_historical_audit_rows_with_secrets_are_masked_in_page_and_export(): void
    {
        $this->insertHistoricalAuditRow();
        $admin = $this->staff('admin');

        $this->actingAs($admin)
            ->get(route('admin.auditoria.index'))
            ->assertOk()
            ->assertSee('settings_updated')
            ->assertDontSee(self::LEGACY_SECRET)
            ->assertSee(SensitiveData::MASK);

        $respuesta = $this->get(route('admin.auditoria.exportar'))->assertOk();
        $json = $respuesta->streamedContent();

        $this->assertStringNotContainsString(self::LEGACY_SECRET, $json);

        $registros = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $registros);
        $this->assertSame(
            ['moneda' => 'S/', 'api_resend' => SensitiveData::MASK],
            json_decode($registros[0]['cambios'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_audit_export_is_audited_and_leaves_no_copy_on_the_server(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get(route('admin.auditoria.exportar'))->assertOk()->streamedContent();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $log = AuditLog::where('action', 'audit_exported')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringStartsWith('auditoria_', $log->changes['filename']);
    }

    // ---- Exportación administrativa (ZIP) ---------------------------------------------------

    public function test_zip_export_omits_authentication_data_and_masks_secrets(): void
    {
        $admin = $this->staff('admin');
        $admin->forceFill(['remember_token' => 'REMEMBER_TOKEN_PRUEBA', 'jwt_version' => 7])->save();
        $hash = $admin->fresh()->password;
        SystemSetting::create(['key' => 'api_resend', 'value' => self::LEGACY_SECRET, 'description' => 'Clave API de Resend']);
        SystemSetting::create(['key' => 'moneda', 'value' => 'S/', 'description' => 'Símbolo monetario']);
        $this->insertHistoricalAuditRow();
        DB::table('password_reset_tokens')->insert(['email' => $admin->email, 'token' => 'TOKEN_RESET_PRUEBA', 'created_at' => now()]);

        $archivos = $this->downloadZip($this->actingAs($admin)->get(route('admin.respaldo.exportar')));

        $this->assertEqualsCanonicalizing(
            ['users', 'estudiantes', 'cursos', 'matriculas', 'procesos_internos', 'notificaciones_internas', 'audit_logs', 'system_settings', 'aprobaciones_internas'],
            array_map(fn (string $n): string => basename($n, '.csv'), array_diff(array_keys($archivos), ['manifest.json'])),
        );

        $cabeceraUsuarios = str_getcsv(strtok($archivos['users.csv'], "\n"));
        $this->assertContains('email', $cabeceraUsuarios);
        foreach (['password', 'remember_token', 'jwt_version'] as $columna) {
            $this->assertNotContains($columna, $cabeceraUsuarios);
        }

        $todo = implode("\n", $archivos);
        foreach ([$hash, 'REMEMBER_TOKEN_PRUEBA', 'TOKEN_RESET_PRUEBA', self::LEGACY_SECRET] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }

        $this->assertStringContainsString(SensitiveData::MASK, $archivos['system_settings.csv']);
        $this->assertStringContainsString(SensitiveData::MASK, $archivos['audit_logs.csv']);
        $this->assertStringContainsString('S/', $archivos['system_settings.csv']);
    }

    public function test_zip_export_is_not_presented_as_a_restorable_backup(): void
    {
        $archivos = $this->downloadZip($this->actingAs($this->staff('admin'))->get(route('admin.respaldo.exportar')));
        $manifiesto = json_decode($archivos['manifest.json'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('exportacion_administrativa', $manifiesto['tipo']);
        $this->assertFalse($manifiesto['es_respaldo_restaurable']);
        $this->assertStringContainsString('No es una copia de seguridad completa de PostgreSQL', $manifiesto['descripcion']);
        $this->assertContains('password', $manifiesto['columnas_omitidas_por_seguridad']['users']);

        $this->get(route('admin.respaldo.index'))
            ->assertOk()
            ->assertSee('No es una copia de seguridad')
            ->assertDontSee('Generar respaldo');
    }

    public function test_zip_export_leaves_no_copy_and_deletes_its_temporary_file(): void
    {
        $admin = $this->staff('admin');
        $respuesta = $this->actingAs($admin)->get(route('admin.respaldo.exportar'))->assertOk()->assertDownload();
        $temporal = $respuesta->baseResponse->getFile()->getPathname();
        $this->assertFileExists($temporal);

        $this->downloadZip($respuesta);

        $this->assertFileDoesNotExist($temporal);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_zip_export_is_audited_with_the_acting_user(): void
    {
        $admin = $this->staff('admin');
        $this->downloadZip($this->actingAs($admin)->get(route('admin.respaldo.exportar')));

        $log = AuditLog::where('action', 'data_export_generated')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertContains('users', $log->changes['tables']);
        $this->assertStringStartsWith('exportacion_', $log->changes['filename']);
    }

    public function test_exports_neutralize_spreadsheet_formulas(): void
    {
        $admin = $this->staff('admin');
        User::factory()->create(['name' => '=HYPERLINK("http://malo.example","clic")', 'email' => 'formula@example.com']);

        $archivos = $this->downloadZip($this->actingAs($admin)->get(route('admin.respaldo.exportar')));

        $this->assertStringContainsString("'=HYPERLINK", $archivos['users.csv']);
        $this->assertStringNotContainsString(',"=HYPERLINK', $archivos['users.csv']);
        $this->assertSame('-5.00', SensitiveData::csvSafe('-5.00'), 'Los números no se alteran.');
        $this->assertSame("'@SUM(A1)", SensitiveData::csvSafe('@SUM(A1)'));
    }

    // ---- Fallos durante la exportación ZIP --------------------------------------------------
    //
    // En cada fallo: ninguna descarga, el temporal que creó la exportación ya no existe (su ruta exacta se
    // registra mediante RespaldoController::newTemporaryFile), nada en el disco `local` y ningún registro
    // de exportación generada. La excepción no se oculta: llega al manejador de excepciones
    // (Exceptions::fake la captura sin escribir logs).

    public function test_zip_export_removes_its_temporary_file_when_auditing_fails(): void
    {
        Exceptions::fake();
        AuditLog::creating(static function (): void {
            throw new RuntimeException('auditoria-no-disponible');
        });

        $this->assertExportFailsCleanly();

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'auditoria-no-disponible');
    }

    public function test_zip_export_removes_its_temporary_file_when_a_table_query_fails(): void
    {
        Exceptions::fake();
        // `users` ya se habrá añadido al ZIP cuando falle `estudiantes`: fallo a mitad de la generación.
        DB::beforeExecuting(static function (string $query): void {
            if (str_contains($query, 'from "estudiantes"')) {
                throw new RuntimeException('tabla-no-disponible');
            }
        });

        $this->assertExportFailsCleanly();

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'tabla-no-disponible');
    }

    public function test_zip_export_is_not_delivered_when_closing_the_archive_fails(): void
    {
        Exceptions::fake();
        // El ZIP llega a escribirse en disco pero `close()` informa de fallo: no debe entregarse ni quedar.
        $this->assertExportFailsCleanly($this->failingArchive(failOnClose: true, failOnAdd: false));

        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'finalizar'));
    }

    public function test_zip_export_is_not_delivered_when_an_entry_cannot_be_written(): void
    {
        Exceptions::fake();
        $this->assertExportFailsCleanly($this->failingArchive(failOnClose: false, failOnAdd: true));

        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'No se pudo añadir'));
    }

    public function test_a_failed_export_does_not_touch_other_files_in_the_temp_directory(): void
    {
        Exceptions::fake();
        $ajeno = tempnam(sys_get_temp_dir(), 'horus_ajeno_');
        AuditLog::creating(static function (): void {
            throw new RuntimeException('auditoria-no-disponible');
        });

        try {
            $this->assertExportFailsCleanly();
            $this->assertFileExists($ajeno, 'Solo se elimina el temporal propio de la exportación fallida.');
        } finally {
            @unlink($ajeno);
        }
    }

    // ---- Listado de archivos heredados ------------------------------------------------------

    public function test_export_listing_only_shows_the_private_exports_folder_and_warns_about_older_zip_files(): void
    {
        Storage::disk('local')->put('exports/auditoria_20260101_000000.json', 'contenido-antiguo');
        // Los ZIP de versiones anteriores se guardaban fuera de `exports/` del disco `local`: no se listan.
        Storage::disk('local')->put('otra-carpeta/respaldo_20260101_000000.zip', 'contenido-antiguo');

        $this->actingAs($this->staff('admin'))
            ->get(route('admin.respaldo.index'))
            ->assertOk()
            ->assertSee('auditoria_20260101_000000.json')
            ->assertDontSee('respaldo_20260101_000000.zip')
            ->assertSee('Archivos heredados de versiones anteriores')
            ->assertSee('No incluye los ZIP de «respaldo»');
    }

    public function test_the_legacy_zip_warning_is_shown_even_when_the_listing_is_empty_and_exposes_no_server_path(): void
    {
        $this->actingAs($this->staff('admin'))
            ->get(route('admin.respaldo.index'))
            ->assertOk()
            ->assertSee('No incluye los ZIP de «respaldo»')
            ->assertSee('aunque esta lista esté vacía')
            ->assertDontSee('storage/app')
            ->assertDontSee('storage\\app');
    }

    public function test_export_routes_keep_their_permission_requirements(): void
    {
        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->get(route('admin.respaldo.exportar'))->assertForbidden();
            $this->get(route('admin.auditoria.exportar'))->assertForbidden();
        }

        $this->assertSame(0, AuditLog::whereIn('action', ['data_export_generated', 'audit_exported'])->count());
    }

    // ---- Exportación CSV de matrículas ------------------------------------------------------

    public function test_enrollment_csv_is_audited_and_neutralizes_formulas(): void
    {
        $curso = Curso::create([
            'codigo' => 'CUR-CSV',
            'nombre' => '=cmd|calc',
            'descripcion' => 'x',
            'categoria' => 'tecnologia',
            'duracion_horas' => 1,
            'precio' => 1,
            'modalidad' => 'virtual',
            'horario' => 'x',
            'cupos_maximos' => 5,
            'cupos_disponibles' => 4,
            'estado' => 'activo',
        ]);
        $estudiante = Estudiante::create(['dni' => '70000555', 'nombres' => '@Maria', 'apellidos' => 'Perez', 'estado' => 'activo']);
        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pagada',
            'monto_pagado' => 150,
        ]);
        $admin = $this->staff('admin');

        $csv = $this->actingAs($admin)->get(route('admin.matriculas.csv', ['estado' => 'pagada']))->assertOk()->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString("'@Maria Perez", $csv);
        $this->assertStringContainsString('150.00', $csv);

        $log = AuditLog::where('action', 'matriculas_csv_exported')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('pagada', $log->changes['filters']['estado']);
    }

    // ---- Utilidades -------------------------------------------------------------------------

    /**
     * Lanza la exportación ZIP (con el ZIP indicado, si se pasa uno) y comprueba que, al fallar, no hay
     * descarga ni residuos: el temporal creado por la exportación debe haber desaparecido.
     */
    private function assertExportFailsCleanly(?ZipArchive $zip = null): void
    {
        $rastro = $this->trackedController($zip);
        $admin = $this->staff('admin');

        $this->actingAs($admin)
            ->from(route('admin.respaldo.index'))
            ->get(route('admin.respaldo.exportar'))
            ->assertRedirect(route('admin.respaldo.index'))
            ->assertSessionHas('error')
            ->assertHeaderMissing('Content-Disposition');

        // Si la lista estuviera vacía la comprobación no probaría nada: el temporal sí debió crearse.
        $this->assertCount(1, $rastro, 'La exportación debió crear exactamente un temporal.');
        foreach ($rastro as $temporal) {
            $this->assertFileDoesNotExist($temporal, 'El temporal de la exportación fallida debe eliminarse.');
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'data_export_generated']);
    }

    /** ZipArchive que falla a demanda, para simular disco lleno o errores de escritura. */
    private function failingArchive(bool $failOnClose, bool $failOnAdd): ZipArchive
    {
        return new class($failOnClose, $failOnAdd) extends ZipArchive
        {
            public function __construct(private readonly bool $failOnClose, private readonly bool $failOnAdd) {}

            public function close(): bool
            {
                $escrito = parent::close();

                return $this->failOnClose ? false : $escrito;
            }

            public function addFromString(string $name, string $content, int $flags = ZipArchive::FL_OVERWRITE): bool
            {
                return $this->failOnAdd ? false : parent::addFromString($name, $content, $flags);
            }
        };
    }

    /**
     * Sustituye el controlador (la ruta lo resuelve desde el contenedor) por uno que registra la ruta de
     * cada temporal que crea y, opcionalmente, usa el ZIP indicado.
     *
     * @return ArrayObject<int, string> Rutas de los temporales creados.
     */
    private function trackedController(?ZipArchive $zip): ArrayObject
    {
        $rastro = new ArrayObject;

        $this->app->bind(RespaldoController::class, fn (): RespaldoController => new class($zip, $rastro) extends RespaldoController
        {
            /** @param ArrayObject<int, string> $rastro */
            public function __construct(private readonly ?ZipArchive $zip, private readonly ArrayObject $rastro) {}

            protected function newTemporaryFile(): string|false
            {
                $ruta = parent::newTemporaryFile();

                if ($ruta !== false) {
                    $this->rastro[] = $ruta;
                }

                return $ruta;
            }

            protected function newArchive(): ZipArchive
            {
                return $this->zip ?? parent::newArchive();
            }
        });

        return $rastro;
    }

    private function staff(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function insertHistoricalAuditRow(): void
    {
        DB::table('audit_logs')->insert([
            'action' => 'settings_updated',
            'model_type' => 'SystemSetting',
            'changes' => json_encode(['moneda' => 'S/', 'api_resend' => self::LEGACY_SECRET]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    private function assertNoSecretInDatabase(string $secret): void
    {
        foreach (['system_settings' => ['value'], 'audit_logs' => ['changes']] as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $this->assertFalse(
                    DB::table($tabla)->where($columna, 'like', '%'.$secret.'%')->exists(),
                    "El secreto no debe persistir en {$tabla}.{$columna}.",
                );
            }
        }
    }

    /**
     * Envía la respuesta de descarga (lo que elimina el temporal) y devuelve el contenido de cada archivo del ZIP.
     *
     * @return array<string, string>
     */
    private function downloadZip(TestResponse $respuesta): array
    {
        ob_start();
        $respuesta->baseResponse->sendContent();
        $bytes = (string) ob_get_clean();

        $this->assertStringStartsWith('PK', $bytes, 'La respuesta debe ser un ZIP.');

        $ruta = tempnam(sys_get_temp_dir(), 'horus_test_');
        file_put_contents($ruta, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $archivos = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $archivos[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($ruta);

        return $archivos;
    }
}
