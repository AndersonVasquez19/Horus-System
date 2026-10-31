<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CotizacionOrigenMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = 'database/migrations/2026_10_29_000029_add_origen_to_solicitudes_cotizacion.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->assertSame('sqlite', DB::getDriverName());
    }

    private function migracion(): object
    {
        return require base_path(self::RUTA);
    }

    /** @return array<string, mixed> */
    private function fila(array $extra = []): array
    {
        $uuid = (string) Str::uuid();

        return array_merge([
            'identificador' => $uuid, 'codigo' => 'SOL-'.strtoupper($uuid), 'recibida_at' => now(),
            'necesidad_cliente' => 'Necesidad sintetica', 'contacto_nombre' => 'Contacto sintetico',
            'contacto_email' => 'ficticio@example.test', 'estado' => 'recibida', 'revision' => 1,
            'creador_ambito' => 1, 'creacion_clave_hash' => hash('sha256', $uuid), 'creacion_huella' => hash('sha256', 'h'.$uuid),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra);
    }

    /** @return list<string> */
    private function columnas(string $tabla): array
    {
        return array_map(fn (array $c): string => $c['name'], Schema::getColumns($tabla));
    }

    public function test_la_columna_es_obligatoria_con_defecto_administrativo(): void
    {
        $columna = collect(Schema::getColumns('solicitudes_cotizacion'))->firstWhere('name', 'origen');
        $this->assertNotNull($columna);
        $this->assertFalse($columna['nullable']);
        $this->assertStringContainsString('administrativo', (string) $columna['default']);
    }

    public function test_una_fila_sin_origen_queda_como_administrativa(): void
    {
        DB::table('solicitudes_cotizacion')->insert($this->fila());

        $this->assertSame('administrativo', DB::table('solicitudes_cotizacion')->value('origen'));
    }

    public function test_el_alta_del_panel_existente_queda_como_administrativa_sin_exponer_el_origen(): void
    {
        $admin = $this->adminUser();
        $respuesta = $this->actingAs($admin)->postJson('/admin/cotizaciones', [
            'contacto_nombre' => 'Contacto sintetico', 'contacto_email' => 'ficticio@example.test', 'necesidad_cliente' => 'Necesidad sintetica',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $respuesta->assertCreated();
        $this->assertSame('administrativo', DB::table('solicitudes_cotizacion')->value('origen'));
        $uuid = $respuesta->json('solicitud.identificador');
        $this->actingAs($admin)->getJson('/admin/cotizaciones/'.$uuid)->assertOk()->assertJsonMissingPath('solicitud.origen');
        $this->actingAs($admin)->getJson('/admin/cotizaciones')->assertOk()->assertJsonMissingPath('data.0.origen');
    }

    public function test_acepta_publico_y_rechaza_cualquier_otro_valor(): void
    {
        DB::table('solicitudes_cotizacion')->insert($this->fila(['origen' => 'publico']));
        $this->assertSame('publico', DB::table('solicitudes_cotizacion')->value('origen'));

        foreach (['otro', '', 'PUBLICO'] as $invalido) {
            try {
                DB::table('solicitudes_cotizacion')->insert($this->fila(['origen' => $invalido]));
                $this->fail("Se aceptó el origen '$invalido'.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(QueryException::class);
        DB::table('solicitudes_cotizacion')->update(['origen' => 'otro']);
    }

    public function test_las_restricciones_de_000027_siguen_vigentes(): void
    {
        DB::table('solicitudes_cotizacion')->insert($this->fila(['creador_ambito' => 7, 'creacion_clave_hash' => str_repeat('a', 64)]));

        // Contacto mínimo (trigger/CHECK de 000027).
        try {
            DB::table('solicitudes_cotizacion')->insert($this->fila(['contacto_email' => null, 'contacto_telefono' => null]));
            $this->fail('Se aceptó una solicitud sin contacto.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        // Unicidad de ámbito + clave de creación.
        try {
            DB::table('solicitudes_cotizacion')->insert($this->fila(['creador_ambito' => 7, 'creacion_clave_hash' => str_repeat('a', 64)]));
            $this->fail('Se aceptó una clave de creación duplicada.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        // Snapshot de servicio incompleto.
        $this->expectException(QueryException::class);
        DB::table('solicitudes_cotizacion')->insert($this->fila(['servicio_uuid' => (string) Str::uuid()]));
    }

    public function test_hacia_atras_y_hacia_adelante_conserva_datos_y_estructura(): void
    {
        $original = $this->columnas('solicitudes_cotizacion');
        $this->assertContains('origen', $original);
        $migracion = $this->migracion();

        $migracion->down();
        $sinOrigen = $this->columnas('solicitudes_cotizacion');
        $this->assertSame(array_values(array_diff($original, ['origen'])), $sinOrigen);

        // Fila heredada, anterior a la columna.
        DB::table('solicitudes_cotizacion')->insert($this->fila());
        $antes = (array) DB::table('solicitudes_cotizacion')->first();

        $migracion->up();
        $migracion->up(); // repetible
        $this->assertSame($original, $this->columnas('solicitudes_cotizacion'));
        $despues = (array) DB::table('solicitudes_cotizacion')->first();
        $this->assertSame('administrativo', $despues['origen']);
        unset($despues['origen']);
        $this->assertEquals($antes, $despues);

        // Las restricciones previas sobreviven a down/up.
        $this->expectException(QueryException::class);
        DB::table('solicitudes_cotizacion')->insert($this->fila(['contacto_email' => null, 'contacto_telefono' => null]));
    }

    public function test_no_altera_historial_ni_servicios_ni_restringe_la_relacion_existente(): void
    {
        $historial = $this->columnas('cotizacion_historial');
        $this->migracion()->down();
        $this->migracion()->up();

        $this->assertSame($historial, $this->columnas('cotizacion_historial'));
        $this->assertNotContains('origen', $this->columnas('cotizacion_historial'));
        $this->assertNotContains('origen', $this->columnas('servicios_tecnologicos'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
