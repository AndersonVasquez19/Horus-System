<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Support\ConfiguracionSistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pulido del panel (B): el nombre de la empresa y la moneda de Configuración se usan en el panel y en los PDF,
 * la pantalla muestra los errores y la auditoría registra antes/después solo cuando hay cambios.
 * SQLite en memoria.
 */
class ConfiguracionEfectivaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    public function test_sin_ajustes_guardados_usa_los_predeterminados(): void
    {
        $this->assertSame('Horus Group SRL', ConfiguracionSistema::nombreEmpresa());
        $this->assertSame('S/ 150.00', ConfiguracionSistema::monto('150'));
    }

    public function test_el_encabezado_del_pdf_usa_el_nombre_configurado(): void
    {
        SystemSetting::create(['key' => 'nombre_empresa', 'value' => 'Academia Prueba SAC']);

        $html = view('pdf.header', ['titulo' => 'Reporte'])->render();

        $this->assertStringContainsString('ACADEMIA PRUEBA SAC', $html);
        $this->assertStringNotContainsString('HORUS GROUP SRL', $html);
    }

    public function test_un_valor_vacio_vuelve_al_predeterminado(): void
    {
        SystemSetting::create(['key' => 'moneda', 'value' => '   ']);

        $this->assertSame('S/', ConfiguracionSistema::moneda());
        $this->assertSame('S/ 1,234.50', ConfiguracionSistema::monto('1234.5'));
    }

    public function test_los_ajustes_se_leen_con_una_sola_consulta_por_peticion(): void
    {
        $admin = $this->adminUser();
        $consultas = 0;
        DB::listen(function ($query) use (&$consultas): void {
            if (str_contains($query->sql, 'system_settings')) {
                $consultas++;
            }
        });

        // Una petición pide el nombre y la moneda varias veces (cabecera de PDF, importes): una sola consulta.
        ConfiguracionSistema::olvidar();
        for ($i = 0; $i < 5; $i++) {
            ConfiguracionSistema::nombreEmpresa();
            ConfiguracionSistema::monto('10');
        }

        $this->assertSame(1, $consultas);
    }
}
