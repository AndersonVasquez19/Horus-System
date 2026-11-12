<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\PaginaTecnologicaController;
use App\Http\Controllers\ServiciosPublicosController;
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
use Tests\TestCase;

/** Contenido administrable de las tres páginas de Servicios Tecnológicos (SQLite :memory:; sin PostgreSQL ni red). */
class ServiciosContenidoTest extends TestCase
{
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
        return (string) $this->getJson("/admin/servicios/$id")->assertOk()->headers->get('ETag');
    }

    private function servicio(string $codigo = 'CABLEADO', bool $conFicha = true): int
    {
        $id = (int) $this->postJson('/admin/servicios', ['codigo' => $codigo, 'nombre' => 'Servicio '.$codigo, 'categoria' => $codigo])->assertCreated()->json('servicio.id');
        if ($conFicha) {
            $this->putJson("/admin/servicios/$id/publicacion", [
                'titulo' => 'Título principal '.$codigo, 'resumen' => 'Resumen público', 'descripcion' => "Primer párrafo.\n\nSegundo párrafo.",
                'vigente_desde' => '2026-11-01', 'vigente_hasta' => '2026-12-31', 'titulo_tarjetas' => 'Sección de tarjetas',
            ], ['If-Match' => $this->etag($id)])->assertOk();
        }

        return $id;
    }

    /** @param array<string, mixed> $extra */
    private function tarjeta(int $id, array $extra = [], ?string $etag = null): TestResponse
    {
        return $this->post("/admin/servicios/$id/elementos", array_merge(['titulo' => 'Tarjeta uno', 'descripcion' => 'Descripción uno'], $extra),
            ['Accept' => 'application/json', 'If-Match' => $etag ?? $this->etag($id)]);
    }

    private function activarYPublicar(int $id): void
    {
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'activo'], ['If-Match' => $this->etag($id)])->assertOk();
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->etag($id)])->assertOk();
    }

    private function retirar(int $id): void
    {
        $this->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $this->etag($id)])->assertOk();
    }

    private function imagen(string $nombre = 'foto.png', int $ancho = 200, int $alto = 150): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, $ancho, $alto);
    }

    private function archivos(): array
    {
        return Storage::disk(ImagenesServicios::DISCO)->allFiles('servicios');
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

    public function test_acceso_por_roles_a_las_operaciones_de_contenido(): void
    {
        $id = $this->servicio();
        $etag = $this->etag($id);
        $ruta = "/admin/servicios/$id/elementos";

        $lector = User::factory()->create();
        $lector->assignRole('secretaria');
        $lector->givePermissionTo('servicios.ver');
        $this->actingAs($lector);
        $this->post($ruta, ['titulo' => 'X', 'descripcion' => 'Y'], ['Accept' => 'application/json', 'If-Match' => $etag])->assertForbidden();
        $this->post("/admin/servicios/$id/publicacion/imagen", ['imagen' => $this->imagen()], ['Accept' => 'application/json', 'If-Match' => $etag])->assertForbidden();
        $this->deleteJson("$ruta/1", [], ['If-Match' => $etag])->assertForbidden();

        // Publicar no implica editar contenido (permisos independientes, sin permisos nuevos).
        $publicador = User::factory()->create();
        $publicador->assignRole('coordinador');
        $publicador->givePermissionTo(['servicios.ver', 'servicios.publicar']);
        $this->actingAs($publicador);
        $this->post($ruta, ['titulo' => 'X', 'descripcion' => 'Y'], ['Accept' => 'application/json', 'If-Match' => $etag])->assertForbidden();

        $editor = User::factory()->create();
        $editor->assignRole('coordinador');
        $editor->givePermissionTo(['servicios.ver', 'servicios.editar']);
        $this->actingAs($editor);
        $this->post($ruta, ['titulo' => 'X', 'descripcion' => 'Y'], ['Accept' => 'application/json', 'If-Match' => $etag])->assertCreated();

        $this->assertFalse(User::factory()->create()->can('servicios.editar'));
        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->assertFalse(Role::findByName($rol, 'web')->hasPermissionTo('servicios.editar'));
        }
    }

    public function test_invitado_no_accede(): void
    {
        $id = $this->servicio();
        auth()->logout();
        $this->post("/admin/servicios/$id/elementos", ['titulo' => 'X', 'descripcion' => 'Y'], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    // ---------------------------------------------------------------- Tarjetas

    public function test_crear_y_editar_tarjeta_con_etag_y_auditoria(): void
    {
        $id = $this->servicio();
        $respuesta = $this->tarjeta($id, ['caracteristicas' => ['Uno', ' Dos ', ''], 'icono' => 'fas fa-bolt', 'variante_imagen' => 'escena', 'activo' => '1'])->assertCreated();
        $respuesta->assertJsonPath('publicacion.elementos.0.titulo', 'Tarjeta uno')
            ->assertJsonPath('publicacion.elementos.0.caracteristicas', ['Uno', 'Dos'])
            ->assertJsonPath('publicacion.elementos.0.orden', 1)->assertJsonPath('publicacion.elementos.0.activo', true);
        $elemento = (int) $respuesta->json('publicacion.elementos.0.id');
        $this->assertSame(3, ServicioTecnologico::findOrFail($id)->revision);

        $this->post("/admin/servicios/$id/elementos/$elemento", ['titulo' => 'Cambiado', 'descripcion' => 'Nueva', 'orden' => 5, 'activo' => '0'],
            ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])->assertOk()
            ->assertJsonPath('publicacion.elementos.0.titulo', 'Cambiado')->assertJsonPath('publicacion.elementos.0.activo', false)
            ->assertJsonPath('publicacion.elementos.0.orden', 5);
        $this->assertSame(4, ServicioTecnologico::findOrFail($id)->revision);

        foreach (['servicio_elemento_creado', 'servicio_elemento_actualizado'] as $evento) {
            $this->assertDatabaseHas('audit_logs', ['action' => $evento, 'model_type' => ServicioTecnologico::class, 'model_id' => $id, 'user_id' => auth()->id()]);
        }
        $this->assertStringNotContainsString('Cambiado', (string) json_encode(AuditLog::query()->where('action', 'servicio_elemento_actualizado')->value('changes')));
    }

    public function test_control_etag_en_todas_las_operaciones_de_contenido(): void
    {
        $id = $this->servicio();
        $viejo = $this->etag($id);
        $this->tarjeta($id, [], $viejo)->assertCreated();
        // Reutilizar la revisión anterior: conflicto.
        $this->tarjeta($id, [], $viejo)->assertStatus(412);
        $this->post("/admin/servicios/$id/publicacion/imagen", ['imagen' => $this->imagen()], ['Accept' => 'application/json', 'If-Match' => $viejo])->assertStatus(412);
        $this->deleteJson("/admin/servicios/$id/elementos/1", [], ['If-Match' => $viejo])->assertStatus(412);
        // Sin If-Match: 428.
        $this->post("/admin/servicios/$id/elementos", ['titulo' => 'X', 'descripcion' => 'Y'], ['Accept' => 'application/json'])->assertStatus(428);
        $this->assertSame(1, ServicioPublicacionElemento::count());
        $this->assertSame([], $this->archivos());
    }

    public function test_requiere_ficha_y_bloquea_con_ficha_publicada(): void
    {
        $sin = $this->servicio('SOPORTE', false);
        $this->tarjeta($sin)->assertStatus(409);

        $id = $this->servicio();
        $this->tarjeta($id)->assertCreated();
        $this->activarYPublicar($id);
        $this->tarjeta($id)->assertStatus(409);
        $this->post("/admin/servicios/$id/publicacion/imagen", ['imagen' => $this->imagen()], ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])->assertStatus(409);
        $this->assertSame([], $this->archivos());
        $this->retirar($id);
        $this->tarjeta($id, ['titulo' => 'Tras retiro'])->assertCreated();
        // El contenido cambiado tras el retiro vuelve a borrador: no queda reactivable sin reautorizar.
        $this->assertSame('borrador', ServicioPublicacion::firstOrFail()->estado);
    }

    public static function invalidos(): iterable
    {
        yield 'sin título' => [['titulo' => ''], 'titulo'];
        yield 'título con HTML' => [['titulo' => 'a<b>'], 'titulo'];
        yield 'sin descripción' => [['descripcion' => ''], 'descripcion'];
        yield 'descripción larga' => [['descripcion' => str_repeat('a', 1001)], 'descripcion'];
        yield 'ícono arbitrario' => [['icono' => 'fas fa-x" onclick="1'], 'icono'];
        yield 'variante desconocida' => [['variante_imagen' => 'otra'], 'variante_imagen'];
        yield 'orden fuera de rango' => [['orden' => 10000], 'orden'];
        yield 'orden negativo' => [['orden' => -1], 'orden'];
        yield 'demasiadas características' => [['caracteristicas' => array_fill(0, 13, 'x')], 'caracteristicas'];
        yield 'característica con HTML' => [['caracteristicas' => ['<script>']], 'caracteristicas.0'];
        yield 'ruta de imagen impuesta' => [['imagen_ruta' => 'assets/images/x.jpg'], 'imagen_ruta'];
        yield 'publicación impuesta' => [['servicio_publicacion_id' => 99], 'servicio_publicacion_id'];
        yield 'activo inválido' => [['activo' => 'quizá'], 'activo'];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('invalidos')]
    public function test_validacion_de_campos_de_tarjeta(array $extra, string $campo): void
    {
        $id = $this->servicio();
        $this->tarjeta($id, $extra)->assertStatus(422)->assertJsonValidationErrors([$campo]);
        $this->assertSame(0, ServicioPublicacionElemento::count());
    }

    public function test_limite_de_tarjetas_y_sin_archivo_huerfano(): void
    {
        $id = $this->servicio();
        $ficha = ServicioPublicacion::firstOrFail();
        for ($i = 1; $i <= 30; $i++) {
            $e = new ServicioPublicacionElemento(['titulo' => "T$i", 'descripcion' => 'd', 'orden' => $i, 'activo' => true]);
            $e->servicio_publicacion_id = $ficha->id;
            $e->save();
        }
        $this->tarjeta($id, ['imagen' => $this->imagen()])->assertStatus(422);
        $this->assertSame([], $this->archivos(), 'La subida de una operación fallida no debe quedar en disco.');
        $this->assertSame(30, ServicioPublicacionElemento::count());
    }

    public function test_tarjeta_de_otro_servicio_no_es_accesible(): void
    {
        $a = $this->servicio('CABLEADO');
        $b = $this->servicio('SOPORTE');
        $elemento = (int) $this->tarjeta($a)->assertCreated()->json('publicacion.elementos.0.id');
        $this->post("/admin/servicios/$b/elementos/$elemento", ['titulo' => 'Robo', 'descripcion' => 'x'], ['Accept' => 'application/json', 'If-Match' => $this->etag($b)])->assertNotFound();
        $this->deleteJson("/admin/servicios/$b/elementos/$elemento", [], ['If-Match' => $this->etag($b)])->assertNotFound();
        $this->assertSame('Tarjeta uno', ServicioPublicacionElemento::findOrFail($elemento)->titulo);
    }

    public function test_eliminar_tarjeta_y_su_imagen_subida(): void
    {
        $id = $this->servicio();
        $r = $this->tarjeta($id, ['imagen' => $this->imagen()])->assertCreated();
        $ruta = $r->json('publicacion.elementos.0.imagen_ruta');
        Storage::disk('public')->assertExists($ruta);
        $this->deleteJson("/admin/servicios/$id/elementos/".$r->json('publicacion.elementos.0.id'), [], ['If-Match' => $this->etag($id)])->assertOk()
            ->assertJsonPath('publicacion.elementos', []);
        Storage::disk('public')->assertMissing($ruta);
        $this->assertDatabaseHas('audit_logs', ['action' => 'servicio_elemento_eliminado', 'model_id' => $id]);
    }

    // ---------------------------------------------------------------- Imágenes

    public function test_imagen_valida_se_guarda_con_nombre_propio_y_ruta_relativa(): void
    {
        $id = $this->servicio();
        $r = $this->tarjeta($id, ['imagen' => $this->imagen('../../etc/passwd.png')])->assertCreated();
        $ruta = (string) $r->json('publicacion.elementos.0.imagen_ruta');
        $this->assertMatchesRegularExpression('#\Aservicios/[0-9a-f-]{36}\.png\z#', $ruta);
        Storage::disk('public')->assertExists($ruta);
        $this->assertStringNotContainsString('passwd', $ruta);
        $this->assertStringContainsString('/storage/'.$ruta, (string) $r->json('publicacion.elementos.0.imagen_url'));
        $this->assertSame($ruta, DB::table('servicio_publicacion_elementos')->value('imagen_ruta'));
    }

    public static function imagenesInvalidas(): iterable
    {
        yield 'texto con extensión png' => ['x.png', '<?php echo 1;'];
        yield 'svg' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script></svg>'];
        yield 'html como jpg' => ['x.jpg', '<html><body>hola</body></html>'];
        yield 'gif' => ['x.gif', base64_decode('R0lGODlhyADIAIAAAAAAAP///yH5BAEAAAAALAAAAADIAMgAAAL/hI+py+0Po5y02ouz3rz7D4biSJbmiabqyrbuC8cyTNf2jed6zvc+0DsIAAA7')];
    }

    #[DataProvider('imagenesInvalidas')]
    public function test_imagen_invalida_se_rechaza_sin_escribir(string $nombre, string $contenido): void
    {
        $id = $this->servicio();
        $this->tarjeta($id, ['imagen' => UploadedFile::fake()->createWithContent($nombre, $contenido)])->assertStatus(422)->assertJsonValidationErrors(['imagen']);
        $this->post("/admin/servicios/$id/publicacion/imagen", ['imagen' => UploadedFile::fake()->createWithContent($nombre, $contenido)],
            ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])->assertStatus(422);
        $this->assertSame([], $this->archivos());
        $this->assertSame(0, ServicioPublicacionElemento::count());
    }

    public function test_imagen_demasiado_grande_o_pequena_se_rechaza(): void
    {
        $id = $this->servicio();
        $this->tarjeta($id, ['imagen' => UploadedFile::fake()->image('g.jpg', 400, 400)->size(ImagenesServicios::MAX_KB + 1)])->assertStatus(422)->assertJsonValidationErrors(['imagen']);
        $this->tarjeta($id, ['imagen' => $this->imagen('p.png', 20, 20)])->assertStatus(422)->assertJsonValidationErrors(['imagen']);
        $this->assertSame([], $this->archivos());
    }

    public function test_reemplazo_seguro_borra_la_anterior_solo_tras_confirmar(): void
    {
        $id = $this->servicio();
        $r = $this->tarjeta($id, ['imagen' => $this->imagen()])->assertCreated();
        $elemento = (int) $r->json('publicacion.elementos.0.id');
        $vieja = (string) $r->json('publicacion.elementos.0.imagen_ruta');

        // Falla (ETag obsoleto): la anterior sigue y la nueva no queda huérfana.
        $this->post("/admin/servicios/$id/elementos/$elemento", ['titulo' => 'T', 'descripcion' => 'd', 'imagen' => $this->imagen('b.png')],
            ['Accept' => 'application/json', 'If-Match' => '"servicio-'.$id.'-v1"'])->assertStatus(412);
        $this->assertSame([$vieja], $this->archivos());

        $nuevo = (string) $this->post("/admin/servicios/$id/elementos/$elemento", ['titulo' => 'T', 'descripcion' => 'd', 'imagen' => $this->imagen('c.png')],
            ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])->assertOk()->json('publicacion.elementos.0.imagen_ruta');
        $this->assertNotSame($vieja, $nuevo);
        Storage::disk('public')->assertMissing($vieja);
        Storage::disk('public')->assertExists($nuevo);
        $this->assertCount(1, $this->archivos());

        // Quitar la fotografía la borra y deja la tarjeta sin imagen.
        $this->post("/admin/servicios/$id/elementos/$elemento", ['titulo' => 'T', 'descripcion' => 'd', 'quitar_imagen' => '1'],
            ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])->assertOk()->assertJsonPath('publicacion.elementos.0.imagen_ruta', null);
        $this->assertSame([], $this->archivos());
    }

    public function test_imagen_compartida_o_estatica_no_se_borra(): void
    {
        $id = $this->servicio();
        $a = $this->tarjeta($id, ['imagen' => $this->imagen()])->assertCreated();
        $compartida = (string) $a->json('publicacion.elementos.0.imagen_ruta');
        $primera = (int) $a->json('publicacion.elementos.0.id');
        $segunda = (int) $this->tarjeta($id, ['titulo' => 'Otra'])->assertCreated()->json('publicacion.elementos.1.id');
        // Otra tarjeta y la portada referencian la misma carga.
        DB::table('servicio_publicacion_elementos')->where('id', $segunda)->update(['imagen_ruta' => $compartida]);
        DB::table('servicio_publicaciones')->update(['imagen_principal_ruta' => $compartida]);

        $this->deleteJson("/admin/servicios/$id/elementos/$primera", [], ['If-Match' => $this->etag($id)])->assertOk();
        Storage::disk('public')->assertExists($compartida);

        // Una ruta estática versionada nunca se elimina, ni siquiera sin referencias.
        DB::table('servicio_publicacion_elementos')->where('id', $segunda)->update(['imagen_ruta' => 'assets/images/tecnologias/categoria1.jpg']);
        $nueva = $this->post("/admin/servicios/$id/elementos/$segunda", ['titulo' => 'Otra', 'descripcion' => 'd', 'imagen' => $this->imagen('n.png')],
            ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])->assertOk();
        $this->assertFileExists(public_path('assets/images/tecnologias/categoria1.jpg'));
        Storage::disk('public')->assertExists($compartida);
        $this->assertMatchesRegularExpression('#\Aservicios/#', (string) $nueva->json('publicacion.elementos.0.imagen_ruta'));
    }

    public function test_imagen_principal_se_reemplaza_y_conserva_alt(): void
    {
        $id = $this->servicio();
        $url = "/admin/servicios/$id/publicacion/imagen";
        $uno = (string) $this->post($url, ['imagen' => $this->imagen('uno.webp'), 'imagen_principal_alt' => 'Portada'], ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])
            ->assertOk()->assertJsonPath('publicacion.imagen_principal_alt', 'Portada')->json('publicacion.imagen_principal_ruta');
        $dos = (string) $this->post($url, ['imagen' => $this->imagen('dos.png')], ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])
            ->assertOk()->json('publicacion.imagen_principal_ruta');
        Storage::disk('public')->assertMissing($uno);
        Storage::disk('public')->assertExists($dos);
        $this->post($url, ['imagen_principal_alt' => 'x'], ['Accept' => 'application/json', 'If-Match' => $this->etag($id)])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'servicio_imagen_principal_actualizada', 'model_id' => $id]);
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

    public function test_rutas_y_nombres_de_las_tres_paginas_se_conservan(): void
    {
        $esperadas = ['tecnologias.cableado' => ['/tecnologias/cableado-estructurado', PaginaTecnologicaController::class],
            'tecnologias.camaras' => ['/tecnologias/camaras-seguridad', PaginaTecnologicaController::class],
            'tecnologias.soporte' => ['/tecnologias/soporte-mantenimiento', ServiciosPublicosController::class]];
        foreach ($esperadas as $nombre => [$uri, $controlador]) {
            $ruta = app('router')->getRoutes()->getByName($nombre);
            $this->assertSame(['GET', 'HEAD'], $ruta->methods());
            $this->assertSame(url($uri), route($nombre));
            $this->assertStringStartsWith($controlador, $ruta->getActionName());
        }
    }

    public function test_catalogo_apagado_sirve_el_html_estatico_sin_consultar_la_base(): void
    {
        $this->assertFalse(config('catalogo-servicios.enabled'));
        $id = $this->servicio();
        $this->tarjeta($id, ['titulo' => 'TARJETA DINAMICA'])->assertCreated();
        $this->activarYPublicar($id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('tecnologias.cableado'))->assertOk()->assertSee('Categoría 6')->assertDontSee('TARJETA DINAMICA');
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_pagina_dinamica_muestra_solo_activo_autorizado_y_vigente(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio();
        $this->tarjeta($id, ['titulo' => 'SEGUNDA', 'orden' => 20])->assertCreated();
        $this->tarjeta($id, ['titulo' => 'PRIMERA', 'orden' => 10])->assertCreated();
        $this->tarjeta($id, ['titulo' => 'OCULTA', 'orden' => 5, 'activo' => '0'])->assertCreated();

        // Borrador: nada.
        $this->get(route('tecnologias.cableado'))->assertOk()->assertDontSee('PRIMERA')->assertSee('Categoría 6');

        $this->activarYPublicar($id);
        $html = $this->get(route('tecnologias.cableado'))->assertOk()->assertSee('Título principal CABLEADO')->assertSee('Sección de tarjetas')
            ->assertSee('Primer párrafo.')->assertSee('Segundo párrafo.')->assertDontSee('OCULTA')->assertDontSee('Categoría 6')->getContent();
        $this->assertLessThan(strpos($html, 'SEGUNDA'), strpos($html, 'PRIMERA'), 'Debe respetar el orden configurado.');

        // Retirada: vuelve al contenido estático; el borrador no se expone.
        $this->retirar($id);
        $this->get(route('tecnologias.cableado'))->assertDontSee('PRIMERA')->assertSee('Categoría 6');
    }

    public function test_vigencia_servicio_inactivo_y_autor_sin_permiso(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio('VIDEOVIGILANCIA');
        $this->tarjeta($id, ['titulo' => 'CAMARA NUEVA'])->assertCreated();
        $this->activarYPublicar($id);
        $this->get(route('tecnologias.camaras'))->assertSee('CAMARA NUEVA');

        $this->travelTo(Carbon::parse('2027-01-05 12:00:00', 'America/Lima'));
        $this->get(route('tecnologias.camaras'))->assertDontSee('CAMARA NUEVA');
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'America/Lima'));
        $this->get(route('tecnologias.camaras'))->assertSee('CAMARA NUEVA');

        // Programada: aún no vigente.
        DB::table('servicio_publicaciones')->update(['vigente_desde' => '2026-11-03', 'vigente_hasta' => '2026-12-31']);
        $this->get(route('tecnologias.camaras'))->assertDontSee('CAMARA NUEVA');
        DB::table('servicio_publicaciones')->update(['vigente_desde' => '2026-11-01']);

        // Autor que perdió el permiso de publicar.
        $autor = User::find(ServicioPublicacion::firstOrFail()->autorizado_por);
        $autor->removeRole('admin');
        $this->get(route('tecnologias.camaras'))->assertDontSee('CAMARA NUEVA');
        $autor->assignRole('admin');
        $this->get(route('tecnologias.camaras'))->assertSee('CAMARA NUEVA');

        // Inactivar retira.
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'inactivo'], ['If-Match' => $this->etag($id)])->assertOk();
        $this->get(route('tecnologias.camaras'))->assertDontSee('CAMARA NUEVA')->assertSee('CÁMARAS IP');
    }

    public function test_contenido_escapado_y_sin_exponer_datos_administrativos(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio('SOPORTE');
        $r = $this->tarjeta($id, ['titulo' => 'Tarjeta & "comillas"', 'icono' => 'fas fa-cogs', 'caracteristicas' => ['Item & uno']])->assertCreated();
        DB::table('servicio_publicacion_elementos')->update(['descripcion' => '<script>alert(1)</script>', 'imagen_alt' => '"><img src=x>']);
        $this->activarYPublicar($id);
        $uuid = (string) $r->json('servicio.identificador_publico');
        $this->get(route('tecnologias.soporte'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('Tarjeta &amp; &quot;comillas&quot;', false)
            ->assertDontSee($uuid)->assertDontSee('Servicio SOPORTE')->assertDontSee('"><img', false)->assertSee('fas fa-cogs');
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

    public function test_pagina_mal_provisionada_cae_al_contenido_estatico(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->servicio();
        $this->activarYPublicar($id);
        DB::statement('DROP TABLE servicio_publicacion_elementos');
        $this->get(route('tecnologias.cableado'))->assertOk()->assertSee('Categoría 6');
    }

    public function test_error_al_comprobar_esquema_conserva_pagina_estatica_sin_filtrar_sql(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        Schema::shouldReceive('hasTable')->once()->with('servicio_publicaciones')
            ->andThrow(new QueryException('sqlite', 'consulta privada', [], new \RuntimeException('detalle privado')));
        Log::shouldReceive('warning')->once()->with('servicios_paginas_lectura_fallida');

        $this->withoutExceptionHandling()->get(route('tecnologias.cableado'))
            ->assertOk()->assertSee('Categoría 6')->assertDontSee('consulta privada')->assertDontSee('detalle privado');
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
        $this->assertSame([], $this->archivos(), 'La importación no copia ni sube archivos.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function paginas(): iterable
    {
        yield 'cableado' => [PaginasServicios::CABLEADO, 'pages.tecnologias.cableado-estructurado'];
        yield 'videovigilancia' => [PaginasServicios::VIDEOVIGILANCIA, 'pages.tecnologias.camaras-seguridad'];
        yield 'soporte' => [PaginasServicios::SOPORTE, 'pages.tecnologias.soporte-mantenimiento'];
    }

    private function normalizar(string $html): string
    {
        $html = str_replace('&quot;', '"', (string) preg_replace('/<!--.*?-->/s', '', $html));

        return trim((string) preg_replace('/\s*(<[^>]+>)\s*/', '$1', (string) preg_replace('/\s+/', ' ', $html)));
    }

    #[DataProvider('paginas')]
    public function test_contenido_inicial_publicado_reproduce_el_html_estatico_actual(string $codigo, string $vista): void
    {
        $this->seed(ServiciosTecnologicosContenidoSeeder::class);
        $id = (int) ServicioTecnologico::where('codigo', $codigo)->value('id');
        $this->activarYPublicar($id);
        config(['catalogo-servicios.enabled' => true]);

        $contenido = app(ContenidoPublicoServicios::class)->pagina($codigo);
        $this->assertNotNull($contenido);
        $dinamico = view($vista, ['contenidoServicio' => $contenido])->renderSections()['content'];
        $estatico = view($vista)->renderSections()['content'];
        $this->assertSame($this->normalizar($estatico), $this->normalizar($dinamico));

        // Y el HTTP real sirve esa misma versión dinámica, con el diseño y el CSS existentes.
        $ruta = ['CABLEADO' => 'tecnologias.cableado', 'VIDEOVIGILANCIA' => 'tecnologias.camaras', 'SOPORTE' => 'tecnologias.soporte'][$codigo];
        $this->get(route($ruta))->assertOk()->assertSee('assets/css/tecnologias/', false)->assertSee('legacy-showcase', false);
    }

    public function test_panel_diferencia_las_tres_categorias(): void
    {
        $this->seed(ServiciosTecnologicosContenidoSeeder::class);
        $html = $this->get('/admin/servicios')->assertOk()->getContent();
        foreach (['Cableado estructurado', 'Sistema de videovigilancia', 'Soporte y mantenimiento', '/tecnologias/cableado-estructurado', '/tecnologias/camaras-seguridad', '/tecnologias/soporte-mantenimiento'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $json = $this->getJson('/admin/servicios')->assertOk()->json('data');
        $this->assertSame(['CABLEADO', 'SOPORTE', 'VIDEOVIGILANCIA'], collect($json)->pluck('pagina_publica.codigo')->sort()->values()->all());
    }
}
