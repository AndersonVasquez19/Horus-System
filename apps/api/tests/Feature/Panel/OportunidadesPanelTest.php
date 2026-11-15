<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Oportunidad;
use App\Models\OportunidadHistorial;
use App\Models\Prospecto;
use App\Models\ServicioTecnologico;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Pipeline de oportunidades del panel de servicios (`/api/panel/v1/servicios/oportunidades`, Ola 3): permisos,
 * validación, etapas, historial, tablero, conversión de un prospecto con oportunidad y auditoría sin datos personales.
 */
final class OportunidadesPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = [], string $base = 'oportunidades'): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/'.$base.($ruta === '' ? '' : '/'.$ruta), $datos);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    private function cliente(string $estado = 'activo', string $nombre = 'Cliente Ficticio'): Cliente
    {
        $cliente = new Cliente(['tipo' => 'persona', 'nombre' => $nombre]);
        $cliente->identificador = (string) Str::uuid();
        $cliente->estado = $estado;
        $cliente->revision = 1;
        $cliente->save();

        return $cliente;
    }

    /** @return array<string, mixed> */
    private function datos(Cliente $cliente, array $cambios = []): array
    {
        return ['cliente' => $cliente->identificador, 'titulo' => 'Cableado de la sede', 'monto_estimado' => 1500.5, 'fecha_cierre_estimada' => '2026-12-31', ...$cambios];
    }

    private function crear(User $admin, ?Cliente $cliente = null, array $cambios = []): Oportunidad
    {
        $respuesta = $this->como($admin, 'POST', '', $this->datos($cliente ?? $this->cliente(), $cambios))->assertCreated();

        return Oportunidad::query()->where('identificador', $respuesta->json('data.identificador'))->firstOrFail();
    }

    private function mover(User $admin, Oportunidad $oportunidad, string $etapa, ?string $motivo = null): TestResponse
    {
        return $this->como($admin, 'POST', $oportunidad->identificador.'/etapa', array_filter(
            ['etapa' => $etapa, 'motivo_perdida' => $motivo, '_revision' => $oportunidad->fresh()->etag()],
            fn ($v): bool => $v !== null,
        ));
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_el_personal_academico_es_403(): void
    {
        $this->como(null, 'GET')->assertUnauthorized();
        foreach (['coordinador', 'secretaria'] as $rol) {
            $usuario = $this->usuarioConRol($rol, $rol.'@horus.test');
            $this->como($usuario, 'GET')->assertForbidden();
            $this->como($usuario, 'GET', 'tablero')->assertForbidden();
            $this->como($usuario, 'POST', '', $this->datos($this->cliente()))->assertForbidden();
        }
        $this->assertSame(0, Oportunidad::query()->count());
    }

    public function test_un_admin_sin_el_permiso_concreto_no_puede_esa_accion(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->crear($admin);
        $rol = Role::findByName('admin');

        foreach ([
            ['pipeline.gestionar', fn () => $this->mover($admin->fresh(), $oportunidad, 'calificada')],
            ['pipeline.gestionar', fn () => $this->como($admin->fresh(), 'POST', '', $this->datos($this->cliente()))],
            ['pipeline.ver', fn () => $this->como($admin->fresh(), 'GET', $oportunidad->identificador)],
            ['pipeline.ver', fn () => $this->como($admin->fresh(), 'GET', 'tablero')],
        ] as [$permiso, $llamada]) {
            $rol->revokePermissionTo($permiso);
            $llamada()->assertForbidden();
            $rol->givePermissionTo($permiso);
        }
        $this->assertSame('nueva', $oportunidad->fresh()->etapa);
    }

    // ---------------------------------------------------------------- alta y validación

    public function test_crear_fija_etapa_responsable_historial_y_audita_sin_datos_personales(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente(nombre: 'Marta Ficticia');
        $servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => 'REDES', 'nombre' => 'Redes', 'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);

        $respuesta = $this->como($admin, 'POST', '', $this->datos($cliente, ['servicio' => strtoupper($servicio->identificador_publico), 'titulo' => 'Proyecto Secreto']))->assertCreated();

        $respuesta->assertJsonPath('data.etapa', 'nueva')
            ->assertJsonPath('data.probabilidad', 10)
            ->assertJsonPath('data.monto_estimado', '1500.50')
            ->assertJsonPath('data.cliente.nombre', 'Marta Ficticia')
            ->assertJsonPath('data.servicio.nombre', 'Redes')
            ->assertJsonPath('data.responsable.id', $admin->id)
            ->assertJsonPath('data.historial.0.etapa', 'nueva')
            ->assertJsonMissingPath('data.id')
            ->assertHeader('Cache-Control', 'no-store, private');

        $fila = AuditLog::query()->where('action', 'oportunidad_creada')->sole();
        $volcado = (string) json_encode($fila->getAttributes());
        foreach (['Marta', 'Secreto', 'Cableado'] as $personal) {
            $this->assertStringNotContainsString($personal, $volcado);
        }
        $this->assertSame(1, OportunidadHistorial::query()->count());
    }

    public function test_validaciones_de_alta(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente();

        $this->como($admin, 'POST', '', $this->datos($cliente, ['titulo' => null]))->assertJsonValidationErrors('titulo');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['titulo' => '<b>x</b>']))->assertJsonValidationErrors('titulo');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['monto_estimado' => -1]))->assertJsonValidationErrors('monto_estimado');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['monto_estimado' => 1.234]))->assertJsonValidationErrors('monto_estimado');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['probabilidad' => 101]))->assertJsonValidationErrors('probabilidad');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['fecha_cierre_estimada' => '31/12/2026']))->assertJsonValidationErrors('fecha_cierre_estimada');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['etapa' => 'ganada']))->assertJsonValidationErrors('etapa');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['responsable_id' => 1]))->assertJsonValidationErrors('responsable_id');
        $this->como($admin, 'POST', '', ['titulo' => 'x'])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['cliente' => (string) Str::uuid()]))->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['servicio' => (string) Str::uuid()]))->assertJsonValidationErrors('servicio');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['cotizacion' => (string) Str::uuid()]))->assertJsonValidationErrors('cotizacion');
        $this->assertSame(0, Oportunidad::query()->count());
    }

    public function test_no_se_abre_una_oportunidad_para_un_cliente_inactivo(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'POST', '', $this->datos($this->cliente('inactivo')))->assertJsonValidationErrors('cliente');
        $this->assertSame(0, Oportunidad::query()->count());
    }

    // ---------------------------------------------------------------- edición

    public function test_actualizar_exige_la_revision_no_cambia_el_cliente_y_no_edita_una_cerrada(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->crear($admin);
        $cambios = ['titulo' => 'Cableado ampliado', 'monto_estimado' => 2000, 'probabilidad' => 40, 'fecha_cierre_estimada' => null];

        $this->como($admin, 'PUT', $oportunidad->identificador, $cambios)->assertStatus(428);
        $this->como($admin, 'PUT', $oportunidad->identificador, $cambios + ['_revision' => '"oportunidad-'.$oportunidad->identificador.'-v9"'])->assertStatus(412);
        $this->como($admin, 'PUT', $oportunidad->identificador, $cambios + ['cliente' => $this->cliente()->identificador, '_revision' => $oportunidad->etag()])
            ->assertJsonValidationErrors('cliente');
        $this->como($admin, 'PUT', $oportunidad->identificador, $cambios + ['_revision' => $oportunidad->etag()])
            ->assertOk()->assertJsonPath('data.titulo', 'Cableado ampliado')->assertJsonPath('data.probabilidad', 40)->assertJsonPath('data.fecha_cierre_estimada', null);
        $this->assertSame(2, $oportunidad->fresh()->revision);

        // Sin cambios no avanza la revisión.
        $this->como($admin, 'PUT', $oportunidad->identificador, $cambios + ['_revision' => $oportunidad->fresh()->etag()])->assertOk();
        $this->assertSame(2, $oportunidad->fresh()->revision);

        $this->mover($admin, $oportunidad, 'perdida', 'Sin presupuesto')->assertOk();
        $this->como($admin, 'PUT', $oportunidad->identificador, $cambios + ['_revision' => $oportunidad->fresh()->etag()])->assertStatus(409);
    }

    // ---------------------------------------------------------------- etapas

    public function test_el_recorrido_completo_deja_el_historial_y_la_probabilidad_de_cada_etapa(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->crear($admin);

        foreach ([['calificada', 25], ['propuesta', 50], ['negociacion', 75], ['ganada', 100]] as [$etapa, $probabilidad]) {
            $this->mover($admin, $oportunidad, $etapa)->assertOk()->assertJsonPath('data.etapa', $etapa)->assertJsonPath('data.probabilidad', $probabilidad);
        }

        $ganada = $oportunidad->fresh();
        $this->assertNotNull($ganada->cerrada_at);
        $this->assertSame(
            ['nueva', 'calificada', 'propuesta', 'negociacion', 'ganada'],
            OportunidadHistorial::query()->where('oportunidad_id', $ganada->id)->orderBy('id')->pluck('etapa_nueva')->all(),
        );
        $this->assertSame(['oportunidad_ganada'], AuditLog::query()->where('action', 'oportunidad_ganada')->pluck('action')->all());
        $this->assertSame(3, AuditLog::query()->where('action', 'oportunidad_etapa_cambiada')->count());
    }

    public function test_no_se_salta_ninguna_etapa_ni_se_retrocede(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->crear($admin);

        $this->mover($admin, $oportunidad, 'propuesta')->assertStatus(409);
        $this->mover($admin, $oportunidad, 'ganada')->assertStatus(409);
        $this->mover($admin, $oportunidad, 'calificada')->assertOk();
        $this->mover($admin, $oportunidad, 'nueva')->assertStatus(409);
        $this->mover($admin, $oportunidad, 'inventada')->assertJsonValidationErrors('etapa');
        $this->assertSame('calificada', $oportunidad->fresh()->etapa);
    }

    public function test_perder_exige_motivo_y_reabrir_vuelve_a_nueva_con_historial(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->crear($admin);
        $this->mover($admin, $oportunidad, 'calificada')->assertOk();

        $this->mover($admin, $oportunidad, 'perdida')->assertJsonValidationErrors('motivo_perdida');
        $this->assertSame('calificada', $oportunidad->fresh()->etapa);

        $this->mover($admin, $oportunidad, 'perdida', 'Eligió otro proveedor')->assertOk()
            ->assertJsonPath('data.etapa', 'perdida')->assertJsonPath('data.motivo_perdida', 'Eligió otro proveedor')->assertJsonPath('data.probabilidad', 0);
        $this->assertNotNull($oportunidad->fresh()->cerrada_at);
        $perdida = AuditLog::query()->where('action', 'oportunidad_perdida')->sole();
        $this->assertStringNotContainsString('proveedor', (string) json_encode($perdida->getAttributes()));

        // Una cerrada solo se reabre, y vuelve a `nueva` sin motivo ni fecha de cierre.
        $this->mover($admin, $oportunidad, 'ganada')->assertStatus(409);
        $this->mover($admin, $oportunidad, 'nueva')->assertOk()->assertJsonPath('data.etapa', 'nueva')->assertJsonPath('data.motivo_perdida', null);
        $reabierta = $oportunidad->fresh();
        $this->assertNull($reabierta->cerrada_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'oportunidad_reabierta')->count());
        $this->assertSame('nueva', OportunidadHistorial::query()->where('oportunidad_id', $reabierta->id)->orderByDesc('id')->first()->etapa_nueva);
    }

    public function test_repetir_la_etapa_actual_no_cambia_nada_y_la_revision_obsoleta_es_412(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->crear($admin);
        $vieja = $oportunidad->etag();

        $this->mover($admin, $oportunidad, 'nueva')->assertOk();
        $this->assertSame(1, $oportunidad->fresh()->revision);
        $this->assertSame(1, OportunidadHistorial::query()->count());

        $this->mover($admin, $oportunidad, 'calificada')->assertOk();
        $this->como($admin, 'POST', $oportunidad->identificador.'/etapa', ['etapa' => 'propuesta', '_revision' => $vieja])->assertStatus(412);
        $this->como($admin, 'POST', $oportunidad->identificador.'/etapa', ['etapa' => 'propuesta'])->assertStatus(428);
        $this->assertSame('calificada', $oportunidad->fresh()->etapa);
    }

    public function test_el_responsable_solo_puede_ser_un_admin_con_permiso(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->crear($admin);
        $coordinador = $this->usuarioConRol('coordinador', 'coord@horus.test');

        $this->como($admin, 'POST', $oportunidad->identificador.'/responsable', ['responsable_id' => $coordinador->id, '_revision' => $oportunidad->etag()])
            ->assertJsonValidationErrors('responsable_id');
        $this->como($admin, 'POST', $oportunidad->identificador.'/responsable', ['responsable_id' => null, '_revision' => $oportunidad->etag()])
            ->assertOk()->assertJsonPath('data.responsable', null);
        $this->assertNull($oportunidad->fresh()->responsable_id);
    }

    // ---------------------------------------------------------------- listado, tablero y privacidad

    public function test_el_listado_filtra_pagina_y_oculta_el_cliente_sin_permiso(): void
    {
        $admin = $this->admin();
        $uno = $this->crear($admin, null, ['titulo' => 'Cableado norte']);
        $this->crear($admin, null, ['titulo' => 'Servidor sur']);
        $this->mover($admin, $uno, 'calificada')->assertOk();

        $this->como($admin, 'GET', '?busqueda=Servidor')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.titulo', 'Servidor sur');
        $this->como($admin, 'GET', '?etapa=calificada')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.titulo', 'Cableado norte');
        $this->como($admin, 'GET', '?etapa=inventada')->assertOk()->assertJsonCount(2, 'data');
        $this->como($admin, 'GET', '?cliente='.Cliente::query()->first()->identificador)->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', '')->assertOk()->assertJsonMissingPath('data.0.id')->assertHeader('Cache-Control', 'no-store, private');

        Role::findByName('admin')->revokePermissionTo('clientes.ver');
        $this->como($admin->fresh(), 'GET', '')->assertOk()->assertJsonPath('data.0.cliente', null);
        $this->como($admin->fresh(), 'GET', $uno->identificador)->assertOk()->assertJsonPath('data.cliente', null);
    }

    public function test_el_tablero_agrupa_por_etapa_con_totales_y_montos(): void
    {
        $admin = $this->admin();
        $a = $this->crear($admin, null, ['monto_estimado' => 100]);
        $this->crear($admin, null, ['monto_estimado' => 250.25]);
        $this->mover($admin, $a, 'calificada')->assertOk();

        $respuesta = $this->como($admin, 'GET', 'tablero')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(Oportunidad::ETAPAS, array_column($respuesta->json('data'), 'etapa'));
        $columnas = array_column($respuesta->json('data'), null, 'etapa');
        $this->assertSame([1, '250.25'], [$columnas['nueva']['total'], $columnas['nueva']['monto']]);
        $this->assertSame([1, '100.00'], [$columnas['calificada']['total'], $columnas['calificada']['monto']]);
        $this->assertSame([0, '0.00'], [$columnas['ganada']['total'], $columnas['ganada']['monto']]);
        $this->assertArrayHasKey('revision', $columnas['calificada']['tarjetas'][0]);
        $this->assertArrayNotHasKey('id', $columnas['calificada']['tarjetas'][0]);
    }

    // ---------------------------------------------------------------- prospecto convertido con oportunidad

    private function prospectoCalificado(User $admin, ?string $servicio = null): Prospecto
    {
        $prospecto = $this->como($admin, 'POST', '', [
            'nombre_contacto' => 'Lucía Ficticia', 'email' => 'lucia@example.test', 'origen' => 'referido', 'servicio_interes' => $servicio,
        ], 'prospectos')->assertCreated();
        $modelo = Prospecto::query()->where('identificador', $prospecto->json('data.identificador'))->firstOrFail();
        foreach (['contactado', 'calificado'] as $paso) {
            $this->como($admin, 'POST', $modelo->identificador.'/estado', ['estado' => $paso, '_revision' => $modelo->fresh()->etag()], 'prospectos')->assertOk();
        }

        return $modelo->fresh();
    }

    public function test_convertir_un_prospecto_puede_abrir_la_oportunidad_en_la_misma_operacion(): void
    {
        $admin = $this->admin();
        $servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => 'REDES', 'nombre' => 'Redes', 'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);
        $prospecto = $this->prospectoCalificado($admin, $servicio->identificador_publico);

        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', [
            '_revision' => $prospecto->etag(), 'oportunidad' => ['titulo' => 'Red de la sede', 'monto_estimado' => 900],
        ], 'prospectos')->assertOk()->assertJsonPath('data.estado', 'convertido');

        $oportunidad = Oportunidad::query()->sole();
        $this->assertSame([$prospecto->id, $prospecto->fresh()->cliente_id, $servicio->id, 'nueva'], [$oportunidad->prospecto_id, $oportunidad->cliente_id, $oportunidad->servicio_id, $oportunidad->etapa]);
        $this->assertTrue((bool) AuditLog::query()->where('action', 'prospecto_convertido')->sole()->changes['oportunidad_creada']);

        // Idempotente: repetir la conversión no abre una segunda oportunidad.
        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', [
            '_revision' => $prospecto->etag(), 'oportunidad' => ['titulo' => 'Red de la sede'],
        ], 'prospectos')->assertOk();
        $this->assertSame(1, Oportunidad::query()->count());
        $this->assertSame(1, Cliente::query()->count());
    }

    public function test_sin_pipeline_gestionar_la_conversion_con_oportunidad_no_deja_nada(): void
    {
        $admin = $this->admin();
        $prospecto = $this->prospectoCalificado($admin);
        Role::findByName('admin')->revokePermissionTo('pipeline.gestionar');

        $this->como($admin->fresh(), 'POST', $prospecto->identificador.'/convertir', [
            '_revision' => $prospecto->etag(), 'oportunidad' => ['titulo' => 'Red de la sede'],
        ], 'prospectos')->assertForbidden();

        $this->assertSame('calificado', $prospecto->fresh()->estado);
        $this->assertSame(0, Cliente::query()->count());
        $this->assertSame(0, Oportunidad::query()->count());
    }

    public function test_la_oportunidad_del_prospecto_exige_titulo_y_no_acepta_otros_campos(): void
    {
        $admin = $this->admin();
        $prospecto = $this->prospectoCalificado($admin);

        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag(), 'oportunidad' => ['monto_estimado' => 5]], 'prospectos')
            ->assertJsonValidationErrors('oportunidad.titulo');
        $this->como($admin, 'POST', $prospecto->identificador.'/convertir', ['_revision' => $prospecto->etag(), 'oportunidad' => ['titulo' => 'x', 'etapa' => 'ganada']], 'prospectos')
            ->assertJsonValidationErrors('oportunidad');
        $this->assertSame('calificado', $prospecto->fresh()->estado);
    }
}
