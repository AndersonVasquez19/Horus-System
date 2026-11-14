<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

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
use Tests\Support\LlamaServiciosDelPanel;
use Tests\TestCase;

/**
 * Contrato del catálogo de servicios tecnológicos en /api/panel/v1/servicios/servicios. Sustituye a
 * `ServicioTecnologicoTest` y a `PanelServiciosTest`, que ejercitaban el módulo Blade retirado el 14/11/2026:
 * revisión `If-Match`, permisos separados, publicación e idempotencia, auditoría, rollback, proyección pública y
 * restricciones SQL. Cambian la ruta, el verbo de las gestiones (POST en vez de PATCH/DELETE) y la forma de la
 * respuesta (`data.*`); las pruebas de catálogo, esquema y migración no dependían de la ruta y se conservan.
 *
 * Diferencias de convención aceptadas: un filtro de listado fuera de contrato se ignora (en Blade devolvía 422), la
 * paginación usa `page`/`per_page` (1..100) y el acceso exige además `panel.servicios.acceder`.
 */
class ServiciosContratoPanelTest extends TestCase
{
    use LlamaServiciosDelPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite :memory: fuera del perfil; conexión protegida de la lista fija dentro de PostgreSQL Testing.
        $this->assertIsolatedTestDatabase();
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
        return $this->crearServicioPanel($this->datos());
    }

    private function revision(int $id): string
    {
        return $this->revisionServicioPanel($id);
    }

    private function preparar(int $id): void
    {
        $this->guardarFichaPanel($id, $this->ficha())->assertOk();
    }

    private function publicar(int $id): void
    {
        $this->autorizarFichaPanel($id)->assertOk();
    }

    /** Usuario con rol administrativo y los permisos indicados (entrada al panel incluida solo si se pide). */
    private function usuario(string $rol, array $permisos): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);
        $usuario->givePermissionTo($permisos);

        return $usuario;
    }

    public function test_crear_listar_detalle_y_editar_sin_publicacion_implicita(): void
    {
        $id = $this->crear();
        $this->assertDatabaseCount('servicio_publicaciones', 0);
        $this->panelServicios('GET')->assertOk()->assertJsonPath('meta.total', 1);
        $detalle = $this->panelServicios('GET', (string) $id)->assertOk()->assertJsonPath('data.estado_editorial', 'sin_ficha');
        $this->assertNotEmpty($detalle->json('data.identificador_publico'));
        $this->panelServicios('PUT', (string) $id, ['nombre' => 'Nombre corregido'], ['If-Match' => $detalle->headers->get('ETag')])
            ->assertOk()->assertJsonPath('data.nombre', 'Nombre corregido');
        $this->assertDatabaseCount('audit_logs', 2);
        Http::assertNothingSent();
    }

    public function test_publicacion_retiro_idempotencia_y_auditoria(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $this->panelServicios('GET', (string) $id)->assertOk()->assertJsonPath('data.estado_editorial', 'borrador');
        $this->publicar($id);
        $revision = $this->revision($id);
        $audits = AuditLog::count();
        $this->publicar($id);
        $this->assertSame($revision, $this->revision($id));
        $this->assertSame($audits, AuditLog::count());
        $this->panelServicios('PUT', "$id/publicacion", $this->ficha(), ['If-Match' => $revision])->assertConflict();
        $this->panelServicios('POST', "$id/publicacion/retirar", [], ['If-Match' => $revision])->assertOk()->assertJsonPath('data.estado_editorial', 'retirada');
        $audits = AuditLog::count();
        $this->retirarFichaPanel($id)->assertOk();
        $this->assertSame($audits, AuditLog::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'servicio_publicado', 'model_id' => $id, 'user_id' => auth()->id()]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'servicio_retirado', 'model_id' => $id]);
    }

    public function test_revision_obligatoria_y_obsoleta_en_todas_las_mutaciones(): void
    {
        $id = $this->crear();
        $old = $this->revision($id);
        $this->preparar($id);
        foreach ([['PUT', "$id", ['nombre' => 'Cambio']], ['POST', "$id/estado", ['estado' => 'inactivo']],
            ['PUT', "$id/publicacion", $this->ficha()], ['POST', "$id/publicacion/autorizar", ['confirmacion_publica' => true]],
            ['POST', "$id/publicacion/retirar", []]] as [$method, $url, $body]) {
            $this->panelServicios($method, $url, $body)->assertStatus(428);
            $this->panelServicios($method, $url, $body, ['If-Match' => '*'])->assertUnprocessable();
            $this->panelServicios($method, $url, $body, ['If-Match' => $old])->assertStatus(412);
        }
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_la_revision_tambien_viaja_en_el_cuerpo_para_el_bff(): void
    {
        $id = $this->crear();
        $this->panelServicios('PUT', (string) $id, ['nombre' => 'Por cuerpo', '_revision' => $this->revision($id)])
            ->assertOk()->assertJsonPath('data.nombre', 'Por cuerpo');
        // Una cabecera real tiene prioridad sobre el campo del cuerpo.
        $this->panelServicios('PUT', (string) $id, ['nombre' => 'Otra vez', '_revision' => $this->revision($id)], ['If-Match' => '"servicio-'.$id.'-v1"'])
            ->assertStatus(412);
    }

    public function test_permisos_separados_y_roles_no_autorizados(): void
    {
        $id = $this->crear();
        $revision = $this->revision($id);
        $editor = User::factory()->create();
        $editor->assignRole('coordinador');
        $this->actingAs($editor);
        $this->panelServicios('GET')->assertForbidden();
        // Los permisos del módulo no abren el panel de servicios sin el permiso de entrada.
        $editor->givePermissionTo(['servicios.ver', 'servicios.editar']);
        $this->panelServicios('GET')->assertForbidden();
        $editor->givePermissionTo('panel.servicios.acceder');
        $this->panelServicios('GET', (string) $id)->assertOk();
        $this->preparar($id);
        $this->panelServicios('POST', "$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertForbidden();
        $this->panelServicios('POST', "$id/publicacion/retirar", [], ['If-Match' => $revision])->assertForbidden();
        $this->panelServicios('POST', '', $this->datos())->assertForbidden();
        $externo = User::factory()->create();
        $externo->givePermissionTo('servicios.ver');
        $this->actingAs($externo);
        $this->panelServicios('GET')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->panelServicios('GET')->assertUnauthorized();
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
        $this->assertTrue(config('catalogo-publico.enabled'));
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
        $this->cambiarEstadoServicioPanel($id, 'inactivo')->assertOk()->assertJsonPath('data.estado_editorial', 'retirada');
        $this->cambiarEstadoServicioPanel($id, 'activo')->assertOk()->assertJsonPath('data.estado_editorial', 'retirada');
    }

    public function test_validacion_duplicados_identidad_y_mass_assignment(): void
    {
        $id = $this->crear();
        $this->panelServicios('POST', '', $this->datos())->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $this->panelServicios('PUT', (string) $id, ['codigo' => 'OTRO'], ['If-Match' => $this->revision($id)])->assertUnprocessable();
        $this->cambiarEstadoServicioPanel($id, 'inventado')->assertUnprocessable();
        $this->guardarFichaPanel($id, [...$this->ficha(), 'vigente_hasta' => '2026-10-01'])->assertUnprocessable();
        $this->guardarFichaPanel($id, [...$this->ficha(), 'titulo' => '<script>alert(1)</script>'])->assertUnprocessable();
        $this->guardarFichaPanel($id, [...$this->ficha(), 'autorizado_por' => 999, 'estado' => 'publicada'])->assertOk();
        $this->assertDatabaseHas('servicio_publicaciones', ['estado' => 'borrador', 'autorizado_por' => null]);
        $this->panelServicios('GET', '999999')->assertNotFound();
        $this->panelServicios('GET', 'no-numerico')->assertNotFound();
        $this->panelServicios('PUT', 'no-numerico/publicacion', [])->assertNotFound();
        // Un filtro fuera de contrato se ignora (no se aplica ni se refleja en `meta.filtros`).
        $this->panelServicios('GET', '?estado=inventado')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.filtros', []);
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
                ...array_diff_key(get_object_vars($original), ['id' => true]), 'codigo' => "TEC-$i", 'identificador_publico' => (string) Str::uuid(),
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
        $this->panelServicios('GET', '?per_page=50&page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 55);
        // Un tamaño fuera de 1..100 vuelve al valor por defecto del panel.
        $this->panelServicios('GET', '?per_page=101')->assertOk()->assertJsonPath('meta.por_pagina', 20)->assertJsonCount(20, 'data');
    }

    public function test_listado_con_filtros_texto_sin_interpretar_y_sin_n_mas_uno(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $id = DB::table('servicios_tecnologicos')->insertGetId([
                'identificador_publico' => (string) Str::uuid(), 'codigo' => 'TEC-'.$i,
                'nombre' => $i === 1 ? '<script>intruso</script>' : 'Servicio ficticio '.$i,
                'estado' => $i === 17 ? 'inactivo' : 'activo', 'revision' => 1,
            ]);
            DB::table('servicio_publicaciones')->insert([
                'servicio_tecnologico_id' => $id, 'titulo' => 'Ficha ficticia', 'resumen' => 'Resumen', 'descripcion' => 'Detalle',
                'estado' => 'borrador', 'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-11-30',
            ]);
        }
        $this->panelServicios('GET', '?estado=activo&per_page=100')->assertOk();
        DB::enableQueryLog();
        $respuesta = $this->panelServicios('GET', '?estado=activo&per_page=100')->assertOk()->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.filtros', ['estado' => 'activo']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        // El nombre llega como dato JSON literal (la interfaz lo escapa); y el inactivo queda fuera.
        $this->assertSame('<script>intruso</script>', collect($respuesta->json('data'))->firstWhere('codigo', 'TEC-1')['nombre']);
        $this->assertNull(collect($respuesta->json('data'))->firstWhere('codigo', 'TEC-17'));
        $this->assertSame('borrador', $respuesta->json('data.0.publicacion.estado'));
        $this->assertCount(3, array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "servicios_tecnologicos"') || str_contains($query['query'], 'from "servicio_publicaciones"')));
        $this->panelServicios('GET', '?estado=activo&per_page=10&page=2')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('meta.paginas', 2);
        $this->panelServicios('GET', '?estado=inactivo')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.codigo', 'TEC-17');
    }

    public function test_bordes_de_vigencia_lima_y_caducidad_sin_jobs(): void
    {
        config(['catalogo-servicios.enabled' => true]);
        $id = $this->crear();
        $this->guardarFichaPanel($id, [...$this->ficha(), 'vigente_hasta' => '2026-10-29'])->assertOk();
        $this->publicar($id);
        $this->travelTo(Carbon::parse('2026-10-29 23:59:59', 'America/Lima'));
        $this->assertSame(1, app(CatalogoPublicoServicios::class)->pagina()['total']);
        $this->travelTo(Carbon::parse('2026-10-30 00:00:00', 'America/Lima'));
        $this->assertSame(0, app(CatalogoPublicoServicios::class)->pagina()['total']);
        // La sesión del panel tiene vida máxima absoluta: tras avanzar el reloj se abre una nueva (el control no se relaja).
        $usuario = auth()->user();
        $this->flushSession();
        $this->actingAs($usuario);
        $this->panelServicios('GET', (string) $id)->assertOk()->assertJsonPath('data.estado_editorial', 'caducada');
        $this->autorizarFichaPanel($id)->assertConflict();
    }

    public function test_autorizar_exige_ficha_activa_confirmacion_y_estado_valido(): void
    {
        $id = $this->crear();
        $this->autorizarFichaPanel($id)->assertConflict();
        $this->preparar($id);
        $this->panelServicios('POST', "$id/publicacion/autorizar", [], ['If-Match' => $this->revision($id)])->assertUnprocessable();
        $this->cambiarEstadoServicioPanel($id, 'inactivo')->assertOk();
        $this->autorizarFichaPanel($id)->assertConflict();
        $this->assertDatabaseHas('servicio_publicaciones', ['estado' => 'borrador', 'autorizado_por' => null]);
    }

    public function test_repetir_borrador_edicion_estado_y_retiro_ausente_no_audita(): void
    {
        $id = $this->crear();
        $revision = $this->revision($id);
        $this->panelServicios('POST', "$id/publicacion/retirar", [], ['If-Match' => $revision])->assertOk();
        $this->panelServicios('PUT', (string) $id, ['nombre' => $this->datos()['nombre']], ['If-Match' => $revision])->assertOk();
        $this->panelServicios('POST', "$id/estado", ['estado' => 'activo'], ['If-Match' => $revision])->assertOk();
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
        $this->panelServicios('POST', "$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $revision])->assertStatus(500);
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
        $request = Request::create("/api/panel/v1/servicios/servicios/$id/publicacion/autorizar", 'POST');
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
        $this->panelServicios('DELETE', (string) $id)->assertStatus(405);
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
                // Savepoint por caso: cada restricción se comprueba por sí misma también en PostgreSQL.
                DB::transaction($escribir);
                $this->fail('La escritura inválida debía rechazarse.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_campos_inmutables_nulos_se_rechazan_y_el_estado_solo_cambia_en_su_ruta(): void
    {
        $id = $this->crear();
        $revision = $this->revision($id);
        $this->panelServicios('PUT', (string) $id, ['estado' => null], ['If-Match' => $revision])->assertUnprocessable();
        $this->panelServicios('PUT', (string) $id, ['codigo' => null], ['If-Match' => $revision])->assertUnprocessable();
        $this->panelServicios('POST', '', [...$this->datos(), 'codigo' => 'TEC-002', 'identificador_publico' => null])->assertUnprocessable();
        $this->panelServicios('PATCH', (string) $id, ['estado' => 'inactivo'], ['If-Match' => $revision])->assertStatus(405);
        $this->assertDatabaseHas('servicios_tecnologicos', ['id' => $id, 'estado' => 'activo']);
        $this->assertSame($revision, $this->revision($id));
    }

    public function test_lector_no_muta_y_publicador_delegado_no_recibe_edicion(): void
    {
        $id = $this->crear();
        $this->preparar($id);
        $lector = $this->usuario('secretaria', ['panel.servicios.acceder', 'servicios.ver']);
        $this->actingAs($lector);
        $revision = $this->revision($id);
        $this->panelServicios('GET', (string) $id)->assertOk();
        foreach ([['PUT', "$id", ['nombre' => 'Cambio']],
            ['POST', "$id/estado", ['estado' => 'inactivo']],
            ['PUT', "$id/publicacion", $this->ficha()],
            ['POST', "$id/publicacion/autorizar", ['confirmacion_publica' => true]],
            ['POST', "$id/publicacion/retirar", []],
        ] as [$method, $url, $body]) {
            $this->panelServicios($method, $url, $body, ['If-Match' => $revision])->assertForbidden();
        }
        $lector->givePermissionTo('servicios.publicar');
        $this->publicar($id);
        $this->panelServicios('PUT', (string) $id, ['nombre' => 'Cambio'], ['If-Match' => $this->revision($id)])->assertForbidden();
        $this->assertDatabaseHas('servicio_publicaciones', ['autorizado_por' => $lector->id, 'estado' => 'publicada']);
    }

    public function test_codigo_normalizado_por_servidor_y_etag_de_otro_servicio_rechazado(): void
    {
        $id = $this->crear();
        $this->panelServicios('POST', '', [...$this->datos(), 'codigo' => 'tec-001'])->assertUnprocessable();
        $otro = $this->panelServicios('POST', '', [...$this->datos(), 'codigo' => 'tec-002'])->assertCreated()->assertJsonPath('data.codigo', 'TEC-002')->json('data.id');
        $this->panelServicios('PUT', (string) $id, ['nombre' => 'Cambio'], ['If-Match' => $this->revision($otro)])->assertStatus(412);
        foreach (['W/"servicio-1-v1"', '"servicio-1-v1", "servicio-1-v2"', ''] as $cabecera) {
            $this->panelServicios('PUT', (string) $id, ['nombre' => 'Cambio'], ['If-Match' => $cabecera])->assertUnprocessable();
        }
        // Un intento de inyección en el filtro no se interpreta: se ignora y el listado sigue completo.
        $this->panelServicios('GET', '?estado=activo%27%20OR%201=1--')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.filtros', []);
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
        Schema::dropIfExists('servicio_publicacion_elementos');
        Schema::drop('servicio_publicaciones');
        $this->assertSame([], $lector->pagina()['servicios']);
        $this->assertDatabaseCount('servicios_tecnologicos', 0);
    }

    public function test_lista_vacia_y_acceso_solo_con_entrada_y_permiso_de_lectura(): void
    {
        $this->panelServicios('GET')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);

        // Entrada al panel sin `servicios.ver`: no lista.
        $sinLectura = $this->usuario('coordinador', ['panel.servicios.acceder']);
        $this->actingAs($sinLectura);
        $this->panelServicios('GET')->assertForbidden();
        $sinLectura->givePermissionTo('servicios.ver');
        $this->panelServicios('GET')->assertOk();

        // Editor y publicador son independientes: ninguno concede el permiso del otro.
        $editor = $this->usuario('coordinador', ['panel.servicios.acceder', 'servicios.ver', 'servicios.editar']);
        $this->actingAs($editor);
        $id = $this->crearServicioComoAdmin();
        $this->actingAs($editor);
        $this->panelServicios('PUT', "$id/publicacion", $this->ficha(), ['If-Match' => $this->revision($id)])->assertOk();
        $this->panelServicios('POST', "$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertForbidden();
        $editor->revokePermissionTo('servicios.editar');
        $editor->givePermissionTo('servicios.publicar');
        $this->panelServicios('PUT', "$id/publicacion", $this->ficha(), ['If-Match' => $this->revision($id)])->assertForbidden();
        $this->panelServicios('POST', "$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revision($id)])->assertOk();
    }

    /** Alta con el administrador y vuelta al usuario anterior. */
    private function crearServicioComoAdmin(): int
    {
        $anterior = auth()->user();
        $this->actingAs(User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->firstOrFail());
        $id = $this->crear();
        $this->actingAs($anterior);

        return $id;
    }
}
