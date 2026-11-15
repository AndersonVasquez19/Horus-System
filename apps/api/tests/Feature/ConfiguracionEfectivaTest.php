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
        $this->actingAs($this->adminUser())->get(route('admin.respaldo.index'))
            ->assertOk()
            ->assertSee('<title>Respaldo | Horus Group SRL</title>', false);
        $this->assertSame('S/ 150.00', ConfiguracionSistema::monto('150'));
    }

    /**
     * El nombre de la empresa sigue viéndose en las páginas Blade restantes (título, barra superior, menú) y la moneda
     * en todo importe que formatea el servidor (PDF, correos). Los importes del panel los muestra apps/web con la moneda
     * que entrega la sesión del panel.
     */
    public function test_el_nombre_y_la_moneda_configurados_se_ven_en_el_panel(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->put(route('admin.configuracion.update'), [
            'settings' => ['nombre_empresa' => 'Academia Prueba SAC', 'moneda' => 'USD'],
        ])->assertRedirect(route('admin.configuracion.index'));

        $this->actingAs($admin)->get(route('admin.respaldo.index'))
            ->assertOk()
            ->assertSee('<title>Respaldo | Academia Prueba SAC</title>', false);
        $this->assertSame('USD 150.00', ConfiguracionSistema::monto('150'));
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

    public function test_los_errores_de_validacion_se_muestran_en_la_pantalla(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->from(route('admin.configuracion.index'))
            ->put(route('admin.configuracion.update'), ['settings' => ['correo_contacto' => 'no-es-correo']])
            ->assertRedirect(route('admin.configuracion.index'))
            ->assertSessionHasErrors('settings.correo_contacto');

        $html = (string) $this->actingAs($admin)->get(route('admin.configuracion.index'))->assertOk()->getContent();
        $this->assertStringContainsString('No se guardó la configuración', $html);
        $this->assertStringContainsString('admin-error', $html);
        $this->assertSame(0, AuditLog::where('action', 'settings_updated')->count());
    }

    public function test_la_auditoria_registra_antes_y_despues_y_omite_guardados_sin_cambios(): void
    {
        $admin = $this->adminUser();
        SystemSetting::create(['key' => 'telefono', 'value' => '+51 900 000 000']);

        // Mismos valores efectivos (el teléfono guardado y la moneda predeterminada): no hay cambio.
        $this->actingAs($admin)->put(route('admin.configuracion.update'), [
            'settings' => ['telefono' => '+51 900 000 000', 'moneda' => 'S/'],
        ])->assertSessionHas('success', 'No hubo cambios en la configuración.');
        $this->assertSame(0, AuditLog::where('action', 'settings_updated')->count());

        $this->actingAs($admin)->put(route('admin.configuracion.update'), [
            'settings' => ['telefono' => '+51 911 111 111', 'moneda' => 'S/'],
        ])->assertSessionHas('success', 'La configuración del sistema se guardó correctamente.');

        $registro = AuditLog::where('action', 'settings_updated')->sole();
        $this->assertSame([
            'antes' => ['telefono' => '+51 900 000 000'],
            'despues' => ['telefono' => '+51 911 111 111'],
        ], $registro->changes);
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

        // La página de respaldo usa el nombre (title, topbar, menú) varias veces.
        $this->actingAs($admin)->get(route('admin.respaldo.index'))->assertOk();

        $this->assertSame(1, $consultas);
    }
}
