<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\CatalogoPublicoCursos;
use App\Services\CatalogoPublicoServicios;
use App\Services\GestionServiciosTecnologicos;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ServicioTecnologicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'America/Lima'));
        Http::preventStrayRequests();
        $this->actingAs($this->adminUser());
    }

    private function datos(): array
    {
        return ['codigo' => 'TEC-001', 'nombre' => 'Nombre interno ficticio', 'categoria' => 'Infraestructura', 'estado' => 'activo'];
    }

    private function ficha(): array
    {
        return ['titulo' => 'Servicio de demostración', 'resumen' => 'Resumen público ficticio', 'descripcion' => 'Detalle editorial ficticio',
            'categoria' => 'Tecnología', 'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-11-30'];
    }

    private function crear(): int
    {
        return $this->postJson('/admin/servicios', $this->datos())->assertCreated()->json('servicio.id');
    }

    private function revision(int $id): string
    {
        return $this->getJson("/admin/servicios/$id")->assertOk()->headers->get('ETag');
    }

    private function preparar(int $id): void
    {
        $this->putJson("/admin/servicios/$id/publicacion", $this->ficha(), ['If-Match' => $this->revision($id)])->assertOk();
    }

    private function publicar(int $id): void
    {
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertOk();
    }

    public function test_crear_listar_detalle_y_editar_sin_publicacion_implicita(): void
    {
        $id = $this->crear();
        $this->assertDatabaseCount('servicio_publicaciones', 0);
        $this->getJson('/admin/servicios')->assertOk()->assertJsonPath('total', 1);
        $detalle = $this->getJson("/admin/servicios/$id")->assertOk()->assertJsonPath('estado_editorial', 'sin_ficha');
        $this->assertNotEmpty($detalle->json('servicio.identificador_publico'));
        $this->putJson("/admin/servicios/$id", ['nombre' => 'Nombre corregido'], ['If-Match' => $detalle->headers->get('ETag')])
            ->assertOk()->assertJsonPath('servicio.nombre', 'Nombre corregido');
        $this->assertDatabaseCount('audit_logs', 2);
        Http::assertNothingSent();
    }

    public function test_publicacion_retiro_idempotencia_y_auditoria(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $this->getJson("/admin/servicios/$id/publicacion")->assertOk()->assertJsonPath('estado_editorial', 'borrador');
        $this->publicar($id);
        $revision = $this->revision($id);
        $audits = AuditLog::count();
        $this->publicar($id);
        $this->assertSame($revision, $this->revision($id));
        $this->assertSame($audits, AuditLog::count());
        $this->putJson("/admin/servicios/$id/publicacion", $this->ficha(), ['If-Match' => $revision])->assertConflict();
        $this->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $revision])->assertOk()->assertJsonPath('estado_editorial', 'retirada');
        $audits = AuditLog::count();
        $this->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $this->revision($id)])->assertOk();
        $this->assertSame($audits, AuditLog::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'servicio_publicado', 'model_id' => $id, 'user_id' => auth()->id()]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'servicio_retirado', 'model_id' => $id]);
    }

    public function test_revision_obligatoria_y_obsoleta_en_todas_las_mutaciones(): void
    {
        $id = $this->crear();
        $old = $this->revision($id);
        $this->preparar($id);
        foreach ([['PUT', "/admin/servicios/$id", ['nombre' => 'Cambio']], ['PATCH', "/admin/servicios/$id/estado", ['estado' => 'inactivo']],
            ['PUT', "/admin/servicios/$id/publicacion", $this->ficha()], ['POST', "/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true]],
            ['DELETE', "/admin/servicios/$id/publicacion", []]] as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertStatus(428);
            $this->json($method, $url, $body, ['If-Match' => '*'])->assertUnprocessable();
            $this->json($method, $url, $body, ['If-Match' => $old])->assertStatus(412);
        }
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_permisos_separados_y_roles_no_autorizados(): void
    {
        $id = $this->crear();
        $revision = $this->revision($id);
        $editor = User::factory()->create();
        $editor->assignRole('coordinador');
        $this->actingAs($editor);
        $this->getJson('/admin/servicios')->assertForbidden();
        $editor->givePermissionTo(['servicios.ver', 'servicios.editar']);
        $this->getJson("/admin/servicios/$id")->assertOk();
        $this->preparar($id);
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertForbidden();
        $this->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $revision])->assertForbidden();
        $this->postJson('/admin/servicios', $this->datos())->assertForbidden();
        $externo = User::factory()->create();
        $externo->givePermissionTo('servicios.ver');
        $this->actingAs($externo)->getJson('/admin/servicios')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/admin/servicios')->assertUnauthorized();
    }

    public function test_catalogo_apagado_vacio_y_proyeccion_publica_sin_escrituras(): void
    {
        $catalogo = app(CatalogoPublicoServicios::class);
        DB::enableQueryLog();
        $this->assertFalse($catalogo->pagina()['habilitado']);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        config(['catalogo-servicios.enabled' => true]);
        $this->assertSame([], $catalogo->pagina()['servicios']);
        $id = $this->crear();
        $this->preparar($id);
        $this->assertSame(0, $catalogo->pagina()['total']);
        $this->publicar($id);
        $audits = AuditLog::count();
        DB::enableQueryLog();
        $result = $catalogo->pagina();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(1, $result['total']);
        $this->assertSame(['identificador_publico', 'titulo', 'resumen', 'descripcion', 'categoria', 'vigente_desde', 'vigente_hasta'], array_keys($result['servicios'][0]));
        $this->assertStringNotContainsString('Nombre interno', json_encode($result));
        $this->assertSame($audits, AuditLog::count());
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select/i', $query['query']);
        }
        $this->assertFalse(config('catalogo-publico.enabled'));
        $this->assertFalse(config('chatbot.public_catalog_enabled'));
    }

    public static function exclusiones(): iterable
    {
        foreach (['inactivo', 'archivado', 'retirada', 'caducada', 'futura', 'autor_revocado', 'autor_sin_rol', 'autor_eliminado', 'autorizacion_futura'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('exclusiones')]
    public function test_catalogo_excluye_fichas_no_elegibles(string $caso): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->crear();
        $this->preparar($id);
        $this->publicar($id);
        match ($caso) {
            'inactivo', 'archivado' => DB::table('servicios_tecnologicos')->where('id', $id)->update(['estado' => $caso]),
            'retirada' => DB::table('servicio_publicaciones')->update(['estado' => 'retirada']),
            'caducada' => DB::table('servicio_publicaciones')->update(['vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-28']),
            'futura' => DB::table('servicio_publicaciones')->update(['vigente_desde' => '2026-10-30']),
            'autor_revocado' => auth()->user()->roles()->first()->revokePermissionTo('servicios.publicar'),
            'autor_sin_rol' => auth()->user()->syncRoles([]),
            'autor_eliminado' => auth()->user()->delete(),
            'autorizacion_futura' => DB::table('servicio_publicaciones')->update(['autorizado_at' => now()->addHour()]),
        };
        $this->assertSame(0, app(CatalogoPublicoServicios::class)->pagina()['total']);
    }

    public function test_inactivar_retira_y_reactivar_no_republica(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $this->publicar($id);
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'inactivo'], ['If-Match' => $this->revision($id)])
            ->assertOk()->assertJsonPath('estado_editorial', 'retirada');
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'activo'], ['If-Match' => $this->revision($id)])
            ->assertOk()->assertJsonPath('estado_editorial', 'retirada');
    }

    public function test_validacion_duplicados_identidad_y_mass_assignment(): void
    {
        $id = $this->crear();
        $this->postJson('/admin/servicios', $this->datos())->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $this->putJson("/admin/servicios/$id", ['codigo' => 'OTRO'], ['If-Match' => $this->revision($id)])->assertUnprocessable();
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'inventado'], ['If-Match' => $this->revision($id)])->assertUnprocessable();
        $this->putJson("/admin/servicios/$id/publicacion", [...$this->ficha(), 'vigente_hasta' => '2026-10-01'], ['If-Match' => $this->revision($id)])->assertUnprocessable();
        $this->putJson("/admin/servicios/$id/publicacion", [...$this->ficha(), 'titulo' => '<script>alert(1)</script>'], ['If-Match' => $this->revision($id)])->assertUnprocessable();
        $this->putJson("/admin/servicios/$id/publicacion", [...$this->ficha(), 'autorizado_por' => 999, 'estado' => 'publicada'], ['If-Match' => $this->revision($id)])->assertOk();
        $this->assertDatabaseHas('servicio_publicaciones', ['estado' => 'borrador', 'autorizado_por' => null]);
        $this->getJson('/admin/servicios/999999')->assertNotFound();
        $this->getJson('/admin/servicios?estado=inventado')->assertUnprocessable();
    }

    public function test_paginacion_sql_acotada_orden_estable_y_sin_n_mas_uno(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->crear();
        $this->preparar($id);
        $this->publicar($id);
        $original = DB::table('servicios_tecnologicos')->where('id', $id)->first();
        $ficha = (array) DB::table('servicio_publicaciones')->first();
        unset($ficha['id']);
        for ($i = 2; $i <= 55; $i++) {
            $nuevo = DB::table('servicios_tecnologicos')->insertGetId([
                ...get_object_vars($original), 'id' => null, 'codigo' => "TEC-$i", 'identificador_publico' => (string) Str::uuid(),
            ]);
            DB::table('servicio_publicaciones')->insert([...$ficha, 'servicio_tecnologico_id' => $nuevo]);
        }
        $lector = app(CatalogoPublicoServicios::class);
        $lector->pagina(); // Inicializa caché de permisos antes de medir consultas.
        DB::enableQueryLog();
        $primera = $lector->pagina(1, 50);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $segunda = $lector->pagina(2, 50);
        $this->assertSame(55, $primera['total']);
        $this->assertCount(50, $primera['servicios']);
        $this->assertCount(5, $segunda['servicios']);
        $this->assertSame([], array_intersect(array_column($primera['servicios'], 'identificador_publico'), array_column($segunda['servicios'], 'identificador_publico')));
        $this->assertSame($primera, $lector->pagina(1, 50));
        $sql = implode(' ', array_column($queries, 'query'));
        $this->assertStringContainsString('limit 50 offset 0', $sql);
        $this->assertLessThanOrEqual(8, count($queries));
        $this->assertSame([], $lector->pagina(3, 50)['servicios']);
        $this->getJson('/admin/servicios?por_pagina=50&page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/admin/servicios?por_pagina=51')->assertUnprocessable();
    }

    public function test_bordes_de_vigencia_lima_y_caducidad_sin_jobs(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->crear();
        $this->putJson("/admin/servicios/$id/publicacion", [...$this->ficha(), 'vigente_hasta' => '2026-10-29'], ['If-Match' => $this->revision($id)])->assertOk();
        $this->publicar($id);
        $this->travelTo(Carbon::parse('2026-10-29 23:59:59', 'America/Lima'));
        $this->assertSame(1, app(CatalogoPublicoServicios::class)->pagina()['total']);
        $this->travelTo(Carbon::parse('2026-10-30 00:00:00', 'America/Lima'));
        $this->assertSame(0, app(CatalogoPublicoServicios::class)->pagina()['total']);
        $this->getJson("/admin/servicios/$id/publicacion")->assertOk()->assertJsonPath('estado_editorial', 'caducada');
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertConflict();
    }

    public function test_autorizar_exige_ficha_activa_confirmacion_y_estado_valido(): void
    {
        $id = $this->crear();
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertConflict();
        $this->preparar($id);
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", [], ['If-Match' => $this->revision($id)])->assertUnprocessable();
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'inactivo'], ['If-Match' => $this->revision($id)])->assertOk();
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertConflict();
        $this->assertDatabaseHas('servicio_publicaciones', ['estado' => 'borrador', 'autorizado_por' => null]);
    }

    public function test_repetir_borrador_edicion_estado_y_retiro_ausente_no_audita(): void
    {
        $id = $this->crear();
        $revision = $this->revision($id);
        $this->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $revision])->assertOk();
        $this->putJson("/admin/servicios/$id", ['nombre' => $this->datos()['nombre']], ['If-Match' => $revision])->assertOk();
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'activo'], ['If-Match' => $revision])->assertOk();
        $this->assertSame($revision, $this->revision($id));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->preparar($id);
        $revision = $this->revision($id);
        $this->preparar($id);
        $this->assertSame($revision, $this->revision($id));
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_un_fallo_de_auditoria_revierte_ficha_revision_y_autorizacion(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $revision = $this->revision($id);
        Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
            throw new \RuntimeException('Fallo simulado de auditoría');
        });
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $revision])->assertStatus(500);
        $this->assertSame($revision, $this->revision($id));
        $this->assertDatabaseHas('servicio_publicaciones', ['estado' => 'borrador', 'autorizado_por' => null]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_revalida_permiso_despues_del_bloqueo(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $revision = $this->revision($id);
        $actor = auth()->user();
        $actor->can('servicios.publicar'); // Precarga relaciones como una petición que ya pasó middleware.
        $request = Request::create("/admin/servicios/$id/publicacion/autorizar", 'POST');
        $request->setUserResolver(fn () => $actor);
        $request->headers->set('If-Match', $revision);
        $actor->roles()->first()->revokePermissionTo('servicios.publicar');
        try {
            app(GestionServiciosTecnologicos::class)->cambiar($request, $id, 'publicar');
            $this->fail('Se esperaba rechazo del permiso revocado.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('servicio_publicaciones', ['estado' => 'borrador']);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_no_hay_ruta_publica_ni_eliminacion_y_cursos_conviven(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $this->publicar($id);
        $this->getJson('/servicios')->assertNotFound();
        $this->getJson('/api/servicios')->assertNotFound();
        $this->deleteJson("/admin/servicios/$id")->assertStatus(405);
        $this->assertDatabaseCount('curso_publicaciones', 0);
        $this->assertSame([], app(CatalogoPublicoCursos::class)->paraWeb()['cursos']);
        foreach (['cableado-estructurado', 'camaras-seguridad', 'soporte-mantenimiento'] as $pagina) {
            $this->get("/tecnologias/$pagina")->assertOk()->assertDontSee('Servicio de demostración');
        }
    }

    public function test_migracion_permisos_en_base_sembrada_preserva_concesiones(): void
    {
        $coordinador = Role::findByName('coordinador', 'web');
        $coordinador->givePermissionTo('servicios.ver');
        $antes = $coordinador->permissions()->pluck('name')->sort()->values()->all();
        $migracion = require database_path('migrations/2026_10_29_000026_add_servicios_permissions.php');
        $migracion->up();
        $migracion->up();
        $this->assertSame($antes, $coordinador->fresh()->permissions()->pluck('name')->sort()->values()->all());
        $this->assertFalse($coordinador->fresh()->hasPermissionTo('servicios.publicar'));
        $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo('servicios.ver'));
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('servicios.publicar'));
        $this->assertSame(4, Permission::where('name', 'like', 'servicios.%')->count());
    }

    public function test_restricciones_sql_protegen_identidad_duplicados_y_fechas(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $fila = (array) DB::table('servicios_tecnologicos')->first();
        unset($fila['id']);
        foreach ([fn () => DB::table('servicios_tecnologicos')->insert($fila),
            fn () => DB::table('servicios_tecnologicos')->where('id', $id)->update(['revision' => 0]),
            fn () => DB::table('servicios_tecnologicos')->where('id', $id)->update(['estado' => 'invalido']),
            fn () => DB::table('servicio_publicaciones')->update(['estado' => 'invalido']),
            fn () => DB::table('servicio_publicaciones')->update(['vigente_hasta' => '2025-01-01']),
            fn () => DB::table('servicio_publicaciones')->update(['estado' => 'publicada']),
            fn () => DB::table('servicios_tecnologicos')->where('id', $id)->delete(),
        ] as $escribir) {
            try {
                $escribir();
                $this->fail('La escritura inválida debía rechazarse.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_campos_inmutables_nulos_se_rechazan_y_patch_solo_cambia_estado_en_su_ruta(): void
    {
        $id = $this->crear();
        $revision = $this->revision($id);
        $this->putJson("/admin/servicios/$id", ['estado' => null], ['If-Match' => $revision])->assertUnprocessable();
        $this->putJson("/admin/servicios/$id", ['codigo' => null], ['If-Match' => $revision])->assertUnprocessable();
        $this->postJson('/admin/servicios', [...$this->datos(), 'codigo' => 'TEC-002', 'identificador_publico' => null])->assertUnprocessable();
        $this->patchJson("/admin/servicios/$id", ['estado' => 'inactivo'], ['If-Match' => $revision])->assertStatus(405);
        $this->assertDatabaseHas('servicios_tecnologicos', ['id' => $id, 'estado' => 'activo']);
        $this->assertSame($revision, $this->revision($id));
    }

    public function test_lector_no_muta_y_publicador_delegado_no_recibe_edicion(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $lector = User::factory()->create();
        $lector->assignRole('secretaria');
        $lector->givePermissionTo('servicios.ver');
        $this->actingAs($lector);
        $revision = $this->revision($id);
        $this->getJson("/admin/servicios/$id/publicacion")->assertOk();
        foreach ([['PUT', "/admin/servicios/$id", ['nombre' => 'Cambio']],
            ['PATCH', "/admin/servicios/$id/estado", ['estado' => 'inactivo']],
            ['PUT', "/admin/servicios/$id/publicacion", $this->ficha()],
            ['POST', "/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true]],
            ['DELETE', "/admin/servicios/$id/publicacion", []],
        ] as [$method, $url, $body]) {
            $this->json($method, $url, $body, ['If-Match' => $revision])->assertForbidden();
        }
        $lector->givePermissionTo('servicios.publicar');
        $this->publicar($id);
        $this->putJson("/admin/servicios/$id", ['nombre' => 'Cambio'], ['If-Match' => $this->revision($id)])->assertForbidden();
        $this->assertDatabaseHas('servicio_publicaciones', ['autorizado_por' => $lector->id, 'estado' => 'publicada']);
    }

    public function test_codigo_normalizado_por_servidor_y_etag_de_otro_servicio_rechazado(): void
    {
        $id = $this->crear();
        $this->postJson('/admin/servicios', [...$this->datos(), 'codigo' => 'tec-001'])->assertUnprocessable();
        $otro = $this->postJson('/admin/servicios', [...$this->datos(), 'codigo' => 'tec-002'])->assertCreated()->assertJsonPath('servicio.codigo', 'TEC-002')->json('servicio.id');
        $this->putJson("/admin/servicios/$id", ['nombre' => 'Cambio'], ['If-Match' => $this->revision($otro)])->assertStatus(412);
        foreach (['W/"servicio-1-v1"', '"servicio-1-v1", "servicio-1-v2"', ''] as $cabecera) {
            $this->putJson("/admin/servicios/$id", ['nombre' => 'Cambio'], ['If-Match' => $cabecera])->assertUnprocessable();
        }
        $this->getJson('/admin/servicios?estado=activo%27%20OR%201=1--')->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_migracion_recrea_permisos_ausentes_sin_resincronizar_roles(): void
    {
        $admin = Role::findByName('admin', 'web');
        $extra = Permission::findOrCreate('prueba.conservar', 'web');
        $admin->givePermissionTo($extra);
        Permission::whereIn('name', RolesAndPermissionsSeeder::SERVICIOS_PERMISSIONS)->get()->each->delete();
        $migracion = require database_path('migrations/2026_10_29_000026_add_servicios_permissions.php');
        $migracion->up();
        $this->assertTrue($admin->fresh()->hasPermissionTo('prueba.conservar'));
        foreach (RolesAndPermissionsSeeder::SERVICIOS_PERMISSIONS as $permiso) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($permiso));
        }
    }

    public function test_catalogo_sin_esquema_y_argumentos_invalidos_no_inventa_datos(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $lector = app(CatalogoPublicoServicios::class);
        foreach ([[0, 20], [1, 0], [1, 51], [1000001, 20]] as [$pagina, $tamano]) {
            try {
                $lector->pagina($pagina, $tamano);
                $this->fail('Se esperaba rechazo del rango.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        Schema::drop('servicio_publicaciones');
        $this->assertSame([], $lector->pagina()['servicios']);
        $this->assertDatabaseCount('servicios_tecnologicos', 0);
    }
}
