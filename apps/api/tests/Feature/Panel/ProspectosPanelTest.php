<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Prospecto;
use App\Models\ServicioTecnologico;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Prospectos del panel de servicios (`/api/panel/v1/servicios/prospectos`, Ola 3): permisos, validación, transiciones,
 * conversión idempotente a cliente, origen en una cotización, privacidad y auditoría sin datos personales.
 */
final class ProspectosPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/prospectos'.($ruta === '' ? '' : '/'.$ruta), $datos);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return [
            'nombre_contacto' => 'Lucía Ficticia', 'empresa' => null, 'email' => 'lucia@example.test', 'telefono' => '987 654 321',
            'origen' => 'referido', 'servicio_interes' => null, 'proximo_seguimiento' => null, ...$cambios,
        ];
    }

    private function crear(User $admin, array $cambios = []): Prospecto
    {
        $respuesta = $this->como($admin, 'POST', '', $this->datos($cambios))->assertCreated();

        return Prospecto::query()->where('identificador', $respuesta->json('data.identificador'))->firstOrFail();
    }

    /** Lleva el prospecto a un estado con las transiciones válidas y devuelve la copia fresca. */
    private function llevarA(User $admin, Prospecto $prospecto, string $estado): Prospecto
    {
        foreach (['contactado', 'calificado'] as $paso) {
            $this->como($admin, 'POST', $prospecto->identificador.'/estado', ['estado' => $paso, '_revision' => $prospecto->fresh()->etag()])->assertOk();
            if ($paso === $estado) {
                break;
            }
        }

        return $prospecto->fresh();
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_el_personal_academico_es_403(): void
    {
        $this->como(null, 'GET')->assertUnauthorized();
        foreach (['coordinador', 'secretaria'] as $rol) {
            $usuario = $this->usuarioConRol($rol, $rol.'@horus.test');
            $this->como($usuario, 'GET')->assertForbidden();
            $this->como($usuario, 'POST', '', $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Prospecto::query()->count());
    }

    public function test_un_admin_sin_el_permiso_concreto_no_puede_esa_accion(): void
    {
        $admin = $this->admin();
        $prospecto = $this->crear($admin);
        $rol = Role::findByName('admin');

        foreach ([
            ['prospectos.crear', fn () => $this->como($admin->fresh(), 'POST', '', $this->datos())],
            ['prospectos.editar', fn () => $this->como($admin->fresh(), 'POST', $prospecto->identificador.'/estado', ['estado' => 'contactado', '_revision' => $prospecto->etag()])],
            ['prospectos.convertir', fn () => $this->como($admin->fresh(), 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag()])],
            ['prospectos.ver', fn () => $this->como($admin->fresh(), 'GET', $prospecto->identificador)],
        ] as [$permiso, $llamada]) {
            $rol->revokePermissionTo($permiso);
            $llamada()->assertForbidden();
        }
        $this->assertSame('nuevo', $prospecto->fresh()->estado);
    }

    // ---------------------------------------------------------------- alta, listado y validación

    public function test_crear_normaliza_fija_el_responsable_y_audita_sin_datos_personales(): void
    {
        $admin = $this->admin();
        $servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => 'REDES', 'nombre' => 'Redes', 'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);

        $respuesta = $this->como($admin, 'POST', '', $this->datos([
            'email' => ' LUCIA@Example.TEST ', 'servicio_interes' => strtoupper($servicio->identificador_publico), 'proximo_seguimiento' => '2026-12-01',
        ]))->assertCreated();

        $respuesta->assertJsonPath('data.email', 'lucia@example.test')
            ->assertJsonPath('data.estado', 'nuevo')
            ->assertJsonPath('data.servicio_interes.nombre', 'Redes')
            ->assertJsonPath('data.responsable.id', $admin->id)
            ->assertJsonPath('data.proximo_seguimiento', '2026-12-01')
            ->assertJsonPath('data.desde_cotizacion', false)
            ->assertJsonMissingPath('data.id')
            ->assertHeader('Cache-Control', 'no-store, private');

        $fila = AuditLog::query()->where('action', 'prospecto_creado')->sole();
        $volcado = (string) json_encode($fila->getAttributes());
        foreach (['Lucía', 'example.test', '987'] as $personal) {
            $this->assertStringNotContainsString($personal, $volcado);
        }
    }

    public function test_validaciones_de_alta(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'POST', '', $this->datos(['email' => null, 'telefono' => null]))->assertJsonValidationErrors('email');
        $this->como($admin, 'POST', '', $this->datos(['email' => 'no-es-correo']))->assertJsonValidationErrors('email');
        $this->como($admin, 'POST', '', $this->datos(['origen' => 'cotizacion']))->assertJsonValidationErrors('origen');
        $this->como($admin, 'POST', '', $this->datos(['origen' => 'inventado']))->assertJsonValidationErrors('origen');
        $this->como($admin, 'POST', '', $this->datos(['nombre_contacto' => '<b>x</b>']))->assertJsonValidationErrors('nombre_contacto');
        $this->como($admin, 'POST', '', $this->datos(['nombre_contacto' => ['x']]))->assertJsonValidationErrors('nombre_contacto');
        $this->como($admin, 'POST', '', $this->datos(['proximo_seguimiento' => '01/12/2026']))->assertJsonValidationErrors('proximo_seguimiento');
        $this->como($admin, 'POST', '', $this->datos(['servicio_interes' => (string) Str::uuid()]))->assertJsonValidationErrors('servicio_interes');
        foreach (['estado' => 'convertido', 'revision' => 9, 'cliente_id' => 1, 'responsable_id' => 1, 'motivo_descarte' => 'x', 'identificador' => (string) Str::uuid()] as $campo => $valor) {
            $this->como($admin, 'POST', '', $this->datos([$campo => $valor]))->assertJsonValidationErrors($campo);
        }
        $this->assertSame(0, Prospecto::query()->count());
    }

    public function test_el_listado_enmascara_el_correo_no_expone_telefono_ni_id_y_filtra(): void
    {
        $admin = $this->admin();
        $this->crear($admin, ['nombre_contacto' => 'Ana 100% Real', 'email' => 'ana@example.test', 'proximo_seguimiento' => '2020-01-01']);
        $this->crear($admin, ['nombre_contacto' => 'Beto Perez', 'empresa' => 'Redes SAC', 'email' => 'beto@example.test', 'origen' => 'web']);
        $descartado = $this->crear($admin, ['nombre_contacto' => 'Carla Perez', 'email' => 'carla@example.test', 'proximo_seguimiento' => '2020-01-01']);
        $this->como($admin, 'POST', $descartado->identificador.'/estado', ['estado' => 'descartado', 'motivo_descarte' => 'Sin presupuesto', '_revision' => $descartado->etag()])->assertOk();

        $lista = $this->como($admin, 'GET')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $texto = (string) $lista->getContent();
        foreach (['ana@example.test', '987 654 321', 'Sin presupuesto'] as $completo) {
            $this->assertStringNotContainsString($completo, $texto);
        }
        $this->assertSame(['identificador', 'nombre_contacto', 'empresa', 'email', 'origen', 'estado', 'servicio_interes', 'responsable', 'proximo_seguimiento'], array_keys($lista->json('data.0')));

        $this->assertSame(['Carla Perez'], $this->como($admin, 'GET', '', ['estado' => 'descartado'])->json('data.*.nombre_contacto'));
        $this->assertSame(['Beto Perez'], $this->como($admin, 'GET', '', ['origen' => 'web'])->json('data.*.nombre_contacto'));
        $this->assertSame(['Ana 100% Real'], $this->como($admin, 'GET', '', ['busqueda' => '%'])->json('data.*.nombre_contacto'));
        $this->assertSame(['Beto Perez'], $this->como($admin, 'GET', '', ['busqueda' => 'redes'])->json('data.*.nombre_contacto'));
        // Con seguimiento vencido: el descartado ya no cuenta.
        $this->assertSame(['Ana 100% Real'], $this->como($admin, 'GET', '', ['seguimiento_pendiente' => 1])->json('data.*.nombre_contacto'));
        $this->como($admin, 'GET', '', ['busqueda' => ['x'], 'estado' => ['x'], 'origen' => ['x']])->assertOk();
    }

    // ---------------------------------------------------------------- edición y estado

    public function test_actualizar_exige_la_revision_no_avanza_sin_cambios_y_no_edita_un_convertido(): void
    {
        $admin = $this->admin();
        $prospecto = $this->crear($admin);
        $cambio = $this->datos(['nombre_contacto' => 'Lucía Editada']);

        $this->como($admin, 'PUT', $prospecto->identificador, $cambio)->assertStatus(428);
        $this->como($admin, 'PUT', $prospecto->identificador, $cambio + ['_revision' => '"prospecto-'.$prospecto->identificador.'-v9"'])->assertStatus(412);

        $this->como($admin, 'PUT', $prospecto->identificador, $this->datos() + ['_revision' => $prospecto->etag()])->assertOk();
        $this->assertSame(1, $prospecto->fresh()->revision);
        $this->assertSame(0, AuditLog::query()->where('action', 'prospecto_actualizado')->count());

        $this->como($admin, 'PUT', $prospecto->identificador, $cambio + ['_revision' => $prospecto->etag()])->assertOk()->assertJsonPath('data.nombre_contacto', 'Lucía Editada');
        $this->assertSame(2, $prospecto->fresh()->revision);
        $this->como($admin, 'PUT', $prospecto->identificador, $cambio + ['_revision' => $prospecto->etag()])->assertStatus(412);

        $calificado = $this->llevarA($admin, $prospecto, 'calificado');
        $this->como($admin, 'POST', $calificado->identificador.'/convertir', ['_revision' => $calificado->etag()])->assertOk();
        $this->como($admin, 'PUT', $prospecto->identificador, $this->datos(['nombre_contacto' => 'Otra vez']) + ['_revision' => $prospecto->fresh()->etag()])->assertStatus(409);
    }

    public function test_las_transiciones_de_estado_y_el_descarte_con_motivo(): void
    {
        $admin = $this->admin();
        $prospecto = $this->crear($admin);
        $estado = fn (string $nuevo, array $extra = []) => $this->como($admin, 'POST', $prospecto->identificador.'/estado', ['estado' => $nuevo, '_revision' => $prospecto->fresh()->etag()] + $extra);

        $estado('calificado')->assertStatus(409);              // no se salta «contactado»
        $estado('convertido')->assertJsonValidationErrors('estado'); // solo con la conversión
        $estado('inventado')->assertJsonValidationErrors('estado');
        $estado('contactado')->assertOk()->assertJsonPath('data.estado', 'contactado');
        $estado('contactado')->assertOk();                      // repetir el estado actual no cambia nada
        $this->assertSame(2, $prospecto->fresh()->revision);

        $estado('descartado')->assertJsonValidationErrors('motivo_descarte');
        $estado('descartado', ['motivo_descarte' => '   '])->assertJsonValidationErrors('motivo_descarte');
        $estado('descartado', ['motivo_descarte' => '<b>x</b>'])->assertJsonValidationErrors('motivo_descarte');
        $estado('descartado', ['motivo_descarte' => 'Sin presupuesto'])->assertOk()->assertJsonPath('data.motivo_descarte', 'Sin presupuesto');

        $estado('calificado')->assertStatus(409);               // un descartado solo se reabre
        $estado('nuevo')->assertOk()->assertJsonPath('data.estado', 'nuevo')->assertJsonPath('data.motivo_descarte', null);
        $this->assertNull($prospecto->fresh()->motivo_descarte);

        $fila = AuditLog::query()->where('action', 'prospecto_estado_actualizado')->latest('id')->first();
        $this->assertStringNotContainsString('Sin presupuesto', (string) json_encode($fila->getAttributes()));
        $this->como($admin, 'POST', $prospecto->identificador.'/estado', ['estado' => 'contactado'])->assertStatus(428);
    }

    public function test_el_responsable_solo_puede_ser_un_admin_con_permiso(): void
    {
        $admin = $this->admin();
        $prospecto = $this->crear($admin);
        $otro = User::factory()->create();
        $otro->assignRole('admin');
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');

        $this->como($admin, 'POST', $prospecto->identificador.'/responsable', ['responsable_id' => $secretaria->id, '_revision' => $prospecto->etag()])->assertJsonValidationErrors('responsable_id');
        $this->como($admin, 'POST', $prospecto->identificador.'/responsable', ['responsable_id' => $otro->id, '_revision' => $prospecto->etag()])
            ->assertOk()->assertJsonPath('data.responsable.id', $otro->id);
        $this->assertEqualsCanonicalizing([$admin->id, $otro->id], $this->como($admin, 'GET', 'opciones/responsables')->assertOk()->json('data.*.id'));
    }

    // ---------------------------------------------------------------- conversión

    public function test_convertir_exige_estar_calificado_y_crea_un_cliente_persona(): void
    {
        $admin = $this->admin();
        $prospecto = $this->crear($admin);

        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag()])->assertStatus(409);
        $this->assertSame(0, Cliente::query()->count());

        $calificado = $this->llevarA($admin, $prospecto, 'calificado');
        $this->como($admin, 'POST', $calificado->identificador.'/convertir')->assertStatus(428);
        $respuesta = $this->como($admin, 'POST', $calificado->identificador.'/convertir', ['_revision' => $calificado->etag()])->assertOk();

        $cliente = Cliente::query()->sole();
        $this->assertSame(['persona', 'Lucía Ficticia', 'lucia@example.test'], [$cliente->tipo, $cliente->nombre, $cliente->email]);
        $respuesta->assertJsonPath('data.estado', 'convertido')->assertJsonPath('data.cliente.identificador', $cliente->identificador);
        $this->assertSame($cliente->id, $prospecto->fresh()->cliente_id);
        $this->assertTrue((bool) AuditLog::query()->where('action', 'prospecto_convertido')->sole()->changes['cliente_creado']);
    }

    public function test_una_empresa_crea_un_cliente_empresa_con_su_contacto_principal(): void
    {
        $admin = $this->admin();
        $prospecto = $this->llevarA($admin, $this->crear($admin, ['empresa' => 'Redes del Sur SAC']), 'calificado');

        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag()])->assertOk();

        $cliente = Cliente::query()->with('contactos')->sole();
        $this->assertSame(['empresa', 'Redes del Sur SAC'], [$cliente->tipo, $cliente->nombre]);
        $this->assertCount(1, $cliente->contactos);
        $this->assertSame('Lucía Ficticia', $cliente->contactos->first()->nombre);
        $this->assertTrue($cliente->contactos->first()->principal);
    }

    public function test_convertir_es_idempotente_y_no_duplica_el_cliente(): void
    {
        $admin = $this->admin();
        $prospecto = $this->llevarA($admin, $this->crear($admin), 'calificado');
        $revisionVieja = $prospecto->etag();

        $primera = $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $revisionVieja])->assertOk();
        // Reintento con la revisión anterior (la respuesta se perdió): mismo resultado, sin segundo cliente ni auditoría.
        $segunda = $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $revisionVieja])->assertOk();

        $this->assertSame($primera->json('data.cliente.identificador'), $segunda->json('data.cliente.identificador'));
        $this->assertSame(1, Cliente::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'prospecto_convertido')->count());
    }

    public function test_convertir_vinculando_un_cliente_existente(): void
    {
        $admin = $this->admin();
        $prospecto = $this->llevarA($admin, $this->crear($admin), 'calificado');
        $existente = new Cliente(['tipo' => 'persona', 'nombre' => 'Cliente Previo']);
        $existente->identificador = (string) Str::uuid();
        $existente->estado = 'activo';
        $existente->revision = 1;
        $existente->save();

        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['cliente' => (string) Str::uuid(), '_revision' => $prospecto->etag()])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['cliente' => $existente->identificador, '_revision' => $prospecto->etag()])
            ->assertOk()->assertJsonPath('data.cliente.nombre', 'Cliente Previo');

        $this->assertSame(1, Cliente::query()->count());
        $this->assertFalse((bool) AuditLog::query()->where('action', 'prospecto_convertido')->sole()->changes['cliente_creado']);
    }

    public function test_no_se_fusiona_con_un_cliente_que_ya_tiene_ese_correo_y_un_fallo_deja_todo_igual(): void
    {
        $admin = $this->admin();
        $prospecto = $this->llevarA($admin, $this->crear($admin), 'calificado');
        $previo = new Cliente(['tipo' => 'persona', 'nombre' => 'Ya Registrada', 'email' => 'lucia@example.test']);
        $previo->identificador = (string) Str::uuid();
        $previo->estado = 'activo';
        $previo->revision = 1;
        $previo->save();

        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag()])->assertJsonValidationErrors('cliente');
        $this->assertSame('calificado', $prospecto->fresh()->estado);
        $this->assertSame(1, Cliente::query()->count());

        // Sin permiso para crear clientes la conversión falla entera: el prospecto no queda a medias.
        Cliente::query()->withTrashed()->forceDelete();
        Role::findByName('admin')->revokePermissionTo('clientes.crear');
        $this->como($admin->fresh(), 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag()])->assertForbidden();
        $this->assertSame('calificado', $prospecto->fresh()->estado);
        $this->assertNull($prospecto->fresh()->cliente_id);
    }

    // ---------------------------------------------------------------- desde una cotización

    private function desdeCotizacion(User $admin, string $cotizacion): TestResponse
    {
        return $this->actingAs($admin)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json('POST', "/api/panel/v1/servicios/cotizaciones/{$cotizacion}/prospecto");
    }

    /** @return array{0: string, 1: string} identificador y uuid del servicio */
    private function cotizacion(User $admin): array
    {
        $servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => 'CAM', 'nombre' => 'Cámaras', 'categoria' => 'seguridad', 'estado' => 'activo', 'revision' => 1,
        ]);
        $respuesta = $this->actingAs($admin)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->json('POST', '/api/panel/v1/servicios/cotizaciones', [
            'contacto_nombre' => 'Rosa Cotiza', 'necesidad_cliente' => 'Instalar cámaras.', 'contacto_email' => 'Rosa@Example.test',
            'servicio_uuid' => $servicio->identificador_publico, '_clave' => (string) Str::uuid(),
        ])->assertCreated();

        return [$respuesta->json('data.identificador'), $servicio->identificador_publico];
    }

    public function test_crear_un_prospecto_desde_una_cotizacion_es_idempotente_y_hereda_el_servicio(): void
    {
        $admin = $this->admin();
        [$cotizacion, $servicio] = $this->cotizacion($admin);

        $primera = $this->desdeCotizacion($admin, $cotizacion)->assertCreated();
        $primera->assertJsonPath('data.origen', 'cotizacion')->assertJsonPath('data.desde_cotizacion', true)
            ->assertJsonPath('data.nombre_contacto', 'Rosa Cotiza')->assertJsonPath('data.email', 'rosa@example.test')
            ->assertJsonPath('data.servicio_interes.identificador', $servicio);

        $segunda = $this->desdeCotizacion($admin, $cotizacion)->assertOk();
        $this->assertSame($primera->json('data.identificador'), $segunda->json('data.identificador'));
        $this->assertSame(1, Prospecto::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'prospecto_creado')->count());
    }

    public function test_desde_una_cotizacion_exige_poder_verla_y_el_identificador_valido(): void
    {
        $admin = $this->admin();
        [$cotizacion] = $this->cotizacion($admin);

        $this->desdeCotizacion($admin, 'no-es-uuid')->assertNotFound();
        $this->desdeCotizacion($admin, (string) Str::uuid())->assertNotFound();

        Role::findByName('admin')->revokePermissionTo('cotizaciones.ver');
        $this->desdeCotizacion($admin->fresh(), $cotizacion)->assertForbidden();
        $this->assertSame(0, Prospecto::query()->count());
    }

    public function test_editar_un_prospecto_de_cotizacion_conserva_su_origen(): void
    {
        $admin = $this->admin();
        [$cotizacion] = $this->cotizacion($admin);
        $creado = $this->desdeCotizacion($admin, $cotizacion)->assertCreated();
        $prospecto = Prospecto::query()->where('identificador', $creado->json('data.identificador'))->firstOrFail();

        $this->como($admin, 'PUT', $prospecto->identificador, $this->datos(['nombre_contacto' => 'Rosa Editada', 'origen' => 'otro']) + ['_revision' => $prospecto->etag()])
            ->assertOk()->assertJsonPath('data.nombre_contacto', 'Rosa Editada')->assertJsonPath('data.origen', 'cotizacion');
        $this->assertSame('cotizacion', $prospecto->fresh()->origen);
    }

    public function test_el_detalle_oculta_el_cliente_a_quien_no_puede_consultarlo(): void
    {
        $admin = $this->admin();
        $prospecto = $this->llevarA($admin, $this->crear($admin), 'calificado');
        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag()])->assertOk();

        Role::findByName('admin')->revokePermissionTo('clientes.ver');
        $this->como($admin->fresh(), 'GET', $prospecto->identificador)->assertOk()->assertJsonPath('data.cliente', null)->assertJsonPath('data.estado', 'convertido');
        $this->como($admin->fresh(), 'GET', 'no-es-un-uuid')->assertNotFound();
    }
}
