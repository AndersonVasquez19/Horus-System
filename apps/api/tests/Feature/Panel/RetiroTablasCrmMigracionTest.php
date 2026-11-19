<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Migración 000079: el CRM comercial (retirado el 18/11/2026) deja de tener tablas; `secuencias_codigo` se conserva. */
final class RetiroTablasCrmMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS = [
        'ticket_notas', 'tickets_soporte', 'proyecto_hitos', 'proyectos', 'oportunidad_historial', 'oportunidades',
        'prospectos', 'cliente_contactos', 'clientes',
    ];

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_19_000079_drop_crm_tables.php');
    }

    public function test_una_instalacion_nueva_no_tiene_tablas_del_crm_pero_conserva_las_secuencias(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), $tabla);
        }
        $this->assertTrue(Schema::hasTable('secuencias_codigo'), 'La usan los códigos de las ediciones.');
        $this->assertTrue(Schema::hasTable('solicitudes_cotizacion'));
    }

    public function test_los_permisos_del_crm_se_conservan(): void
    {
        foreach (RolesAndPermissionsSeeder::CLIENTES_PERMISSIONS as $permiso) {
            $this->assertDatabaseHas('permissions', ['name' => $permiso, 'guard_name' => 'web']);
        }
    }

    public function test_repetirla_no_falla_ni_cambia_nada(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();

        $this->assertTrue(Schema::hasTable('secuencias_codigo'));
    }

    public function test_se_detiene_sin_borrar_nada_si_una_tabla_del_crm_tiene_datos(): void
    {
        Schema::create('clientes', function ($tabla): void {
            $tabla->id();
        });
        Schema::create('prospectos', function ($tabla): void {
            $tabla->id();
        });
        DB::table('clientes')->insert(['id' => 1]);

        try {
            $this->migracion()->up();
            $this->fail('Debió negarse a borrar una tabla con datos.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('clientes', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('clientes'));
        $this->assertTrue(Schema::hasTable('prospectos'), 'Ninguna tabla se elimina si otra tiene datos.');
        $this->assertSame(1, DB::table('clientes')->count());
    }

    public function test_revertirla_no_falla_ni_recrea_el_crm(): void
    {
        $this->migracion()->down();

        foreach (self::TABLAS as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), $tabla);
        }
        $this->assertTrue(Schema::hasTable('secuencias_codigo'));
    }
}
