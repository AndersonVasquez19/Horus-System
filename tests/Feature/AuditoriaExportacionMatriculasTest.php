<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\SensitiveData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Sprint 21.2 · Fase 4: auditoría saneada de la exportación CSV de matrículas y enmascarado de los registros
 * históricos que guardaron el texto libre de búsqueda.
 *
 * SQLite en memoria. Los registros históricos se insertan con DB::table para demostrar que el valor original
 * permanece intacto en la base mientras todas las salidas lo muestran enmascarado.
 */
class AuditoriaExportacionMatriculasTest extends TestCase
{
    use RefreshDatabase;

    private const BUSQUEDA = '71000001 Ana Prueba';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    // ---------------------------------------------------------------------------------------------
    // Nuevo registro de la exportación
    // ---------------------------------------------------------------------------------------------

    public function test_el_registro_nuevo_contiene_solo_la_lista_cerrada_y_un_booleano_real(): void
    {
        // Un usuario previo para que el administrador no tenga el id 1 y un actor fijo no pase inadvertido.
        User::factory()->create();
        $admin = $this->adminUser();
        $curso = $this->matriculaConDatos()[1];

        $this->actingAs($admin)
            ->post(route('admin.matriculas.csv', ['estado' => 'pagada', 'curso_id' => (string) $curso->id, 'eliminados' => '0', 'busqueda' => self::BUSQUEDA]))
            ->assertOk()
            ->streamedContent();

        $registro = AuditLog::where('action', 'matriculas_csv_exported')->sole();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame(Matricula::class, $registro->model_type);
        $this->assertNull($registro->model_id);

        $cambios = $registro->changes;
        $this->assertSame(['busqueda_aplicada', 'curso_id', 'eliminados', 'estado', 'filename'], $this->clavesOrdenadas($cambios));
        $this->assertMatchesRegularExpression('/^matriculas_\d{8}_\d{6}\.csv$/', $cambios['filename']);
        $this->assertSame('pagada', $cambios['estado']);
        $this->assertSame($curso->id, $cambios['curso_id']);
        $this->assertFalse($cambios['eliminados']);
        $this->assertTrue($cambios['busqueda_aplicada']);
        $this->assertArrayNotHasKey('filters', $cambios);
        $this->assertArrayNotHasKey('busqueda', $cambios);
    }

    public function test_el_registro_nuevo_no_contiene_busqueda_dni_nombres_ni_correo(): void
    {
        $admin = $this->adminUser();
        $this->matriculaConDatos();

        $this->actingAs($admin)
            ->post(route('admin.matriculas.csv', ['busqueda' => self::BUSQUEDA]))
            ->assertOk()
            ->streamedContent();

        $texto = (string) json_encode(DB::table('audit_logs')->get()->all(), JSON_UNESCAPED_UNICODE);
        foreach (['71000001', 'Ana', 'Prueba', 'ana.prueba@example.test', self::BUSQUEDA] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
    }

    public function test_sin_filtros_se_registran_valores_nulos_y_booleanos_falsos(): void
    {
        $admin = $this->adminUser();
        $this->matriculaConDatos();

        $this->actingAs($admin)->post(route('admin.matriculas.csv'))->assertOk()->streamedContent();

        $cambios = AuditLog::where('action', 'matriculas_csv_exported')->sole()->changes;
        $this->assertNull($cambios['estado']);
        $this->assertNull($cambios['curso_id']);
        $this->assertFalse($cambios['eliminados']);
        $this->assertFalse($cambios['busqueda_aplicada']);
    }

    public function test_eliminados_se_registra_como_booleano_verdadero(): void
    {
        $admin = $this->adminUser();
        $this->matriculaConDatos();

        $this->actingAs($admin)->post(route('admin.matriculas.csv', ['eliminados' => '1']))->assertOk()->streamedContent();

        $this->assertTrue(AuditLog::where('action', 'matriculas_csv_exported')->sole()->changes['eliminados']);
    }

    public function test_busqueda_vacia_o_con_forma_de_array_no_cuenta_como_aplicada(): void
    {
        $admin = $this->adminUser();
        $this->matriculaConDatos();

        foreach (['', '   '] as $busqueda) {
            $this->actingAs($admin)->post(route('admin.matriculas.csv', ['busqueda' => $busqueda]))->assertOk()->streamedContent();
        }
        $this->actingAs($admin)->post(route('admin.matriculas.csv', ['busqueda' => ['Ana']]))->assertOk()->streamedContent();

        $registros = AuditLog::where('action', 'matriculas_csv_exported')->get();
        $this->assertCount(3, $registros);
        foreach ($registros as $registro) {
            $this->assertFalse($registro->changes['busqueda_aplicada']);
        }
    }

    public function test_estado_y_curso_invalidos_no_se_copian_a_la_auditoria(): void
    {
        $admin = $this->adminUser();
        $this->matriculaConDatos();

        $this->actingAs($admin)
            ->post(route('admin.matriculas.csv', ['estado' => '71000001', 'curso_id' => 'Ana Prueba']))
            ->assertOk()
            ->streamedContent();

        $registro = AuditLog::where('action', 'matriculas_csv_exported')->sole();
        $this->assertNull($registro->changes['estado']);
        $this->assertNull($registro->changes['curso_id']);
        $this->assertStringNotContainsString('71000001', (string) json_encode($registro->changes));
        $this->assertStringNotContainsString('Ana', (string) json_encode($registro->changes));
    }

    public function test_el_contenido_del_csv_no_cambia(): void
    {
        $admin = $this->adminUser();
        [$matricula, $curso] = $this->matriculaConDatos();

        $csv = $this->actingAs($admin)
            ->post(route('admin.matriculas.csv', ['busqueda' => 'Ana']))
            ->assertOk()
            ->streamedContent();

        $esperado = "\xEF\xBB\xBF"
            ."ID,DNI,Estudiante,Curso,\"Código Curso\",Fecha,Estado,Monto\n"
            .$matricula->id.',71000001,"Ana Prueba","Curso ficticio",SOURCE,2026-10-28,Pagada,150.50'."\n";
        $this->assertSame($esperado, $csv);
        $this->assertStringContainsString('text/csv', (string) $this->actingAs($admin)->post(route('admin.matriculas.csv'))->headers->get('Content-Type'));
    }

    public function test_si_la_auditoria_falla_no_se_entrega_el_archivo(): void
    {
        $admin = $this->adminUser();
        $this->matriculaConDatos();
        AuditLog::creating(static function (): void {
            throw new RuntimeException('Fallo simulado de auditoría');
        });

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        try {
            $this->actingAs($admin)->post(route('admin.matriculas.csv'));
        } finally {
            $this->assertSame(0, DB::table('audit_logs')->count());
        }
    }

    public function test_sin_permiso_no_hay_exportacion_ni_auditoria(): void
    {
        $this->matriculaConDatos();
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->post(route('admin.matriculas.csv'))->assertForbidden();
        }

        $this->assertSame(0, AuditLog::where('action', 'matriculas_csv_exported')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // Registros históricos
    // ---------------------------------------------------------------------------------------------

    public function test_el_modelo_enmascara_la_busqueda_historica_sin_alterar_el_resto(): void
    {
        $registro = AuditLog::find($this->insertarHistorico());

        $this->assertSame([
            'filename' => 'matriculas_20260101_000000.csv',
            'filters' => ['estado' => 'pagada', 'curso_id' => '5', 'busqueda' => SensitiveData::MASK, 'eliminados' => '0'],
        ], $registro->redactedChanges());
    }

    public function test_el_valor_original_permanece_intacto_en_la_base(): void
    {
        $id = $this->insertarHistorico();
        $antes = (array) DB::table('audit_logs')->where('id', $id)->first();

        $admin = $this->adminUser();
        $this->actingAs($admin)->get(route('admin.auditoria.index'))->assertOk();
        $this->actingAs($admin)->post(route('admin.auditoria.exportar'))->assertOk()->streamedContent();
        $this->downloadZip($this->actingAs($admin)->post(route('admin.respaldo.exportar')));

        $despues = (array) DB::table('audit_logs')->where('id', $id)->first();
        $this->assertSame($antes, $despues);
        $this->assertStringContainsString(self::BUSQUEDA, (string) $despues['changes']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'matriculas_csv_exported')->count());
    }

    public function test_la_pantalla_de_auditoria_muestra_la_busqueda_historica_enmascarada(): void
    {
        $this->insertarHistorico();

        $html = $this->actingAs($this->adminUser())->get(route('admin.auditoria.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('71000001', (string) $html);
        $this->assertStringNotContainsString('Ana Prueba', (string) $html);
        $this->assertStringContainsString('matriculas_20260101_000000.csv', (string) $html);
        $this->assertStringContainsString(SensitiveData::MASK, (string) $html);
    }

    public function test_la_exportacion_json_de_auditoria_enmascara_la_busqueda_historica(): void
    {
        $this->insertarHistorico();

        $json = $this->actingAs($this->adminUser())->post(route('admin.auditoria.exportar'))->assertOk()->streamedContent();

        $this->assertStringNotContainsString('71000001', $json);
        $this->assertStringNotContainsString('Ana Prueba', $json);
        $this->assertStringContainsString(SensitiveData::MASK, $json);
        $this->assertStringContainsString('matriculas_20260101_000000.csv', $json);
    }

    public function test_la_exportacion_administrativa_zip_enmascara_la_busqueda_historica(): void
    {
        $this->insertarHistorico();

        $archivos = $this->downloadZip($this->actingAs($this->adminUser())->post(route('admin.respaldo.exportar')));

        $this->assertArrayHasKey('audit_logs.csv', $archivos);
        $this->assertStringNotContainsString('71000001', $archivos['audit_logs.csv']);
        $this->assertStringNotContainsString('Ana Prueba', $archivos['audit_logs.csv']);
        $this->assertStringContainsString(SensitiveData::MASK, $archivos['audit_logs.csv']);
        $this->assertStringContainsString('matriculas_20260101_000000.csv', $archivos['audit_logs.csv']);
    }

    public function test_el_panel_de_inicio_no_expone_la_busqueda_historica(): void
    {
        $this->insertarHistorico();

        $html = (string) $this->actingAs($this->adminUser())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('71000001', $html);
        $this->assertStringNotContainsString('Ana Prueba', $html);
    }

    public function test_la_serializacion_del_modelo_enmascara_la_busqueda_historica(): void
    {
        $registro = AuditLog::find($this->insertarHistorico());

        $this->assertStringNotContainsString('71000001', $registro->toJson());
        $this->assertStringNotContainsString('Ana Prueba', (string) json_encode($registro->toArray()));
        $this->assertStringContainsString(SensitiveData::MASK, $registro->toJson());
        // El atributo real sigue intacto: solo cambia la presentación.
        $this->assertSame(self::BUSQUEDA, $registro->changes['filters']['busqueda']);
    }

    public function test_la_busqueda_historica_con_forma_de_array_o_en_otro_nivel_tambien_se_enmascara(): void
    {
        $arreglo = DB::table('audit_logs')->insertGetId([
            'action' => 'matriculas_csv_exported', 'model_type' => Matricula::class,
            'changes' => json_encode(['filters' => ['busqueda' => ['71000001', 'Ana']]]),
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);
        $superior = DB::table('audit_logs')->insertGetId([
            'action' => 'matriculas_csv_exported', 'model_type' => Matricula::class,
            'changes' => json_encode(['busqueda' => '71000001 Ana', 'filename' => 'x.csv']),
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $this->assertSame(['filters' => ['busqueda' => SensitiveData::MASK]], AuditLog::find($arreglo)->redactedChanges());
        $this->assertSame(['busqueda' => SensitiveData::MASK, 'filename' => 'x.csv'], AuditLog::find($superior)->redactedChanges());
    }

    public function test_una_busqueda_historica_vacia_no_se_marca_como_enmascarada(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'action' => 'matriculas_csv_exported', 'model_type' => Matricula::class,
            'changes' => json_encode(['filename' => 'x.csv', 'filters' => ['estado' => 'pagada', 'busqueda' => null]]),
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $this->assertSame(['filename' => 'x.csv', 'filters' => ['estado' => 'pagada', 'busqueda' => null]], AuditLog::find($id)->redactedChanges());
    }

    // ---------------------------------------------------------------------------------------------
    // No regresión en otros módulos
    // ---------------------------------------------------------------------------------------------

    public function test_las_auditorias_de_otros_modulos_se_muestran_sin_cambios(): void
    {
        $curso = Curso::create([
            'codigo' => 'OTRO', 'nombre' => 'Otro curso', 'descripcion' => 'Prueba', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Prueba',
            'cupos_maximos' => 2, 'cupos_disponibles' => 2, 'estado' => 'activo',
        ]);
        $curso->delete();

        $cambios = ['esquema' => 1, 'campos' => ['nombre'], 'antes' => ['nombre' => 'A'], 'despues' => ['nombre' => 'B']];
        $creado = AuditLog::record('curso_actualizado', Curso::class, $curso->id, $cambios, $this->adminUser()->id);
        $reclamo = AuditLog::record('settings_updated', 'SystemSetting', null, ['api_resend' => 'secreto-xyz', 'moneda' => 'S/'], null);

        $this->assertSame($cambios, $creado->fresh()->redactedChanges());
        $this->assertSame(['api_resend' => SensitiveData::MASK, 'moneda' => 'S/'], $reclamo->fresh()->redactedChanges());
    }

    // ---------------------------------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array{0: Matricula, 1: Curso}
     */
    private function matriculaConDatos(): array
    {
        $estudiante = Estudiante::create([
            'dni' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'email' => 'ana.prueba@example.test', 'estado' => 'activo',
        ]);
        $curso = Curso::create([
            'codigo' => 'SOURCE', 'nombre' => 'Curso ficticio', 'descripcion' => 'Prueba', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Prueba',
            'cupos_maximos' => 3, 'cupos_disponibles' => 2, 'estado' => 'activo',
        ]);
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-10-28',
            'estado' => 'pagada', 'monto_pagado' => 150.5,
        ]);

        return [$matricula, $curso];
    }

    /** Registro como lo dejaba la versión anterior de la exportación (texto libre de búsqueda incluido). */
    private function insertarHistorico(): int
    {
        return DB::table('audit_logs')->insertGetId([
            'action' => 'matriculas_csv_exported',
            'model_type' => Matricula::class,
            'changes' => json_encode([
                'filename' => 'matriculas_20260101_000000.csv',
                'filters' => ['estado' => 'pagada', 'curso_id' => '5', 'busqueda' => self::BUSQUEDA, 'eliminados' => '0'],
            ]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $datos
     * @return list<array-key>
     */
    private function clavesOrdenadas(array $datos): array
    {
        $claves = array_keys($datos);
        sort($claves);

        return $claves;
    }

    private function staff(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    /** @return array<string, string> contenido de cada archivo del ZIP descargado */
    private function downloadZip(TestResponse $respuesta): array
    {
        ob_start();
        $respuesta->baseResponse->sendContent();
        $bytes = (string) ob_get_clean();

        $this->assertStringStartsWith('PK', $bytes, 'La respuesta debe ser un ZIP.');

        $ruta = (string) tempnam(sys_get_temp_dir(), 'horus_test_');
        file_put_contents($ruta, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $archivos = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $archivos[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($ruta);

        return $archivos;
    }
}
