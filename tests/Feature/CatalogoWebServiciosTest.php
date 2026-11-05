<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ServiciosPublicosController;
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
use Tests\TestCase;

class CatalogoWebServiciosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite :memory: fuera del perfil; conexión protegida de la lista fija dentro de PostgreSQL Testing.
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'America/Lima'));
        Http::preventStrayRequests();
    }

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

    public function test_ruta_original_flag_apagado_y_html_original_sin_consultas(): void
    {
        $this->assertFalse(config('catalogo-servicios.enabled'));
        $ruta = app('router')->getRoutes()->getByName('tecnologias.soporte');
        $this->assertSame(['GET', 'HEAD'], $ruta->methods());
        $this->assertSame(ServiciosPublicosController::class, $ruta->getActionName());
        $this->assertSame(url('/tecnologias/soporte-mantenimiento'), route('tecnologias.soporte'));
        Schema::drop('servicio_publicaciones');
        Schema::drop('cotizacion_historial');
        Schema::drop('solicitudes_cotizacion');
        Schema::drop('servicios_tecnologicos');
        $this->mock(CatalogoPublicoServicios::class)->shouldNotReceive('pagina');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $respuesta = $this->get(route('tecnologias.soporte', ['servicios_pagina' => ['invalida']]));
        $respuesta->assertOk()->assertSee('MANTENIMIENTO PREVENTIVO')->assertDontSee('id="catalogo-servicios"', false);
        $this->assertSame([], DB::getQueryLog());
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
        $original = view('pages.tecnologias.soporte-mantenimiento')->render();
        $this->assertSame($original, $respuesta->getContent());
        Http::assertNothingSent();
    }

    public function test_habilitado_proyecta_solo_textos_publicos_y_no_escribe(): void
    {
        [, $servicio] = $this->ficha();
        config(['catalogo-servicios.enabled' => true]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('Servicio autorizado ficticio')
            ->assertSee('Descripción autorizada de ejemplo')->assertSee(route('contactos'))->assertSee('Consultar servicio')
            ->assertDontSee('NOMBRE INTERNO SECRETO')->assertDontSee('CATEGORIA INTERNA')
            ->assertDontSee($servicio->codigo)->assertDontSee($servicio->identificador_publico)
            ->assertDontSee('autorizado_por')->assertDontSee('servicio_tecnologico_id');
        foreach (DB::getQueryLog() as $consulta) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $consulta['query']);
        }
        $this->assertDatabaseCount('audit_logs', 0);
        Http::assertNothingSent();
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
        $this->get(route('tecnologias.soporte'))->assertOk()->assertDontSee('Servicio autorizado ficticio')
            ->assertSee('No hay fichas de servicios publicadas vigentes.');
    }

    public function test_vacio_no_inventa_fichas_y_mantiene_tarjetas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['catalogo-servicios.enabled' => true]);
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('No hay fichas de servicios publicadas vigentes.')
            ->assertSee('CONFIGURACIÓN E INSTALACIÓN')->assertSee('ASESORÍA Y CAPACITACIÓN')->assertSee('Contactar con Horus Group');
    }

    public function test_textos_html_escapados_categoria_opcional_y_sin_enlaces_derivados(): void
    {
        [, , $ficha] = $this->ficha();
        $ficha->update(['titulo' => '<script>alert(1)</script>', 'resumen' => '<img src=x onerror=alert(2)>',
            'descripcion' => 'javascript:alert(3)', 'categoria' => '<b>Ejemplo</b>']);
        config(['catalogo-servicios.enabled' => true]);
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('<script>alert(1)</script>')
            ->assertSee('<img src=x onerror=alert(2)>')->assertSee('<b>Ejemplo</b>')
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('href="javascript:', false);
        $ficha->update(['categoria' => null]);
        $this->get(route('tecnologias.soporte'))->assertOk()->assertDontSee('Categoría:');
    }

    public function test_esquema_ausente_habilitado_falla_controlado(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        Schema::drop('servicio_publicaciones');
        Log::shouldReceive('warning')->once()->with('servicios_web_esquema_incompleto');
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('No podemos mostrar los servicios publicados')
            ->assertSee('MANTENIMIENTO PREVENTIVO')->assertDontSee('servicio_publicaciones')->assertDontSee('SQLSTATE');
    }

    public function test_roles_sin_provision_fallan_controlados_sin_sembrar_desde_lectura(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('servicios_web_provision_incompleta');
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('No podemos mostrar los servicios publicados');
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_permiso_ausente_es_infraestructura_incompleta_sin_autoprovision(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findByName('servicios.publicar')->delete();
        config(['catalogo-servicios.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('servicios_web_provision_incompleta');
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('No podemos mostrar los servicios publicados');
        $this->assertDatabaseMissing('permissions', ['name' => 'servicios.publicar']);
    }

    public function test_columnas_incompletas_fallan_sin_registrar_sql(): void
    {
        $this->adminUser();
        config(['catalogo-servicios.enabled' => true]);
        Schema::table('servicio_publicaciones', fn ($tabla) => $tabla->dropColumn('descripcion'));
        Log::shouldReceive('warning')->once()->with('servicios_web_lectura_fallida');
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('No podemos mostrar los servicios publicados')->assertDontSee('SQLSTATE');
    }

    public function test_errores_sql_solo_codigo_seguro(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $this->mock(CatalogoPublicoServicios::class)->shouldReceive('pagina')->once()
            ->andThrow(new QueryException('sqlite', 'SQL SECRETO', ['DATO PRIVADO'], new \RuntimeException('credencial')));
        Log::shouldReceive('warning')->once()->with('servicios_web_lectura_fallida');
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('No podemos mostrar los servicios publicados')
            ->assertDontSee('SECRETO')->assertDontSee('PRIVADO')->assertDontSee('credencial');
    }

    public function test_errores_de_programacion_no_se_ocultan(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $this->withoutExceptionHandling();
        $this->mock(CatalogoPublicoServicios::class)->shouldReceive('pagina')->andThrow(new \LogicException('fallo de contrato ficticio'));
        $this->expectException(\LogicException::class);
        $this->get(route('tecnologias.soporte'));
    }

    public function test_fichas_heredadas_invalidas_se_omiten_sin_rellenar_fuera_de_pagina(): void
    {
        [, , $ficha] = $this->ficha();
        $ficha->update(['resumen' => '   ']);
        config(['catalogo-servicios.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('servicios_web_ficha_invalida');
        $this->get(route('tecnologias.soporte'))->assertOk()->assertDontSee('Servicio autorizado ficticio')
            ->assertSee('Fichas presentadas: 0')->assertSee('No hay fichas disponibles en esta página.');
    }

    public function test_paginacion_sql_orden_estable_consultas_constantes_y_limite(): void
    {
        [$autor] = $this->ficha(null, 'Ficha 01');
        config(['catalogo-servicios.enabled' => true]);
        $this->get(route('tecnologias.soporte'))->assertOk(); // Calentar caché de roles/permisos en ambos tamaños.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('tecnologias.soporte'))->assertOk();
        $consultasUna = count(DB::getQueryLog());
        for ($i = 2; $i <= 25; $i++) {
            $this->ficha($autor, sprintf('Ficha %02d', $i));
        }
        DB::flushQueryLog();
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('Ficha 01')->assertSee('Ficha 12')
            ->assertDontSee('Ficha 13')->assertSee('Página 1 de 3')->assertSee('Total de fichas autorizadas vigentes: 25')
            ->assertSee('servicios_pagina=2')->assertSee('Rango consultado: 1–12');
        $this->assertSame($consultasUna, count(DB::getQueryLog()));
        $filas = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"p"."titulo"') && ! str_contains($q['query'], '"s"."codigo" = '));  // Excluye la lectura del contenido propio de la página.
        $this->assertCount(1, $filas);
        $this->assertStringContainsString('limit 12 offset 0', array_values($filas)[0]['query']);
        $this->get(route('tecnologias.soporte', ['servicios_pagina' => 2, 'secreto' => 'NO_PROPAGAR']))->assertOk()
            ->assertSee('Ficha 13')->assertSee('Ficha 24')->assertDontSee('Ficha 12')->assertDontSee('Ficha 25')
            ->assertSee('servicios_pagina=1')->assertSee('servicios_pagina=3')->assertDontSee('NO_PROPAGAR');
        $this->get(route('tecnologias.soporte', ['servicios_pagina' => 3]))->assertOk()->assertSee('Ficha 25')->assertDontSee('Página siguiente');
        $this->get(route('tecnologias.soporte', ['servicios_pagina' => 999]))->assertOk()->assertSee('No hay fichas disponibles en esta página.');
    }

    public function test_pagina_malformada_rechazada_sin_sql_del_catalogo(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $this->mock(CatalogoPublicoServicios::class)->shouldNotReceive('pagina');
        foreach (['0', '-1', '1000001', 'abc', ['1']] as $valor) {
            $this->getJson(route('tecnologias.soporte', ['servicios_pagina' => $valor]))->assertUnprocessable();
        }
    }

    public function test_retiro_revalidado_y_otras_paginas_no_consultan_catalogo(): void
    {
        [, , $ficha] = $this->ficha();
        config(['catalogo-servicios.enabled' => true]);
        $this->get(route('tecnologias.soporte'))->assertSee('Servicio autorizado ficticio');
        $ficha->forceFill(['estado' => 'retirada'])->save();
        $this->get(route('tecnologias.soporte'))->assertDontSee('Servicio autorizado ficticio');
        $this->mock(CatalogoPublicoServicios::class)->shouldNotReceive('pagina');
        foreach (['tecnologias.cableado', 'tecnologias.camaras', 'educacion.cursos'] as $ruta) {
            $this->get(route($ruta))->assertOk()->assertDontSee('id="catalogo-servicios"', false);
        }
    }

    public function test_gestion_publicacion_y_retiro_se_reflejan_en_web_sin_cache(): void
    {
        $autor = $this->adminUser();
        config(['catalogo-servicios.enabled' => true]);
        $this->actingAs($autor);
        $creado = $this->postJson('/admin/servicios', ['codigo' => 'FLUJO-FICTICIO', 'nombre' => 'Nombre interno de prueba', 'estado' => 'activo'])->assertCreated();
        $id = $creado->json('servicio.id');
        $borrador = $this->putJson("/admin/servicios/$id/publicacion", ['titulo' => 'Servicio del flujo de prueba',
            'resumen' => 'Información sintética', 'descripcion' => 'Detalle público sintético',
            'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-11-01'], ['If-Match' => $creado->headers->get('ETag')])->assertOk();
        auth()->forgetGuards();
        $this->get(route('tecnologias.soporte'))->assertOk()->assertDontSee('Servicio del flujo de prueba');
        $publicada = $this->actingAs($autor)->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $borrador->headers->get('ETag')])->assertOk();
        auth()->forgetGuards();
        $this->get(route('tecnologias.soporte'))->assertOk()->assertSee('Servicio del flujo de prueba');
        $this->actingAs($autor)->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $publicada->headers->get('ETag')])->assertOk();
        auth()->forgetGuards();
        $this->get(route('tecnologias.soporte'))->assertOk()->assertDontSee('Servicio del flujo de prueba');
        $this->assertDatabaseCount('audit_logs', 4);
    }
}
