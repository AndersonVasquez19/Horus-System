<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ServicioPublicacion;
use App\Models\ServicioPublicacionElemento;
use App\Models\ServicioTecnologico;
use App\Models\User;
use App\Services\CatalogoPublicoServicios;
use App\Services\ContenidoPublicoServicios;
use App\Services\ImagenesServicios;
use App\Support\PaginasServicios;
use Carbon\Carbon;
use Database\Seeders\ServiciosTecnologicosContenidoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\FichasDeServiciosParaPruebas;
use Tests\TestCase;

/**
 * Contenido de las tres páginas de Servicios Tecnológicos (SQLite :memory:; sin PostgreSQL ni red). El panel de servicios y su
 * API de edición se retiraron el 19/11/2026: las fichas se preparan directo en la base (`FichasDeServiciosParaPruebas`) como lo
 * haría el seeder, y aquí se prueba lo que entrega el catálogo público.
 */
class ServiciosContenidoTest extends TestCase
{
    use FichasDeServiciosParaPruebas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'America/Lima'));
        Http::preventStrayRequests();
        Storage::fake(ImagenesServicios::DISCO);
        $this->actingAs($this->adminUser());
    }

    private function etag(int $id): string
    {
        return $this->revisionServicioPanel($id);
    }

    private function servicio(string $codigo = 'CABLEADO', bool $conFicha = true): int
    {
        $id = $this->crearServicioPanel(['codigo' => $codigo, 'nombre' => 'Servicio '.$codigo, 'categoria' => $codigo]);
        if ($conFicha) {
            $this->guardarFichaPanel($id, [
                'titulo' => 'Título principal '.$codigo, 'resumen' => 'Resumen público', 'descripcion' => "Primer párrafo.\n\nSegundo párrafo.",
                'vigente_desde' => '2026-11-01', 'vigente_hasta' => '2026-12-31', 'titulo_tarjetas' => 'Sección de tarjetas',
            ]);
        }

        return $id;
    }

    private function activarYPublicar(int $id): void
    {
        $this->cambiarEstadoServicioPanel($id, 'activo');
        $this->autorizarFichaPanel($id);
    }

    private function retirar(int $id): void
    {
        $this->retirarFichaPanel($id);
    }

    /** Ruta de almacenamiento guardada (el panel no la publica). */
    private function rutaDeTarjeta(?int $elemento = null): ?string
    {
        $consulta = DB::table('servicio_publicacion_elementos');
        if ($elemento !== null) {
            $consulta->where('id', $elemento);
        }

        return $consulta->value('imagen_ruta');
    }

    // ---------------------------------------------------------------- Permisos y roles

    #[DataProvider('ordenesFueraDeRango')]
    public function test_la_base_rechaza_orden_fuera_de_rango_en_insert_y_update(int $orden): void
    {
        $id = $this->servicio();
        $publicacion = ServicioPublicacion::query()->where('servicio_tecnologico_id', $id)->firstOrFail();
        $fila = ['servicio_publicacion_id' => $publicacion->id, 'titulo' => 'Elemento',
            'descripcion' => 'Descripción', 'orden' => 0, 'activo' => true,
            'created_at' => now(), 'updated_at' => now()];
        $elemento = DB::table('servicio_publicacion_elementos')->insertGetId($fila);
        foreach ([
            fn () => DB::table('servicio_publicacion_elementos')->insert(array_replace($fila, ['orden' => $orden])),
            fn () => DB::table('servicio_publicacion_elementos')->where('id', $elemento)->update(['orden' => $orden]),
        ] as $operacion) {
            try {
                DB::transaction($operacion);
                $this->fail('La base aceptó un orden fuera del rango.');
            } catch (QueryException $error) {
                $this->assertSame(DB::getDriverName() === 'pgsql' ? '23514' : '23000', $error->errorInfo[0]);
                $this->assertStringContainsString('servicio_elementos_orden_rango', $error->getMessage());
            }
        }
        $this->assertSame(0, (int) DB::table('servicio_publicacion_elementos')->where('id', $elemento)->value('orden'));
        $this->assertSame(1, DB::table('servicio_publicacion_elementos')->where('servicio_publicacion_id', $publicacion->id)->count());
    }

    /** @return iterable<string, array{int}> */
    public static function ordenesFueraDeRango(): iterable
    {
        yield 'negativo' => [-1];
        yield 'mayor_al_maximo' => [10000];
    }

    public function test_rutas_de_imagen_solo_aceptan_origenes_validos(): void
    {
        foreach (['../.env', 'servicios/../../x.png', '/etc/passwd', 'http://externo/x.png', 'storage/x.png', 'assets/images/../x.png', 'servicios/x.png'] as $ruta) {
            $this->assertFalse(ImagenesServicios::rutaValida($ruta), $ruta);
            $this->assertNull(ImagenesServicios::url($ruta));
        }
        $this->assertTrue(ImagenesServicios::rutaValida('assets/images/tecnologias/categoria1.jpg'));
        $this->assertSame(asset('assets/images/tecnologias/categoria1.jpg'), ImagenesServicios::url('assets/images/tecnologias/categoria1.jpg'));
    }

    // ---------------------------------------------------------------- Páginas públicas
    // Las páginas Blade `/tecnologias/*` se retiraron en la Fase 1 del retiro de Blade: el contenido administrable se entrega
    // por `GET /api/public/v1/paginas/{pagina}` y lo pinta apps/web. La regla de qué ficha se muestra es la misma.

    private const SLUGS = ['CABLEADO' => 'cableado-estructurado', 'VIDEOVIGILANCIA' => 'camaras-seguridad', 'SOPORTE' => 'soporte-mantenimiento'];

    private function paginaWeb(string $slug): TestResponse
    {
        $token = 'token-de-prueba-bff-0123456789abcdefghij';
        config(['horus-web.service_token' => $token]);

        return $this->getJson('/api/public/v1/paginas/'.$slug, ['X-Horus-Web-Token' => $token]);
    }

    /** Títulos de las tarjetas que entrega la API, o null cuando la página no tiene contenido dinámico. */
    private function tarjetasWeb(string $slug): ?array
    {
        $contenido = $this->paginaWeb($slug)->assertOk()->json('contenido');

        return $contenido === null ? null : array_column($contenido['tarjetas'], 'titulo');
    }

    public function test_las_tres_paginas_se_atienden_por_la_api_y_no_por_blade(): void
    {
        foreach (self::SLUGS as $slug) {
            $this->paginaWeb($slug)->assertOk()->assertJsonPath('pagina', $slug);
        }
        foreach (['tecnologias.cableado', 'tecnologias.camaras', 'tecnologias.soporte'] as $nombre) {
            $this->assertFalse(app('router')->getRoutes()->hasNamedRoute($nombre), $nombre);
        }
        $this->paginaWeb('market')->assertNotFound();
    }

    public function test_catalogo_apagado_no_entrega_contenido_dinamico_ni_consulta_la_base(): void
    {
        $this->assertFalse(config('catalogo-servicios.enabled'));
        $id = $this->servicio();
        $this->crearTarjetaPanel($id, ['titulo' => 'TARJETA DINAMICA']);
        $this->activarYPublicar($id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->paginaWeb('cableado-estructurado')->assertOk()->assertJsonPath('contenido', null)->assertDontSee('TARJETA DINAMICA');
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_pagina_dinamica_muestra_solo_activo_autorizado_y_vigente(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio();
        $this->crearTarjetaPanel($id, ['titulo' => 'SEGUNDA', 'orden' => 20]);
        $this->crearTarjetaPanel($id, ['titulo' => 'PRIMERA', 'orden' => 10]);
        $this->crearTarjetaPanel($id, ['titulo' => 'OCULTA', 'orden' => 5, 'activo' => '0']);

        // Borrador: nada.
        $this->assertNull($this->tarjetasWeb('cableado-estructurado'));

        $this->activarYPublicar($id);
        $this->paginaWeb('cableado-estructurado')->assertOk()->assertJsonPath('contenido.titulo', 'Título principal CABLEADO')
            ->assertJsonPath('contenido.titulo_tarjetas', 'Sección de tarjetas')
            ->assertJsonPath('contenido.parrafos', ['Primer párrafo.', 'Segundo párrafo.'])
            ->assertDontSee('OCULTA');
        $this->assertSame(['PRIMERA', 'SEGUNDA'], $this->tarjetasWeb('cableado-estructurado'), 'Debe respetar el orden configurado y omitir lo inactivo.');

        // Retirada: sin contenido dinámico; el borrador no se expone.
        $this->retirar($id);
        $this->assertNull($this->tarjetasWeb('cableado-estructurado'));
    }

    public function test_vigencia_servicio_inactivo_y_autor_sin_permiso(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio('VIDEOVIGILANCIA');
        $this->crearTarjetaPanel($id, ['titulo' => 'CAMARA NUEVA']);
        $this->activarYPublicar($id);
        $this->assertSame(['CAMARA NUEVA'], $this->tarjetasWeb('camaras-seguridad'));

        $this->travelTo(Carbon::parse('2027-01-05 12:00:00', 'America/Lima'));
        $this->assertNull($this->tarjetasWeb('camaras-seguridad'));
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'America/Lima'));
        $this->assertSame(['CAMARA NUEVA'], $this->tarjetasWeb('camaras-seguridad'));

        // Programada: aún no vigente.
        DB::table('servicio_publicaciones')->update(['vigente_desde' => '2026-11-03', 'vigente_hasta' => '2026-12-31']);
        $this->assertNull($this->tarjetasWeb('camaras-seguridad'));
        DB::table('servicio_publicaciones')->update(['vigente_desde' => '2026-11-01']);

        // Autor que perdió el permiso de publicar.
        $autor = User::find(ServicioPublicacion::firstOrFail()->autorizado_por);
        $autor->removeRole('admin');
        $this->assertNull($this->tarjetasWeb('camaras-seguridad'));
        $autor->assignRole('admin');
        $this->assertSame(['CAMARA NUEVA'], $this->tarjetasWeb('camaras-seguridad'));

        // Inactivar retira.
        $this->cambiarEstadoServicioPanel($id, 'inactivo');
        $this->assertNull($this->tarjetasWeb('camaras-seguridad'));
    }

    public function test_contenido_literal_para_que_lo_escape_el_cliente_y_sin_exponer_datos_administrativos(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio('SOPORTE');
        $this->crearTarjetaPanel($id, ['titulo' => 'Tarjeta & "comillas"', 'icono' => 'fas fa-cogs', 'caracteristicas' => ['Item & uno']]);
        DB::table('servicio_publicacion_elementos')->update(['descripcion' => '<script>alert(1)</script>', 'imagen_alt' => '"><img src=x>']);
        $this->activarYPublicar($id);
        $uuid = (string) DB::table('servicios_tecnologicos')->where('id', $id)->value('identificador_publico');

        $r = $this->paginaWeb('soporte-mantenimiento')->assertOk()
            ->assertJsonPath('contenido.tarjetas.0.titulo', 'Tarjeta & "comillas"')
            ->assertJsonPath('contenido.tarjetas.0.descripcion', '<script>alert(1)</script>')
            ->assertJsonPath('contenido.tarjetas.0.icono', 'fas fa-cogs');
        $texto = (string) $r->getContent();
        $this->assertStringNotContainsString($uuid, $texto);
        $this->assertStringNotContainsString('Servicio SOPORTE', $texto);
    }

    public function test_una_ficha_de_categoria_no_aparece_ademas_en_el_catalogo_generico(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio('SOPORTE');
        $this->activarYPublicar($id);
        $otro = $this->servicio('OTRO-SERVICIO');
        $this->activarYPublicar($otro);
        $titulos = array_column(app(CatalogoPublicoServicios::class)->pagina()['servicios'], 'titulo');
        $this->assertSame(['Título principal OTRO-SERVICIO'], $titulos);
    }

    public function test_pagina_mal_provisionada_no_entrega_contenido(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio();
        $this->activarYPublicar($id);
        DB::statement('DROP TABLE servicio_publicacion_elementos');
        $this->paginaWeb('cableado-estructurado')->assertOk()->assertJsonPath('contenido', null);
    }

    public function test_error_al_comprobar_esquema_no_entrega_contenido_ni_filtra_sql(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        Schema::shouldReceive('hasTable')->once()->with('servicio_publicaciones')
            ->andThrow(new QueryException('sqlite', 'consulta privada', [], new \RuntimeException('detalle privado')));
        Log::shouldReceive('warning')->once()->with('servicios_paginas_lectura_fallida');

        $this->withoutExceptionHandling();
        $this->paginaWeb('cableado-estructurado')->assertOk()->assertJsonPath('contenido', null)
            ->assertDontSee('consulta privada')->assertDontSee('detalle privado');
    }

    // ---------------------------------------------------------------- Contenido inicial

    public function test_semilla_es_idempotente_no_publica_y_no_pisa_ediciones(): void
    {
        // Un servicio ya existente con el mismo código se reutiliza (sin duplicados ni identificadores nuevos).
        $existente = $this->servicio('SOPORTE', false);
        $uuid = ServicioTecnologico::findOrFail($existente)->identificador_publico;

        $this->seed(ServiciosTecnologicosContenidoSeeder::class);
        $this->assertSame(3, ServicioTecnologico::count());
        $this->assertSame(3, ServicioPublicacion::count());
        $this->assertSame($uuid, ServicioTecnologico::findOrFail($existente)->identificador_publico);
        $this->assertSame(['borrador'], ServicioPublicacion::query()->pluck('estado')->unique()->values()->all());
        $this->assertSame(3, ServicioTecnologico::query()->whereIn('estado', ['inactivo'])->count());
        $cuenta = fn (string $codigo): int => DB::table('servicio_publicacion_elementos as e')->join('servicio_publicaciones as p', 'p.id', '=', 'e.servicio_publicacion_id')
            ->join('servicios_tecnologicos as s', 's.id', '=', 'p.servicio_tecnologico_id')->where('s.codigo', $codigo)->count();
        $this->assertSame([6, 10, 3], [$cuenta('CABLEADO'), $cuenta('VIDEOVIGILANCIA'), $cuenta('SOPORTE')]);
        $this->assertSame(0, DB::table('servicio_publicaciones')->whereNotNull('autorizado_por')->count());

        // Ediciones de Gerencia sobreviven a una segunda ejecución; no se duplica nada.
        DB::table('servicio_publicacion_elementos')->where('titulo', 'Categoría 1')->update(['titulo' => 'Editada por Gerencia']);
        $this->seed(ServiciosTecnologicosContenidoSeeder::class);
        $this->assertSame(19, ServicioPublicacionElemento::count());
        $this->assertSame(1, ServicioPublicacionElemento::where('titulo', 'Editada por Gerencia')->count());
        $this->assertSame(0, ServicioPublicacionElemento::where('titulo', 'Categoría 1')->count());
        $this->assertSame(3, AuditLog::where('action', 'servicio_contenido_inicial_importado')->count());
    }

    public function test_semilla_reutiliza_solo_imagenes_locales_existentes(): void
    {
        $this->seed(ServiciosTecnologicosContenidoSeeder::class);
        $rutas = array_merge(
            DB::table('servicio_publicaciones')->pluck('imagen_principal_ruta')->all(),
            DB::table('servicio_publicacion_elementos')->whereNotNull('imagen_ruta')->pluck('imagen_ruta')->all(),
        );
        $this->assertCount(3 + 6 + 10, $rutas);
        foreach ($rutas as $ruta) {
            $this->assertTrue(ImagenesServicios::rutaValida($ruta), $ruta);
            $this->assertFileExists(public_path($ruta), $ruta);
        }
        $this->assertSame([], Storage::disk(ImagenesServicios::DISCO)->allFiles('servicios'), 'La importación no copia ni sube archivos.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function paginas(): iterable
    {
        yield 'cableado' => [PaginasServicios::CABLEADO, 'cableado-estructurado'];
        yield 'videovigilancia' => [PaginasServicios::VIDEOVIGILANCIA, 'camaras-seguridad'];
        yield 'soporte' => [PaginasServicios::SOPORTE, 'soporte-mantenimiento'];
    }

    #[DataProvider('paginas')]
    public function test_contenido_inicial_publicado_se_entrega_por_la_api_igual_que_el_servicio(string $codigo, string $slug): void
    {
        $this->seed(ServiciosTecnologicosContenidoSeeder::class);
        $id = (int) ServicioTecnologico::where('codigo', $codigo)->value('id');
        $this->activarYPublicar($id);
        config(['catalogo-servicios.enabled' => true]);

        $contenido = app(ContenidoPublicoServicios::class)->pagina($codigo);
        $this->assertNotNull($contenido);
        $this->assertNotSame([], $contenido['tarjetas'], 'El contenido inicial importado trae tarjetas.');
        $this->assertSame($contenido, $this->paginaWeb($slug)->assertOk()->json('contenido'));
    }
}
