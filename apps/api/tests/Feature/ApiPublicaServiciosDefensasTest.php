<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\User;
use App\Services\CatalogoPublicoServicios;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Support\LlamaServiciosDelPanel;
use Tests\TestCase;

/**
 * Defensas del catálogo público de servicios en `GET /api/public/v1/servicios`: qué fichas se excluyen, cómo falla sin
 * exponer SQL ni datos internos y cómo pagina. Sustituye a las pruebas que ejercitaban la página Blade
 * `/tecnologias/soporte-mantenimiento` (retirada en la Fase 1 del retiro de Blade); la regla es la misma.
 */
class ApiPublicaServiciosDefensasTest extends TestCase
{
    use LlamaServiciosDelPanel;
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'America/Lima'));
        config(['horus-web.service_token' => self::TOKEN, 'catalogo-servicios.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function servicios(string $consulta = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/public/v1/servicios'.$consulta, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    /** @return array{0: User, 1: ServicioTecnologico, 2: ServicioPublicacion} */
    private function ficha(?User $autor = null, string $titulo = 'Servicio autorizado ficticio'): array
    {
        $autor ??= $this->adminUser();
        $servicio = ServicioTecnologico::forceCreate(['codigo' => Str::uuid()->toString(), 'identificador_publico' => Str::uuid()->toString(),
            'nombre' => 'NOMBRE INTERNO SECRETO', 'categoria' => 'CATEGORIA INTERNA', 'estado' => 'activo', 'revision' => 1]);
        $ficha = ServicioPublicacion::forceCreate(['servicio_tecnologico_id' => $servicio->id, 'titulo' => $titulo,
            'resumen' => 'Resumen público ficticio', 'descripcion' => 'Descripción autorizada de ejemplo', 'categoria' => null,
            'estado' => 'publicada', 'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-11-30',
            'autorizado_por' => $autor->id, 'autorizado_at' => now()]);

        return [$autor, $servicio, $ficha];
    }

    public function test_apagado_no_consulta_el_catalogo_ni_la_base(): void
    {
        $this->assertFalse(config('catalogo-servicios.enabled'));
        $this->mock(CatalogoPublicoServicios::class)->shouldNotReceive('pagina');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->servicios()->assertOk()->assertExactJson(['habilitado' => false, 'total' => 0, 'pagina' => 1, 'por_pagina' => 12, 'servicios' => []]);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_habilitado_proyecta_solo_textos_publicos_y_no_escribe(): void
    {
        [, $servicio] = $this->ficha();
        config(['catalogo-servicios.enabled' => true]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $r = $this->servicios()->assertOk();
        $r->assertJsonPath('servicios.0.titulo', 'Servicio autorizado ficticio')->assertJsonPath('servicios.0.descripcion', 'Descripción autorizada de ejemplo');
        $texto = (string) $r->getContent();
        // El identificador público es parte del contrato (lo usa Next como clave); el código interno y la autoría no.
        $r->assertJsonPath('servicios.0.identificador_publico', $servicio->identificador_publico);
        foreach (['NOMBRE INTERNO SECRETO', 'CATEGORIA INTERNA', $servicio->codigo, 'autorizado_por', 'servicio_tecnologico_id'] as $privado) {
            $this->assertStringNotContainsString($privado, $texto);
        }
        foreach (DB::getQueryLog() as $consulta) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $consulta['query']);
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function excluidas(): iterable
    {
        foreach (['borrador', 'retirada', 'caducada', 'futura', 'inactivo', 'archivado', 'sin_autor', 'sin_permiso', 'sin_rol', 'autorizacion_futura'] as $caso) {
            yield $caso => [$caso];
        }
    }

    #[DataProvider('excluidas')]
    public function test_estados_no_publicos_excluidos(string $caso): void
    {
        [$autor, $servicio, $ficha] = $this->ficha();
        match ($caso) {
            'borrador', 'retirada' => $ficha->forceFill(['estado' => $caso])->save(),
            'caducada' => $ficha->update(['vigente_hasta' => '2026-10-28', 'vigente_desde' => '2026-10-01']),
            'futura' => $ficha->update(['vigente_desde' => '2026-10-30']),
            'inactivo', 'archivado' => $servicio->update(['estado' => $caso]),
            'sin_autor' => $ficha->forceFill(['autorizado_por' => null])->save(),
            'sin_permiso' => $autor->roles->first()->revokePermissionTo('servicios.publicar'),
            'sin_rol' => $autor->removeRole('admin'),
            'autorizacion_futura' => $ficha->forceFill(['autorizado_at' => now()->addDay()])->save(),
        };
        config(['catalogo-servicios.enabled' => true]);

        $this->servicios()->assertOk()->assertJsonPath('servicios', [])->assertJsonPath('total', 0);
    }

    public function test_vacio_no_inventa_fichas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['catalogo-servicios.enabled' => true]);

        $this->servicios()->assertOk()->assertJsonPath('servicios', [])->assertJsonPath('habilitado', true);
    }

    public function test_el_texto_llega_tal_cual_para_que_lo_escape_el_cliente_y_no_se_derivan_enlaces(): void
    {
        [, , $ficha] = $this->ficha();
        $ficha->update(['titulo' => '<script>alert(1)</script>', 'resumen' => '<img src=x onerror=alert(2)>',
            'descripcion' => 'javascript:alert(3)', 'categoria' => '<b>Ejemplo</b>']);
        config(['catalogo-servicios.enabled' => true]);

        $r = $this->servicios()->assertOk();
        $r->assertJsonPath('servicios.0.titulo', '<script>alert(1)</script>')->assertJsonPath('servicios.0.categoria', '<b>Ejemplo</b>');
        $this->assertArrayNotHasKey('url', $r->json('servicios.0'));
        $this->assertArrayNotHasKey('enlace', $r->json('servicios.0'));
    }

    public function test_esquema_ausente_habilitado_falla_controlado(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        Schema::dropIfExists('servicio_publicacion_elementos');
        Schema::drop('servicio_publicaciones');
        Log::shouldReceive('warning')->once()->with('servicios_web_esquema_incompleto');

        $this->servicios()->assertStatus(503)->assertJsonPath('error.code', 'catalogo_no_disponible')
            ->assertDontSee('servicio_publicaciones')->assertDontSee('SQLSTATE');
    }

    public function test_roles_sin_provision_fallan_controlados_sin_sembrar_desde_lectura(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('servicios_web_provision_incompleta');

        $this->servicios()->assertStatus(503)->assertJsonPath('error.code', 'catalogo_no_disponible');
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_permiso_ausente_es_infraestructura_incompleta_sin_autoprovision(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findByName('servicios.publicar')->delete();
        config(['catalogo-servicios.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('servicios_web_provision_incompleta');

        $this->servicios()->assertStatus(503);
        $this->assertDatabaseMissing('permissions', ['name' => 'servicios.publicar']);
    }

    public function test_columnas_incompletas_fallan_sin_registrar_sql(): void
    {
        $this->adminUser();
        config(['catalogo-servicios.enabled' => true]);
        Schema::table('servicio_publicaciones', fn ($tabla) => $tabla->dropColumn('descripcion'));
        Log::shouldReceive('warning')->once()->with('servicios_web_lectura_fallida');

        $this->servicios()->assertStatus(503)->assertDontSee('SQLSTATE');
    }

    public function test_errores_sql_solo_codigo_seguro(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $this->mock(CatalogoPublicoServicios::class)->shouldReceive('pagina')->once()
            ->andThrow(new QueryException('sqlite', 'SQL SECRETO', ['DATO PRIVADO'], new \RuntimeException('credencial')));
        Log::shouldReceive('warning')->once()->with('servicios_web_lectura_fallida');

        $this->servicios()->assertStatus(503)->assertDontSee('SECRETO')->assertDontSee('PRIVADO')->assertDontSee('credencial');
    }

    public function test_errores_de_programacion_no_se_ocultan(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $this->withoutExceptionHandling();
        $this->mock(CatalogoPublicoServicios::class)->shouldReceive('pagina')->andThrow(new \LogicException('fallo de contrato ficticio'));
        $this->expectException(\LogicException::class);

        $this->servicios();
    }

    public function test_fichas_heredadas_invalidas_se_omiten_sin_rellenar_fuera_de_pagina(): void
    {
        [, , $ficha] = $this->ficha();
        $ficha->update(['resumen' => '   ']);
        config(['catalogo-servicios.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('servicios_web_ficha_invalida');

        $this->servicios()->assertOk()->assertJsonPath('servicios', []);
    }

    public function test_paginacion_de_12_en_12_con_orden_estable_y_consultas_constantes(): void
    {
        [$autor] = $this->ficha(null, 'Ficha 01');
        config(['catalogo-servicios.enabled' => true]);
        $this->servicios()->assertOk(); // Calentar la caché de roles y permisos.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->servicios()->assertOk();
        $consultasUna = count(DB::getQueryLog());
        for ($i = 2; $i <= 25; $i++) {
            $this->ficha($autor, sprintf('Ficha %02d', $i));
        }
        DB::flushQueryLog();

        $primera = $this->servicios()->assertOk()->assertJsonPath('total', 25)->assertJsonPath('por_pagina', 12);
        $this->assertSame(12, count($primera->json('servicios')));
        $this->assertSame('Ficha 01', $primera->json('servicios.0.titulo'));
        $this->assertSame('Ficha 12', $primera->json('servicios.11.titulo'));
        $this->assertSame($consultasUna, count(DB::getQueryLog()), 'Las consultas no crecen con el número de fichas.');
        $filas = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"p"."titulo"') && ! str_contains($q['query'], '"s"."codigo" = '));
        $this->assertCount(1, $filas);
        $this->assertStringContainsString('limit 12 offset 0', array_values($filas)[0]['query']);

        $this->assertSame('Ficha 13', $this->servicios('?pagina=2&secreto=NO_PROPAGAR')->assertOk()->json('servicios.0.titulo'));
        $this->assertSame(['Ficha 25'], array_column($this->servicios('?pagina=3')->assertOk()->json('servicios'), 'titulo'));
        $this->servicios('?pagina=999')->assertOk()->assertJsonPath('servicios', []);
    }

    public function test_pagina_malformada_rechazada_sin_sql_del_catalogo(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $this->mock(CatalogoPublicoServicios::class)->shouldNotReceive('pagina');
        foreach (['0', '-1', '1000001', 'abc'] as $valor) {
            $this->servicios('?pagina='.$valor)->assertUnprocessable();
        }
        $this->servicios('?pagina[]=1')->assertUnprocessable();
    }

    public function test_retiro_revalidado_en_cada_lectura(): void
    {
        [, , $ficha] = $this->ficha();
        config(['catalogo-servicios.enabled' => true]);
        $this->servicios()->assertJsonPath('servicios.0.titulo', 'Servicio autorizado ficticio');
        $ficha->forceFill(['estado' => 'retirada'])->save();

        $this->servicios()->assertJsonPath('servicios', []);
    }

    public function test_gestion_publicacion_y_retiro_se_reflejan_en_la_api_sin_cache(): void
    {
        $autor = $this->adminUser();
        config(['catalogo-servicios.enabled' => true]);
        $this->actingAs($autor);
        $id = $this->crearServicioPanel(['codigo' => 'FLUJO-FICTICIO', 'nombre' => 'Nombre interno de prueba', 'estado' => 'activo']);
        $this->guardarFichaPanel($id, ['titulo' => 'Servicio del flujo de prueba',
            'resumen' => 'Información sintética', 'descripcion' => 'Detalle público sintético',
            'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-11-01'])->assertOk();
        auth()->forgetGuards();
        $this->servicios()->assertOk()->assertJsonPath('servicios', []);
        $this->actingAs($autor);
        $this->autorizarFichaPanel($id)->assertOk();
        auth()->forgetGuards();
        $this->servicios()->assertOk()->assertJsonPath('servicios.0.titulo', 'Servicio del flujo de prueba');
        $this->actingAs($autor);
        $this->retirarFichaPanel($id)->assertOk();
        auth()->forgetGuards();
        $this->servicios()->assertOk()->assertJsonPath('servicios', []);
        $this->assertDatabaseCount('audit_logs', 4);
    }
}
