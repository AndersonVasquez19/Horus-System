<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\CotizacionHistorial;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Services\CatalogoPublicoServicios;
use App\Services\GestionCotizaciones;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Contrato de la bandeja de cotizaciones en /api/panel/v1/servicios/cotizaciones. Sustituye a `CotizacionTest` y a
 * `PanelCotizacionesTest`, que ejercitaban el módulo Blade retirado el 14/11/2026: idempotencia, revisión `If-Match`,
 * revalidación de permisos, rollback, minimización de datos y restricciones SQL. Las cabeceras `If-Match` e
 * `Idempotency-Key` siguen siendo válidas (el panel las acepta además de `_revision`/`_clave`); lo que cambia es la
 * ruta, el verbo de las gestiones (POST en vez de PATCH) y la forma de las respuestas (`data.*`).
 *
 * Las pruebas de la capa de servicio (GestionCotizaciones) y de esquema no dependían de la ruta y se conservan igual.
 */
class CotizacionesContratoPanelTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite :memory: fuera del perfil; conexión protegida de la lista fija dentro de PostgreSQL Testing.
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'America/Lima'));
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['horus-web.service_token' => self::TOKEN]);
        $this->actingAs($this->adminUser());
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    private function panel(string $metodo, string $ruta, array $datos = [], array $cabeceras = []): TestResponse
    {
        return $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/'.ltrim($ruta, '/'), $datos, $cabeceras);
    }

    /** @return array<string, string> */
    private function datos(): array
    {
        return ['contacto_nombre' => 'Persona ficticia', 'contacto_email' => 'persona@example.test',
            'necesidad_cliente' => 'Necesidad sintética privada sin compromiso comercial.'];
    }

    /** @param array<string, mixed> $datos */
    private function crear(array $datos = []): string
    {
        return $this->panel('POST', 'cotizaciones', [...$this->datos(), ...$datos], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.identificador');
    }

    private function etag(string $uuid): string
    {
        return (string) $this->panel('GET', "cotizaciones/$uuid")->assertOk()->headers->get('ETag');
    }

    /** Sesión nueva del mismo usuario (la del panel caduca por vida absoluta; el control no se relaja). */
    private function reiniciarSesion(): void
    {
        $usuario = auth()->user();
        $this->flushSession();
        $this->actingAs($usuario);
    }

    private function servicio(): ServicioTecnologico
    {
        return ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => 'DEMO-19', 'nombre' => 'Servicio sintético original',
            'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);
    }

    public function test_creacion_detalle_e_historial_sin_efectos_externos(): void
    {
        $uuid = $this->crear();
        $detalle = $this->panel('GET', "cotizaciones/$uuid")->assertOk()->assertJsonPath('data.estado', 'recibida')
            ->assertJsonPath('data.necesidad', $this->datos()['necesidad_cliente'])
            ->assertJsonPath('data.servicio_uuid', null)
            ->assertJsonCount(1, 'data.historial')->assertJsonPath('data.historial.0.evento', 'creada')
            ->assertJsonMissingPath('data.historial.0.actor_ambito');
        $this->assertStringContainsString('no-store', (string) $detalle->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/\A"cotizacion-.*-v1"\z/', (string) $detalle->headers->get('ETag'));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('cotizacion_historial', 1);
        foreach (['creacion_huella', 'creacion_clave_hash', 'creador_ambito', 'servicio_id'] as $campo) {
            $detalle->assertJsonMissingPath('data.'.$campo);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'nombre ausente' => [['contacto_nombre' => null]];
        yield 'necesidad vacía' => [['necesidad_cliente' => '']];
        yield 'necesidad larga' => [['necesidad_cliente' => str_repeat('a', 5001)]];
        yield 'sin contacto' => [['contacto_email' => null]];
        yield 'correo inválido' => [['contacto_email' => 'invalido']];
        yield 'teléfono inválido' => [['contacto_telefono' => '<script>']];
        yield 'html' => [['necesidad_cliente' => '<script>alert(1)</script>']];
        yield 'servicio inexistente' => [['servicio_uuid' => '12345678-1234-4234-8234-123456789012']];
        yield 'uuid inválido' => [['servicio_uuid' => '1 OR 1=1']];
        yield 'estado suministrado' => [['estado' => 'recibida']];
        yield 'responsable suministrado' => [['responsable_id' => null]];
        yield 'identidad suministrada' => [['identificador' => null]];
        yield 'snapshot suministrado' => [['servicio_nombre_historico' => 'Inventado']];
        yield 'id interno' => [['servicio_id' => 1]];
    }

    /** @param array<string, mixed> $datos */
    #[DataProvider('entradasInvalidas')]
    public function test_creacion_rechaza_entrada_invalida_sin_escrituras(array $datos): void
    {
        $this->panel('POST', 'cotizaciones', [...$this->datos(), ...$datos], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
        $this->assertDatabaseCount('cotizacion_historial', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_telefono_solo_y_mass_assignment_de_campos_no_validados(): void
    {
        $uuid = $this->crear(['contacto_email' => null, 'contacto_telefono' => '+00 000 000 000',
            'creador_ambito' => 999, 'creacion_huella' => 'forjada', 'precio' => 100, 'recibida_at' => '2000-01-01']);
        $this->panel('GET', "cotizaciones/$uuid")->assertOk()->assertJsonPath('data.contacto.email', null);
        $solicitud = SolicitudCotizacion::firstOrFail();
        $this->assertSame(auth()->id(), $solicitud->creador_ambito);
        $this->assertNotSame('forjada', $solicitud->creacion_huella);
        $this->assertSame('2026-10-29', $solicitud->recibida_at->toDateString());
    }

    public function test_creacion_idempotente_distintas_intenciones_y_alcance_por_operador(): void
    {
        $clave = (string) Str::uuid();
        $primera = $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertCreated();
        $uuid = $primera->json('data.identificador');
        $this->panel('POST', 'cotizaciones', array_reverse($this->datos(), true), ['Idempotency-Key' => $clave])
            ->assertOk()->assertJsonPath('data.identificador', $uuid);
        $this->panel('POST', 'cotizaciones', [...$this->datos(), 'necesidad_cliente' => 'Otra necesidad'], ['Idempotency-Key' => $clave])->assertConflict();
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->crear(); // Mismo contacto y cuerpo con otra intención: legítimo, no deduplicar por persona.
        $otro = User::factory()->create();
        $otro->assignRole('admin');
        $otro->givePermissionTo('cotizaciones.crear');
        $this->actingAs($otro)->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertCreated();
        $this->assertDatabaseCount('solicitudes_cotizacion', 3);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_clave_ausente_o_invalida_no_crea(): void
    {
        foreach ([null, '', 'abc', str_repeat('x', 500)] as $clave) {
            $this->panel('POST', 'cotizaciones', $this->datos(), $clave === null ? [] : ['Idempotency-Key' => $clave])
                ->assertUnprocessable()->assertJsonValidationErrors('idempotencia');
        }
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
    }

    public function test_el_panel_acepta_revision_y_clave_en_el_cuerpo_como_el_bff(): void
    {
        $clave = (string) Str::uuid();
        $uuid = $this->panel('POST', 'cotizaciones', [...$this->datos(), '_clave' => $clave])->assertCreated()->json('data.identificador');
        $this->panel('POST', 'cotizaciones', [...$this->datos(), '_clave' => $clave])->assertOk()->assertJsonPath('data.identificador', $uuid);
        $this->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => 'Por cuerpo', '_revision' => $this->etag($uuid)])
            ->assertOk()->assertJsonPath('data.contacto.nombre', 'Por cuerpo');
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
    }

    public function test_snapshot_administrativo_inmutable_servicio_retirado_y_fk_historica(): void
    {
        $servicio = $this->servicio();
        $uuid = $this->crear(['servicio_uuid' => $servicio->identificador_publico]);
        $servicio->update(['nombre' => 'Nombre corregido', 'estado' => 'archivado']);
        $this->panel('GET', "cotizaciones/$uuid")->assertOk()->assertJsonPath('data.servicio', 'Servicio sintético original');
        $this->panel('PUT', "cotizaciones/$uuid", ['servicio_uuid' => null], ['If-Match' => $this->etag($uuid)])->assertConflict();
        $this->crear(['servicio_uuid' => $servicio->identificador_publico]); // Alta privada sobre servicio histórico, sin promesa de disponibilidad.
        try {
            // Savepoint: en PostgreSQL la violación no aborta la transacción de la prueba.
            DB::transaction(fn () => $servicio->delete());
            $this->fail('Debe conservar la relación histórica.');
        } catch (QueryException) {
            $this->assertDatabaseCount('solicitudes_cotizacion', 2);
        }
    }

    public function test_asociar_posteriormente_y_actualizar_contacto_sin_perder_historia(): void
    {
        $uuid = $this->crear();
        $servicio = $this->servicio();
        $etag = $this->etag($uuid);
        $this->panel('PUT', "cotizaciones/$uuid", ['servicio_uuid' => $servicio->identificador_publico,
            'contacto_nombre' => 'Nombre ficticio corregido'], ['If-Match' => $etag])->assertOk();
        $this->panel('GET', "cotizaciones/$uuid")->assertOk()->assertJsonPath('data.servicio_uuid', $servicio->identificador_publico)
            ->assertJsonPath('data.contacto.nombre', 'Nombre ficticio corregido');
        $this->panel('PUT', "cotizaciones/$uuid", ['contacto_email' => null], ['If-Match' => $this->etag($uuid)])->assertUnprocessable();
        $this->assertDatabaseCount('cotizacion_historial', 2);
        $this->assertSame('informacion_actualizada', CotizacionHistorial::orderByDesc('id')->first()->evento);
    }

    public function test_transicion_unidireccional_y_repeticiones_sin_cambios(): void
    {
        $uuid = $this->crear();
        $primera = $this->etag($uuid);
        $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => 'recibida'], ['If-Match' => $primera])->assertOk();
        $this->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => $this->datos()['contacto_nombre']], ['If-Match' => $primera])->assertOk();
        $this->assertSame($primera, $this->etag($uuid));
        $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => 'en_revision'], ['If-Match' => $primera])->assertOk();
        $actual = $this->etag($uuid);
        $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => 'en_revision'], ['If-Match' => $actual])->assertOk();
        $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => 'recibida'], ['If-Match' => $actual])->assertConflict();
        foreach (['cotizada', 'aceptada', 'rechazada', 'cancelada', 'cerrada'] as $estado) {
            $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => $estado], ['If-Match' => $actual])->assertUnprocessable();
        }
        $this->assertDatabaseCount('cotizacion_historial', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_responsables_autorizados_asignacion_desasignacion_y_eliminacion_de_usuario(): void
    {
        $uuid = $this->crear();
        $otro = User::factory()->create();
        $otro->assignRole('secretaria');
        $this->panel('POST', "cotizaciones/$uuid/responsable", ['responsable_id' => $otro->id], ['If-Match' => $this->etag($uuid)])->assertUnprocessable();
        $otro->givePermissionTo('cotizaciones.ver');
        $this->panel('POST', "cotizaciones/$uuid/responsable", ['responsable_id' => $otro->id], ['If-Match' => $this->etag($uuid)])->assertUnprocessable();
        $otro->assignRole('admin');
        $this->panel('POST', "cotizaciones/$uuid/responsable", ['responsable_id' => $otro->id], ['If-Match' => $this->etag($uuid)])->assertOk();
        $etag = $this->etag($uuid);
        $this->panel('POST', "cotizaciones/$uuid/responsable", ['responsable_id' => $otro->id], ['If-Match' => $etag])->assertOk();
        $this->assertSame($etag, $this->etag($uuid));
        $this->panel('POST', "cotizaciones/$uuid/responsable", ['responsable_id' => null], ['If-Match' => $etag])->assertOk();
        $this->panel('POST', "cotizaciones/$uuid/responsable", ['responsable_id' => 999999], ['If-Match' => $this->etag($uuid)])->assertUnprocessable();
        $this->assertDatabaseCount('cotizacion_historial', 3);
    }

    public function test_seguimientos_idempotentes_huellas_y_revision_obsoleta(): void
    {
        $uuid = $this->crear();
        $vieja = $this->etag($uuid);
        $clave = (string) Str::uuid();
        $datos = ['nota' => 'Nota sintética privada de seguimiento.', 'proximo_seguimiento' => '2026-11-02'];
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $vieja])->assertUnprocessable();
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $vieja, 'Idempotency-Key' => $clave])->assertOk();
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $vieja, 'Idempotency-Key' => $clave])->assertStatus(412);
        $actual = $this->etag($uuid);
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $actual, 'Idempotency-Key' => $clave])->assertOk();
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", ['nota' => 'Otra nota'], ['If-Match' => $actual, 'Idempotency-Key' => $clave])->assertConflict();
        $this->assertSame($actual, $this->etag($uuid));
        // El historial del detalle va del evento más reciente al más antiguo.
        $detalle = $this->panel('GET', "cotizaciones/$uuid")->assertOk()->assertJsonCount(2, 'data.historial')
            ->assertJsonPath('data.historial.0.nota', $datos['nota'])->assertJsonPath('data.historial.1.evento', 'creada')
            ->assertJsonPath('data.proximo_seguimiento', '2026-11-02');
        foreach (['huella', 'clave_hash', 'actor_ambito'] as $campo) {
            $detalle->assertJsonMissingPath('data.historial.0.'.$campo);
        }
        $this->assertDatabaseCount('cotizacion_historial', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertStringNotContainsString('Nota sintética', AuditLog::all()->toJson());
        $this->assertStringNotContainsString('persona@example.test', AuditLog::all()->toJson());
    }

    public function test_segundo_operador_obsoleto_no_sobrescribe_y_etag_estricto(): void
    {
        $uuid = $this->crear();
        $vieja = $this->etag($uuid);
        $this->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => 'Cambio A'])->assertStatus(428);
        foreach (['*', 'W/'.$vieja, $vieja.', '.$vieja, ''] as $etag) {
            $this->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => 'Cambio A'], ['If-Match' => $etag])->assertUnprocessable();
        }
        $this->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => 'Cambio A'], ['If-Match' => $vieja])->assertOk();
        $otro = User::factory()->create();
        $otro->assignRole('admin');
        $this->actingAs($otro)->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => 'Cambio B'], ['If-Match' => $vieja])->assertStatus(412);
        $this->panel('GET', "cotizaciones/$uuid")->assertJsonPath('data.contacto.nombre', 'Cambio A');
        $this->assertDatabaseCount('audit_logs', 2);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, string}> */
    public static function operaciones(): iterable
    {
        yield 'listar' => ['GET', 'cotizaciones', [], 'cotizaciones.ver'];
        yield 'detalle' => ['GET', 'cotizaciones/{uuid}', [], 'cotizaciones.ver'];
        yield 'crear' => ['POST', 'cotizaciones', [], 'cotizaciones.crear'];
        yield 'editar' => ['PUT', 'cotizaciones/{uuid}', ['contacto_nombre' => 'Cambio'], 'cotizaciones.editar'];
        yield 'estado' => ['POST', 'cotizaciones/{uuid}/estado', ['estado' => 'en_revision'], 'cotizaciones.gestionar'];
        yield 'responsable' => ['POST', 'cotizaciones/{uuid}/responsable', ['responsable_id' => null], 'cotizaciones.gestionar'];
        yield 'nota' => ['POST', 'cotizaciones/{uuid}/seguimientos', ['nota' => 'Nota'], 'cotizaciones.gestionar'];
    }

    /** @param array<string, mixed> $datos */
    #[DataProvider('operaciones')]
    public function test_autenticacion_roles_y_permiso_especifico(string $metodo, string $ruta, array $datos, string $permiso): void
    {
        $uuid = $this->crear();
        $ruta = str_replace('{uuid}', $uuid, $ruta);
        $cabeceras = ['If-Match' => $this->etag($uuid), 'Idempotency-Key' => (string) Str::uuid()];
        if ($metodo === 'POST' && $ruta === 'cotizaciones') {
            $datos = $this->datos();
        }
        $historia = CotizacionHistorial::count();
        $auditoria = AuditLog::count();
        foreach (['coordinador', 'secretaria', null] as $rol) {
            $usuario = User::factory()->create();
            if ($rol !== null) {
                $usuario->assignRole($rol);
            }
            $usuario->givePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
            $this->actingAs($usuario)->panel($metodo, $ruta, $datos, $cabeceras)->assertForbidden();
            $this->assertTrue($usuario->fresh()->hasPermissionTo($permiso));
        }
        $this->assertSame($historia, CotizacionHistorial::count());
        $this->assertSame($auditoria, AuditLog::count());
        Role::findByName('admin')->revokePermissionTo($permiso);
        $usuario = User::factory()->create();
        $usuario->assignRole('admin');
        $this->actingAs($usuario)->panel($metodo, $ruta, $datos, $cabeceras)->assertForbidden();
        $usuario->givePermissionTo($permiso);
        $this->actingAs($usuario)->panel($metodo, $ruta, $datos, $cabeceras)->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->panel($metodo, $ruta, $datos, $cabeceras)->assertUnauthorized();
    }

    public function test_creador_sin_lectura_no_recibe_datos_actuales_al_repetir_y_servicios_no_otorga_acceso(): void
    {
        $creador = User::factory()->create();
        $creador->assignRole('admin');
        Role::findByName('admin')->revokePermissionTo('cotizaciones.ver');
        $creador->givePermissionTo('cotizaciones.crear');
        $admin = auth()->user();
        $clave = (string) Str::uuid();
        $primera = $this->actingAs($creador)->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertCreated();
        $uuid = $primera->json('data.identificador');
        // Sin `cotizaciones.ver` la respuesta solo confirma identidad: ni contacto, ni necesidad, ni historial.
        $this->assertSame(['identificador', 'codigo'], array_keys($primera->json('data')));
        $primera->assertJsonMissingPath('data.necesidad')->assertJsonMissingPath('data.contacto')->assertJsonMissingPath('data.historial');
        $this->panel('GET', "cotizaciones/$uuid")->assertForbidden();
        $this->actingAs($admin)->panel('PUT', "cotizaciones/$uuid", ['necesidad_cliente' => 'Nota privada posterior'],
            ['If-Match' => SolicitudCotizacion::where('identificador', $uuid)->firstOrFail()->etag()])->assertOk();
        $repetida = $this->actingAs($creador)->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertOk();
        $this->assertSame($primera->json('data'), $repetida->json('data'));
        $this->assertStringNotContainsString('Nota privada posterior', (string) $repetida->getContent());
        $this->panel('GET', 'cotizaciones')->assertForbidden();
        $creador->syncPermissions(RolesAndPermissionsSeeder::SERVICIOS_PERMISSIONS);
        $this->panel('GET', 'cotizaciones')->assertForbidden();
    }

    public function test_quien_edita_o_gestiona_sin_lectura_recibe_solo_la_identidad(): void
    {
        $uuid = $this->crear();
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        Role::findByName('admin')->revokePermissionTo('cotizaciones.ver');
        $actor->givePermissionTo(['cotizaciones.editar', 'cotizaciones.gestionar']);
        $editado = $this->actingAs($actor)->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => 'Editado sin lectura'], ['If-Match' => SolicitudCotizacion::firstOrFail()->etag()])->assertOk();
        $this->assertSame(['identificador', 'codigo'], array_keys($editado->json('data')));
        $gestionado = $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => 'en_revision'], ['If-Match' => SolicitudCotizacion::firstOrFail()->etag()])->assertOk();
        $this->assertSame(['identificador', 'codigo'], array_keys($gestionado->json('data')));
        $this->assertNotNull($gestionado->headers->get('ETag'));
        $this->assertStringNotContainsString('persona@example.test', (string) $gestionado->getContent());
    }

    public function test_lector_y_acceso_comercial_exigen_admin_y_lectura_aunque_existan_todos_los_permisos(): void
    {
        $uuid = $this->crear();
        // Un lector solo lee: ninguna escritura.
        $lector = User::factory()->create();
        $lector->assignRole('admin');
        Role::findByName('admin')->revokePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
        $lector->givePermissionTo('cotizaciones.ver');
        $this->actingAs($lector)->panel('GET', 'cotizaciones')->assertOk();
        $this->panel('GET', "cotizaciones/$uuid")->assertOk()->assertJsonPath('data.historial.0.evento', 'creada');
        $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => 'en_revision'])->assertForbidden();
        $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        // `crear` o `servicios.ver` en un rol que no es admin no conceden acceso a la bandeja.
        $coordinador = User::factory()->create();
        $coordinador->assignRole('coordinador');
        $coordinador->givePermissionTo(['cotizaciones.crear', 'servicios.ver']);
        $this->actingAs($coordinador)->panel('GET', 'cotizaciones')->assertForbidden();

        foreach (['coordinador', 'secretaria', 'admin'] as $rol) {
            $usuario = User::factory()->create();
            $usuario->assignRole($rol);
            if ($rol === 'admin') {
                Role::findByName('admin')->revokePermissionTo('cotizaciones.ver');
            } else {
                $usuario->givePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
            }
            $this->actingAs($usuario)->panel('GET', 'cotizaciones')->assertForbidden();
        }
    }

    public function test_listado_minimizado_filtros_y_paginacion_sql(): void
    {
        $servicio = $this->servicio();
        $uuid = $this->crear(['servicio_uuid' => $servicio->identificador_publico]);
        $this->panel('POST', "cotizaciones/$uuid/estado", ['estado' => 'en_revision'], ['If-Match' => $this->etag($uuid)])->assertOk();
        $this->crear();
        $this->crear();
        $this->panel('GET', 'cotizaciones?estado=en_revision')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.identificador', $uuid)
            ->assertJsonMissingPath('data.0.contacto')->assertJsonMissingPath('data.0.necesidad')->assertJsonMissingPath('data.0.contacto_email');
        $this->panel('GET', 'cotizaciones?servicio='.$servicio->identificador_publico)->assertJsonPath('meta.total', 1);
        $codigo = SolicitudCotizacion::where('identificador', $uuid)->value('codigo');
        $this->panel('GET', 'cotizaciones?codigo='.$codigo)->assertJsonPath('meta.total', 1);
        $this->panel('GET', 'cotizaciones?desde=2026-10-29&hasta=2026-10-29')->assertJsonPath('meta.total', 3);
        $this->panel('GET', 'cotizaciones?hasta=2026-10-29')->assertOk()->assertJsonPath('meta.total', 3);
        $this->panel('GET', 'cotizaciones?desde=2026-10-30')->assertJsonPath('meta.total', 0);
        DB::enableQueryLog();
        $this->panel('GET', 'cotizaciones?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.paginas', 2);
        $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $this->assertStringContainsString('limit 2 offset 2', $sql);
        // Los valores fuera de contrato se ignoran (convención del panel), no producen un error ni filtran por ellos.
        $this->panel('GET', 'cotizaciones?estado=cotizada&servicio=1&desde=ayer')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonMissingPath('meta.filtros.estado');
    }

    public function test_auditoria_fallida_revierte_expediente_historia_y_clave(): void
    {
        $uuid = $this->crear();
        $etag = $this->etag($uuid);
        Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
            throw new \RuntimeException('Fallo sintético de auditoría');
        });
        $this->panel('PUT', "cotizaciones/$uuid", ['necesidad_cliente' => 'Cambio fallido'], ['If-Match' => $etag])->assertStatus(500);
        $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(500);
        $this->assertSame($etag, $this->etag($uuid));
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('cotizacion_historial', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_revalidacion_de_permiso_revocado_en_escrituras_y_repeticiones(): void
    {
        $uuid = $this->crear();
        $actor = auth()->user();
        $actor->can('cotizaciones.editar');
        $request = Request::create('/api/panel/v1/servicios/cotizaciones/'.$uuid, 'PUT');
        $request->setUserResolver(fn () => $actor);
        $request->headers->set('If-Match', $this->etag($uuid));
        $actor->roles()->first()->revokePermissionTo('cotizaciones.editar');
        try {
            app(GestionCotizaciones::class)->cambiar($request, $uuid, 'editar', ['contacto_nombre' => 'No permitido']);
            $this->fail('Se esperaba rechazo.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_catalogos_no_exponen_expedientes_y_lecturas_no_escriben(): void
    {
        $uuid = $this->crear();
        DB::enableQueryLog();
        $this->panel('GET', 'cotizaciones')->assertOk();
        $this->panel('GET', "cotizaciones/$uuid")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select/i', $query['query']);
        }
        $this->assertDatabaseCount('audit_logs', 1);
        config(['catalogo-servicios.enabled' => true]);
        $this->assertSame([], app(CatalogoPublicoServicios::class)->pagina()['servicios']);
        $this->get('/tecnologias/soporte-mantenimiento')->assertOk()->assertDontSee('persona@example.test')->assertDontSee('Necesidad sintética');
        $this->getJson('/cotizaciones')->assertNotFound();
        $this->postJson('/api/cotizaciones', $this->datos())->assertNotFound();
        $this->panel('DELETE', "cotizaciones/$uuid")->assertStatus(405);
        $this->panel('GET', 'cotizaciones/999999')->assertNotFound();
        $this->assertTrue(config('catalogo-publico.enabled'));
        $this->assertFalse(config('chatbot.public_catalog_enabled'));
        $this->assertFalse(config('horus-advisor.enabled'));
    }

    public function test_migracion_permisos_aditiva_repetible_y_minimo_privilegio(): void
    {
        $coordinador = Role::findByName('coordinador', 'web');
        $secretaria = Role::findByName('secretaria', 'web');
        foreach (RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS as $permiso) {
            $this->assertFalse($coordinador->hasPermissionTo($permiso));
            $this->assertFalse($secretaria->hasPermissionTo($permiso));
        }
        $coordinador->givePermissionTo('cotizaciones.ver');
        $admin = Role::findByName('admin', 'web');
        $admin->givePermissionTo(Permission::findOrCreate('prueba.preservar', 'web'));
        Permission::where('name', 'cotizaciones.crear')->first()->delete();
        $migracion = require database_path('migrations/2026_10_29_000028_add_cotizaciones_permissions.php');
        $migracion->up();
        $migracion->up();
        $this->assertTrue($admin->fresh()->hasPermissionTo('cotizaciones.crear'));
        $this->assertTrue($admin->fresh()->hasPermissionTo('prueba.preservar'));
        $this->assertTrue($coordinador->fresh()->hasPermissionTo('cotizaciones.ver'));
        $this->assertFalse($coordinador->fresh()->hasPermissionTo('cotizaciones.crear'));
        $this->assertSame(4, Permission::whereIn('name', RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS)->count());
    }

    public function test_restricciones_sql_de_esquema(): void
    {
        $uuid = $this->crear();
        $fila = (array) DB::table('solicitudes_cotizacion')->first();
        unset($fila['id']);
        foreach ([fn () => DB::table('solicitudes_cotizacion')->insert($fila),
            fn () => DB::table('solicitudes_cotizacion')->update(['estado' => 'cotizada']),
            fn () => DB::table('solicitudes_cotizacion')->update(['revision' => 0]),
            fn () => DB::table('solicitudes_cotizacion')->update(['contacto_email' => null, 'contacto_telefono' => null]),
            fn () => DB::table('solicitudes_cotizacion')->update(['servicio_uuid' => (string) Str::uuid()]),
            fn () => DB::table('cotizacion_historial')->update(['revision' => 0]),
            fn () => DB::table('cotizacion_historial')->insert((array) DB::table('cotizacion_historial')->first()),
            fn () => DB::table('solicitudes_cotizacion')->where('identificador', $uuid)->delete(),
        ] as $escribir) {
            try {
                // Savepoint por caso: cada restricción se comprueba por sí misma también en PostgreSQL.
                DB::transaction($escribir);
                $this->fail('Se esperaba rechazo SQL.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_idempotencia_conservada_tras_edicion_y_meses_sin_recrear(): void
    {
        $clave = (string) Str::uuid();
        $uuid = $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertCreated()->json('data.identificador');
        $this->panel('PUT', "cotizaciones/$uuid", ['contacto_email' => 'cambio@example.test'], ['If-Match' => $this->etag($uuid)])->assertOk();
        $this->travel(180)->days();
        $this->reiniciarSesion(); // La sesión del panel tiene vida máxima absoluta: tras seis meses se vuelve a entrar.
        $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertOk()->assertJsonPath('data.identificador', $uuid);
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('cotizacion_historial', 2);
        $this->panel('GET', "cotizaciones/$uuid")->assertJsonPath('data.contacto.email', 'cambio@example.test');
    }

    public function test_seguimiento_repetido_con_fecha_pasada_y_claves_distintas_legitimas(): void
    {
        $uuid = $this->crear();
        $clave = (string) Str::uuid();
        $datos = ['nota' => 'Seguimiento sintético', 'proximo_seguimiento' => '2026-10-30'];
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $this->etag($uuid), 'Idempotency-Key' => $clave])->assertOk();
        $this->travel(10)->days();
        $this->reiniciarSesion();
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $this->etag($uuid), 'Idempotency-Key' => $clave])->assertOk();
        $this->assertDatabaseCount('cotizacion_historial', 2);
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $this->etag($uuid), 'Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('cotizacion_historial', 3);
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", ['nota' => 'Otra nota', 'proximo_seguimiento' => null],
            ['If-Match' => $this->etag($uuid), 'Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->panel('GET', "cotizaciones/$uuid")->assertJsonPath('data.proximo_seguimiento', null)
            ->assertJsonCount(4, 'data.historial')->assertJsonPath('data.historial.0.revision', 4)->assertJsonPath('data.historial.3.revision', 1);
    }

    public function test_validacion_seguimiento_actualizacion_y_etag_de_otro_expediente(): void
    {
        $uuid = $this->crear();
        $otro = $this->crear();
        $etag = $this->etag($uuid);
        $this->panel('PUT', "cotizaciones/$uuid", ['contacto_nombre' => 'Cambio'], ['If-Match' => $this->etag($otro)])->assertStatus(412);
        foreach ([['nota' => ''], ['nota' => str_repeat('x', 3001)], ['nota' => '<b>Texto</b>'],
            ['nota' => 'Nota', 'proximo_seguimiento' => '2026-02-30']] as $datos) {
            $this->panel('POST', "cotizaciones/$uuid/seguimientos", $datos, ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        foreach (['codigo', 'revision', 'estado', 'responsable_id', 'servicio_id', 'creado_por'] as $campo) {
            $this->panel('PUT', "cotizaciones/$uuid", [$campo => null], ['If-Match' => $etag])->assertUnprocessable();
        }
        $this->assertSame($etag, $this->etag($uuid));
        $this->assertDatabaseCount('cotizacion_historial', 2);
    }

    public function test_responsable_revocado_y_actor_eliminado_conservan_historial_sin_publicarlo(): void
    {
        $admin = auth()->user();
        $operador = User::factory()->create();
        $operador->assignRole('admin');
        $operador->givePermissionTo(['cotizaciones.ver', 'cotizaciones.crear']);
        $this->actingAs($operador);
        $uuid = $this->crear();
        $this->actingAs($admin)->panel('POST', "cotizaciones/$uuid/responsable", ['responsable_id' => $operador->id], ['If-Match' => $this->etag($uuid)])->assertOk();
        $operador->revokePermissionTo('cotizaciones.ver');
        Role::findByName('admin')->revokePermissionTo('cotizaciones.ver');
        $admin->givePermissionTo('cotizaciones.ver');
        $this->actingAs($operador)->panel('GET', "cotizaciones/$uuid")->assertForbidden();
        $operador->delete();
        $detalle = $this->actingAs($admin)->panel('GET', "cotizaciones/$uuid")->assertOk()->assertJsonPath('data.responsable', null)
            ->assertJsonCount(2, 'data.historial')->assertJsonPath('data.historial.1.actor', null);
        $this->assertStringNotContainsString('actor_id', (string) $detalle->getContent());
        $this->assertNull(SolicitudCotizacion::firstOrFail()->creado_por);
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
    }

    public function test_fallo_historial_revierte_creacion_y_seguimiento_sin_reservar_clave(): void
    {
        $uuid = $this->crear();
        $etag = $this->etag($uuid);
        $clave = (string) Str::uuid();
        Event::listen('eloquent.creating: '.CotizacionHistorial::class, function (): void {
            throw new \RuntimeException('Fallo sintético de historial');
        });
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", ['nota' => 'Intento fallido'], ['If-Match' => $etag, 'Idempotency-Key' => $clave])->assertStatus(500);
        $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertStatus(500);
        $this->assertSame($etag, $this->etag($uuid));
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('cotizacion_historial', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        Event::forget('eloquent.creating: '.CotizacionHistorial::class);
        $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertCreated();
        $this->panel('POST', "cotizaciones/$uuid/seguimientos", ['nota' => 'Intento fallido'], ['If-Match' => $etag, 'Idempotency-Key' => $clave])->assertOk();
    }

    public function test_revalidacion_crear_y_gestionar_sin_confiar_en_actor_precargado(): void
    {
        $uuid = $this->crear();
        $actor = auth()->user();
        $request = Request::create('/api/panel/v1/servicios/cotizaciones', 'POST');
        $request->setUserResolver(fn () => $actor);
        $request->headers->set('If-Match', $this->etag($uuid));
        $request->headers->set('Idempotency-Key', (string) Str::uuid());
        foreach (['cotizaciones.crear', 'cotizaciones.gestionar'] as $permiso) {
            $actor->can($permiso);
            $actor->roles()->first()->revokePermissionTo($permiso);
        }
        foreach ([fn () => app(GestionCotizaciones::class)->crear($request, $this->datos()),
            fn () => app(GestionCotizaciones::class)->cambiar($request, $uuid, 'estado', ['estado' => 'en_revision'])] as $operacion) {
            try {
                $operacion();
                $this->fail('Se esperaba rechazo transaccional.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('solicitudes_cotizacion', ['estado' => 'recibida']);
    }

    public function test_rotacion_de_clave_no_duplica_y_falta_de_configuracion_falla_cerrada(): void
    {
        $clave = (string) Str::uuid();
        $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertCreated();
        config(['app.key' => 'clave-sintetica-alternativa-exclusiva-de-prueba']);
        $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertConflict();
        $request = Request::create('/api/panel/v1/servicios/cotizaciones', 'POST');
        $request->setUserResolver(fn () => auth()->user());
        $request->headers->set('Idempotency-Key', (string) Str::uuid());
        config(['app.key' => null]);
        try {
            app(GestionCotizaciones::class)->crear($request, $this->datos());
            $this->fail('Debe requerir integridad configurada.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_rol_revocado_se_revalida_en_todas_las_mutaciones_y_replay(): void
    {
        $actor = auth()->user();
        $clave = (string) Str::uuid();
        $uuid = $this->panel('POST', 'cotizaciones', $this->datos(), ['Idempotency-Key' => $clave])->assertCreated()->json('data.identificador');
        $solicitud = SolicitudCotizacion::where('identificador', $uuid)->firstOrFail();
        $request = Request::create('/api/panel/v1/servicios/cotizaciones', 'POST');
        $request->setUserResolver(fn () => $actor);
        $request->headers->set('If-Match', $solicitud->etag());
        $request->headers->set('Idempotency-Key', $clave);
        $actor->load('roles', 'permissions');
        $actor->givePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
        // Otra instancia revoca el rol: la instancia precargada conserva admin.
        $actor->fresh()->syncRoles(['coordinador']);
        $this->assertTrue($actor->hasRole('admin'));
        $this->assertTrue($actor->fresh()->can('cotizaciones.crear'));
        $gestion = app(GestionCotizaciones::class);
        $operaciones = [fn () => $gestion->crear($request, $this->datos())];
        foreach (['editar' => ['contacto_nombre' => 'Cambio prohibido'], 'estado' => ['estado' => 'en_revision'],
            'responsable' => ['responsable_id' => null], 'seguimiento' => ['nota' => 'Nota prohibida']] as $operacion => $datos) {
            $operaciones[] = fn () => $gestion->cambiar($request, $uuid, $operacion, $datos);
        }
        foreach ($operaciones as $operar) {
            try {
                $operar();
                $this->fail('Debe rechazar el rol revocado antes de escribir o reconciliar.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $request->headers->set('Idempotency-Key', (string) Str::uuid());
        try {
            $gestion->crear($request, $this->datos());
            $this->fail('Debe rechazar una creación nueva.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($solicitud->getAttributes(), $solicitud->fresh()->getAttributes());
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('cotizacion_historial', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
