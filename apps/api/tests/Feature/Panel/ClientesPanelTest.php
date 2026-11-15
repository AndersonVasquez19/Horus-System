<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Clientes del panel de servicios (`/api/panel/v1/servicios/clientes`, Ola 3): permisos, validación, documento
 * único, privacidad (listado enmascarado, sin id interno, `no-store`), revisión `If-Match` y auditoría sin datos
 * personales. SQLite en memoria; los `CHECK` de PostgreSQL no se ejercitan aquí.
 */
final class ClientesPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/clientes'.($ruta === '' ? '' : '/'.$ruta), $datos);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return [
            'tipo' => 'persona', 'nombre' => 'Marta Ficticia Pérez', 'documento_tipo' => 'dni', 'documento_numero' => '70000001',
            'email' => 'marta@example.test', 'telefono' => '987 654 321', 'direccion' => 'Calle Ficticia 123',
            ...$cambios,
        ];
    }

    private function crear(User $admin, array $cambios = []): Cliente
    {
        $respuesta = $this->como($admin, 'POST', '', $this->datos($cambios))->assertCreated();

        return Cliente::query()->where('identificador', $respuesta->json('data.identificador'))->firstOrFail();
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_el_personal_academico_es_403(): void
    {
        $this->como(null, 'GET')->assertUnauthorized();

        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->como($this->usuarioConRol($rol, $rol.'@horus.test'), 'GET')->assertForbidden();
            $this->como(User::query()->where('email', $rol.'@horus.test')->first(), 'POST', '', $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Cliente::query()->count());
    }

    public function test_un_admin_sin_el_permiso_concreto_no_puede_esa_accion(): void
    {
        $admin = $this->admin();
        $cliente = $this->crear($admin);

        // El permiso llega por el rol: se quita del rol (no de la cuenta) y se vacía la caché de Spatie.
        $rol = Role::findByName('admin');
        $rol->revokePermissionTo('clientes.crear');
        $this->como($admin->fresh(), 'POST', '', $this->datos(['documento_numero' => '70000002']))->assertForbidden();

        $rol->revokePermissionTo('clientes.editar');
        $this->como($admin->fresh(), 'PUT', $cliente->identificador, $this->datos(['nombre' => 'Otro']) + ['_revision' => $cliente->etag()])->assertForbidden();
        $this->como($admin->fresh(), 'POST', $cliente->identificador.'/estado', ['estado' => 'inactivo', '_revision' => $cliente->etag()])->assertForbidden();
        $this->assertSame('Marta Ficticia Pérez', $cliente->fresh()->nombre);

        $rol->revokePermissionTo('clientes.ver');
        $this->como($admin->fresh(), 'GET')->assertForbidden();
        $this->como($admin->fresh(), 'GET', $cliente->identificador)->assertForbidden();
    }

    // ---------------------------------------------------------------- alta y privacidad

    public function test_crear_normaliza_fija_el_responsable_y_audita_sin_datos_personales(): void
    {
        $admin = $this->admin();

        $respuesta = $this->como($admin, 'POST', '', $this->datos([
            'email' => '  MARTA@Example.TEST ', 'documento_numero' => ' 7000 0001 ',
            'contactos' => [['nombre' => 'Contacto Uno', 'email' => 'UNO@example.test', 'principal' => true]],
        ]))->assertCreated();

        $respuesta->assertJsonPath('data.email', 'marta@example.test')
            ->assertJsonPath('data.documento_numero', '70000001')
            ->assertJsonPath('data.estado', 'activo')
            ->assertJsonPath('data.responsable.id', $admin->id)
            ->assertJsonPath('data.contactos.0.email', 'uno@example.test')
            ->assertJsonMissingPath('data.id')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue(Str::isUuid($respuesta->json('data.identificador')));
        $this->assertStringStartsWith('"cliente-', (string) $respuesta->headers->get('ETag'));

        $cliente = Cliente::query()->sole();
        $fila = AuditLog::query()->where('action', 'cliente_creado')->sole();
        $this->assertSame($cliente->id, $fila->model_id);
        $this->assertSame($admin->id, $fila->user_id);
        $volcado = (string) json_encode($fila->getAttributes());
        foreach (['Marta', 'example.test', '70000001', '987', 'Calle'] as $personal) {
            $this->assertStringNotContainsString($personal, $volcado);
        }
    }

    public function test_el_listado_enmascara_correo_y_documento_no_expone_el_id_y_filtra(): void
    {
        $admin = $this->admin();
        $this->crear($admin, ['nombre' => 'Ana 100% Real', 'documento_numero' => '70000010', 'email' => 'ana@example.test']);
        $this->crear($admin, ['nombre' => 'Anita Pérez', 'documento_numero' => '70000011', 'email' => 'anita@example.test']);
        $empresa = $this->crear($admin, ['tipo' => 'empresa', 'nombre' => 'Redes SAC', 'documento_tipo' => 'ruc', 'documento_numero' => '20123456789', 'email' => 'ventas@example.test']);
        $this->como($admin, 'POST', $empresa->identificador.'/estado', ['estado' => 'inactivo', '_revision' => $empresa->etag()])->assertOk();

        $lista = $this->como($admin, 'GET')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(3, $lista->json('data'));
        $texto = (string) $lista->getContent();
        foreach (['ana@example.test', 'ventas@example.test', '70000010', '20123456789'] as $completo) {
            $this->assertStringNotContainsString($completo, $texto);
        }
        $primera = $lista->json('data.0');
        $this->assertSame(['identificador', 'tipo', 'nombre', 'documento_tipo', 'documento', 'email', 'estado', 'responsable'], array_keys($primera));
        $this->assertStringEndsWith('010', (string) $lista->json('data.0.documento'));

        $this->assertSame(['Redes SAC'], $this->como($admin, 'GET', '', ['estado' => 'inactivo'])->json('data.*.nombre'));
        $this->assertSame(['Redes SAC'], $this->como($admin, 'GET', '', ['tipo' => 'empresa'])->json('data.*.nombre'));
        $this->assertSame(['Ana 100% Real'], $this->como($admin, 'GET', '', ['busqueda' => '%'])->json('data.*.nombre'));
        $this->assertSame(['Ana 100% Real'], $this->como($admin, 'GET', '', ['busqueda' => 'ana'])->json('data.*.nombre'));
        $this->assertSame(['Anita Pérez'], $this->como($admin, 'GET', '', ['busqueda' => 'anita@'])->json('data.*.nombre'));
        $this->assertSame(['Ana 100% Real'], $this->como($admin, 'GET', '', ['busqueda' => '70000010'])->json('data.*.nombre'));
        // Filtros con forma de arreglo no rompen nada.
        $this->como($admin, 'GET', '', ['busqueda' => ['x'], 'estado' => ['x'], 'tipo' => ['x']])->assertOk();
    }

    public function test_el_detalle_entrega_los_datos_completos_con_el_contacto_principal_primero(): void
    {
        $admin = $this->admin();
        $cliente = $this->crear($admin, ['tipo' => 'empresa', 'nombre' => 'Redes SAC', 'documento_tipo' => 'ruc', 'documento_numero' => '20123456789', 'contactos' => [
            ['nombre' => 'Segundo', 'principal' => false],
            ['nombre' => 'Primero', 'principal' => true],
        ]]);

        $detalle = $this->como($admin, 'GET', $cliente->identificador)->assertOk();
        $detalle->assertJsonPath('data.documento_numero', '20123456789')
            ->assertJsonPath('data.contactos.0.nombre', 'Primero')
            ->assertJsonPath('data.contactos.1.nombre', 'Segundo')
            ->assertJsonPath('data.revision', $cliente->etag())
            ->assertHeader('ETag', $cliente->etag());

        $this->como($admin, 'GET', 'no-es-un-uuid')->assertNotFound();
        $this->como($admin, 'GET', (string) Str::uuid())->assertNotFound();
    }

    // ---------------------------------------------------------------- validación

    public function test_validacion_de_documento_tipo_y_contactos(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'POST', '', $this->datos(['documento_numero' => '123']))->assertJsonValidationErrors('documento_numero');
        $this->como($admin, 'POST', '', $this->datos(['documento_tipo' => 'ruc', 'documento_numero' => '70000001']))->assertJsonValidationErrors('documento_numero');
        $this->como($admin, 'POST', '', $this->datos(['tipo' => 'empresa']))->assertJsonValidationErrors('documento_tipo');
        $this->como($admin, 'POST', '', $this->datos(['documento_tipo' => null]))->assertJsonValidationErrors('documento_tipo');
        $this->como($admin, 'POST', '', $this->datos(['documento_numero' => null]))->assertJsonValidationErrors('documento_numero');
        $this->como($admin, 'POST', '', $this->datos(['tipo' => 'otra']))->assertJsonValidationErrors('tipo');
        $this->como($admin, 'POST', '', $this->datos(['nombre' => '<b>x</b>']))->assertJsonValidationErrors('nombre');
        $this->como($admin, 'POST', '', $this->datos(['nombre' => ['x']]))->assertJsonValidationErrors('nombre');
        $this->como($admin, 'POST', '', $this->datos(['email' => 'no-es-correo']))->assertJsonValidationErrors('email');
        $this->como($admin, 'POST', '', $this->datos(['telefono' => 'abc']))->assertJsonValidationErrors('telefono');
        $this->como($admin, 'POST', '', $this->datos(['contactos' => [['nombre' => 'A', 'principal' => true], ['nombre' => 'B', 'principal' => true]]]))
            ->assertJsonValidationErrors('contactos');
        $this->como($admin, 'POST', '', $this->datos(['contactos' => array_fill(0, 11, ['nombre' => 'X'])]))->assertJsonValidationErrors('contactos');
        $this->como($admin, 'POST', '', $this->datos(['contactos' => [['cargo' => 'sin nombre']]]))->assertJsonValidationErrors('contactos.0.nombre');
        $this->assertSame(0, Cliente::query()->count());
    }

    public function test_un_cliente_sin_documento_es_valido_y_los_campos_reservados_se_rechazan(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'POST', '', $this->datos(['documento_tipo' => null, 'documento_numero' => null, 'email' => null]))
            ->assertCreated()->assertJsonPath('data.documento_numero', null);
        // Varios clientes sin documento conviven: los nulos no colisionan.
        $this->como($admin, 'POST', '', $this->datos(['nombre' => 'Otro Sin Documento', 'documento_tipo' => null, 'documento_numero' => null]))->assertCreated();

        foreach (['estado' => 'inactivo', 'revision' => 9, 'identificador' => (string) Str::uuid(), 'responsable_id' => 1] as $campo => $valor) {
            $this->como($admin, 'POST', '', $this->datos([$campo => $valor, 'documento_numero' => '70000099']))->assertJsonValidationErrors($campo);
        }
    }

    public function test_el_documento_es_unico_tambien_frente_a_clientes_inactivos(): void
    {
        $admin = $this->admin();
        $primero = $this->crear($admin);
        $this->como($admin, 'POST', $primero->identificador.'/estado', ['estado' => 'inactivo', '_revision' => $primero->etag()])->assertOk();

        $this->como($admin, 'POST', '', $this->datos(['nombre' => 'Otra Persona']))->assertJsonValidationErrors('documento_numero');
        $this->assertSame(1, Cliente::query()->count());

        $segundo = $this->crear($admin, ['nombre' => 'Segunda', 'documento_numero' => '70000002']);
        $this->como($admin, 'PUT', $segundo->identificador, $this->datos(['nombre' => 'Segunda']) + ['_revision' => $segundo->etag()])
            ->assertJsonValidationErrors('documento_numero');
        // Conservar el propio documento no es un duplicado.
        $this->como($admin, 'PUT', $segundo->identificador, $this->datos(['documento_numero' => '70000002', 'nombre' => 'Segunda Editada']) + ['_revision' => $segundo->etag()])->assertOk();
    }

    // ---------------------------------------------------------------- revisión, edición y estado

    public function test_actualizar_exige_la_revision_y_detecta_cambios_ajenos(): void
    {
        $admin = $this->admin();
        $cliente = $this->crear($admin);
        $cambio = $this->datos(['nombre' => 'Nombre Nuevo']);

        $this->como($admin, 'PUT', $cliente->identificador, $cambio)->assertStatus(428);
        $this->como($admin, 'PUT', $cliente->identificador, $cambio + ['_revision' => 'basura'])->assertUnprocessable();
        $this->como($admin, 'PUT', $cliente->identificador, $cambio + ['_revision' => '"cliente-'.$cliente->identificador.'-v9"'])->assertStatus(412);
        $this->assertSame('Marta Ficticia Pérez', $cliente->fresh()->nombre);

        $ok = $this->como($admin, 'PUT', $cliente->identificador, $cambio + ['_revision' => $cliente->etag()])->assertOk();
        $ok->assertJsonPath('data.nombre', 'Nombre Nuevo');
        $this->assertSame(2, $cliente->fresh()->revision);

        // La revisión anterior ya no sirve.
        $this->como($admin, 'PUT', $cliente->identificador, $cambio + ['_revision' => $cliente->etag()])->assertStatus(412);
    }

    public function test_sin_cambios_no_avanza_la_revision_ni_audita(): void
    {
        $admin = $this->admin();
        $cliente = $this->crear($admin);

        $this->como($admin, 'PUT', $cliente->identificador, $this->datos() + ['_revision' => $cliente->etag()])->assertOk();

        $this->assertSame(1, $cliente->fresh()->revision);
        $this->assertSame(0, AuditLog::query()->where('action', 'cliente_actualizado')->count());
    }

    public function test_los_contactos_se_reemplazan_solo_cuando_el_cuerpo_los_trae(): void
    {
        $admin = $this->admin();
        $cliente = $this->crear($admin, ['contactos' => [['nombre' => 'Uno', 'principal' => true], ['nombre' => 'Dos']]]);

        // Sin `contactos`: se conservan.
        $this->como($admin, 'PUT', $cliente->identificador, $this->datos(['nombre' => 'Editado']) + ['_revision' => $cliente->etag()])
            ->assertOk()->assertJsonCount(2, 'data.contactos');

        // Con `contactos`: se sustituyen todos; una lista vacía los quita.
        $cliente->refresh();
        $this->como($admin, 'PUT', $cliente->identificador, $this->datos(['nombre' => 'Editado', 'contactos' => [['nombre' => 'Tres', 'principal' => true]]]) + ['_revision' => $cliente->etag()])
            ->assertOk()->assertJsonCount(1, 'data.contactos')->assertJsonPath('data.contactos.0.nombre', 'Tres');
        $cliente->refresh();
        $this->como($admin, 'PUT', $cliente->identificador, $this->datos(['nombre' => 'Editado', 'contactos' => []]) + ['_revision' => $cliente->etag()])
            ->assertOk()->assertJsonCount(0, 'data.contactos');
        $this->assertSame(0, $cliente->contactos()->count());
    }

    public function test_cambiar_el_estado_es_idempotente_y_valida_el_valor(): void
    {
        $admin = $this->admin();
        $cliente = $this->crear($admin);

        $this->como($admin, 'POST', $cliente->identificador.'/estado', ['estado' => 'archivado', '_revision' => $cliente->etag()])->assertJsonValidationErrors('estado');
        $this->como($admin, 'POST', $cliente->identificador.'/estado', ['estado' => 'inactivo'])->assertStatus(428);

        $this->como($admin, 'POST', $cliente->identificador.'/estado', ['estado' => 'inactivo', '_revision' => $cliente->etag()])
            ->assertOk()->assertJsonPath('data.estado', 'inactivo');
        $cliente->refresh();
        $this->assertSame(2, $cliente->revision);

        // Repetir el mismo estado con la revisión vigente no avanza ni audita de nuevo.
        $this->como($admin, 'POST', $cliente->identificador.'/estado', ['estado' => 'inactivo', '_revision' => $cliente->etag()])->assertOk();
        $this->assertSame(2, $cliente->fresh()->revision);
        $this->assertSame(1, AuditLog::query()->where('action', 'cliente_estado_actualizado')->count());
        $this->assertSame('inactivo', AuditLog::query()->where('action', 'cliente_estado_actualizado')->sole()->changes['estado']);
    }

    public function test_el_responsable_solo_puede_ser_un_admin_con_permiso(): void
    {
        $admin = $this->admin();
        $cliente = $this->crear($admin);
        $otroAdmin = User::factory()->create();
        $otroAdmin->assignRole('admin');
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');

        $this->como($admin, 'POST', $cliente->identificador.'/responsable', ['responsable_id' => $secretaria->id, '_revision' => $cliente->etag()])
            ->assertJsonValidationErrors('responsable_id');
        $this->como($admin, 'POST', $cliente->identificador.'/responsable', ['responsable_id' => 999999, '_revision' => $cliente->etag()])
            ->assertJsonValidationErrors('responsable_id');

        $this->como($admin, 'POST', $cliente->identificador.'/responsable', ['responsable_id' => $otroAdmin->id, '_revision' => $cliente->etag()])
            ->assertOk()->assertJsonPath('data.responsable.id', $otroAdmin->id);
        $cliente->refresh();
        $this->como($admin, 'POST', $cliente->identificador.'/responsable', ['responsable_id' => null, '_revision' => $cliente->etag()])
            ->assertOk()->assertJsonPath('data.responsable', null);

        $opciones = $this->como($admin, 'GET', 'opciones/responsables')->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$admin->id, $otroAdmin->id], $opciones);
        $this->assertNotContains($secretaria->id, $opciones);
    }
}
