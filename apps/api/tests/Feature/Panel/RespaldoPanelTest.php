<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use ZipArchive;

/** Exportación administrativa desde el panel de apps/web: misma minimización que el módulo Blade. Datos ficticios. */
final class RespaldoPanelTest extends PanelApiTestCase
{
    public function test_el_admin_descarga_un_zip_minimizado_y_auditado_sin_dejar_copia(): void
    {
        Storage::fake('local');
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        User::factory()->create(['name' => '=HYPERLINK("http://malo.example","clic")', 'email' => 'formula@example.test']);

        $respuesta = $this->actingAs($admin)->panel('POST', 'compartido/respaldo/exportar')->assertOk()->assertDownload();
        $temporal = $respuesta->baseResponse->getFile()->getPathname();
        $this->assertFileExists($temporal);

        $archivos = $this->leerZip($respuesta);
        $this->assertFileDoesNotExist($temporal);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $cabecera = str_getcsv(strtok($archivos['users.csv'], "\n"));
        $this->assertContains('email', $cabecera);
        foreach (['password', 'remember_token', 'jwt_version'] as $columna) {
            $this->assertNotContains($columna, $cabecera);
        }
        $this->assertStringNotContainsString($admin->password, implode("\n", $archivos));
        $this->assertStringContainsString("'=HYPERLINK", $archivos['users.csv'], 'Las fórmulas se neutralizan.');

        $manifiesto = json_decode($archivos['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($manifiesto['es_respaldo_restaurable']);

        $log = AuditLog::where('action', 'data_export_generated')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('panel', $log->changes['canal']);
    }

    public function test_permisos_y_sesion(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->panel('GET', 'compartido/respaldo')->assertUnauthorized();
        $this->panel('POST', 'compartido/respaldo/exportar')->assertUnauthorized();

        $coordinador = $this->usuarioConRol('coordinador', 'coord@horus.test');
        $this->actingAs($coordinador)->panel('GET', 'compartido/respaldo')->assertOk()
            ->assertJsonPath('data.es_respaldo_restaurable', false)->assertJsonPath('data.tablas.0', 'users')
            ->assertJsonPath('data.contenido.0', ['clave' => 'users', 'etiqueta' => 'Usuarios del panel'])
            ->assertJsonPath('data.contenido.6.etiqueta', 'Historial de actividad');
        $this->actingAs($coordinador)->panel('POST', 'compartido/respaldo/exportar')->assertForbidden();

        $secretaria = $this->usuarioConRol('secretaria', 'sec@horus.test');
        $this->actingAs($secretaria)->panel('GET', 'compartido/respaldo')->assertForbidden();
        $this->actingAs($secretaria)->panel('POST', 'compartido/respaldo/exportar')->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'data_export_generated')->count());
    }

    /** @return array<string, string> */
    private function leerZip(TestResponse $respuesta): array
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
