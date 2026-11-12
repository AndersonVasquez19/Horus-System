<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\CotizacionHistorial;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Escritura del panel de servicios tecnológicos: servicios, ficha pública y cotizaciones (GestionServiciosTecnologicos
 * y GestionCotizaciones, con la revisión en `_revision` y la idempotencia en `_clave`).
 */
final class ServiciosEscrituraPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/'.$ruta, $datos);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    private function servicio(string $codigo = 'REDES', string $estado = 'activo'): ServicioTecnologico
    {
        return ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => "Servicio {$codigo}",
            'categoria' => 'redes', 'estado' => $estado, 'revision' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function ficha(array $cambios = []): array
    {
        return [
            'titulo' => 'Redes', 'resumen' => 'Resumen público', 'descripcion' => 'Descripción pública del servicio.',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(), ...$cambios,
        ];
    }

    /** @return array<string, mixed> */
    private function solicitud(array $cambios = []): array
    {
        return [
            'contacto_nombre' => 'Cliente Manual', 'necesidad_cliente' => 'Cablear dos pisos.', 'contacto_email' => 'cliente@example.test',
            '_clave' => (string) Str::uuid(), ...$cambios,
        ];
    }

    public function test_alta_edicion_y_estado_de_un_servicio_con_revision(): void
    {
        $admin = $this->admin();

        $creado = $this->como($admin, 'POST', 'servicios', ['codigo' => 'cctv-1', 'nombre' => 'CCTV', 'categoria' => 'seguridad'])
            ->assertCreated()->assertJsonPath('data.codigo', 'CCTV-1')->assertJsonPath('data.estado', 'inactivo')->assertJsonPath('data.estado_editorial', 'sin_ficha');
        $id = $creado->json('data.id');
        $revision = $creado->json('data.revision');
        $this->assertSame(1, AuditLog::query()->where('action', 'servicio_creado')->count());

        $this->como($admin, 'POST', 'servicios', ['codigo' => 'CCTV-1', 'nombre' => 'Otro'])->assertUnprocessable()->assertJsonValidationErrors(['codigo']);

        // Sin revisión, no se escribe (428); con una vieja, 412.
        $this->como($admin, 'PUT', "servicios/{$id}", ['nombre' => 'CCTV 2'])->assertStatus(428);
        $editado = $this->como($admin, 'PUT', "servicios/{$id}", ['nombre' => 'CCTV 2', '_revision' => $revision])->assertOk()->assertJsonPath('data.nombre', 'CCTV 2');
        $this->como($admin, 'PUT', "servicios/{$id}", ['nombre' => 'CCTV 3', '_revision' => $revision])->assertStatus(412);

        $this->como($admin, 'POST', "servicios/{$id}/estado", ['estado' => 'activo', '_revision' => $editado->json('data.revision')])
            ->assertOk()->assertJsonPath('data.estado', 'activo');
        $this->como($admin, 'POST', "servicios/{$id}/estado", ['estado' => 'inventado', '_revision' => $editado->json('data.revision')])->assertUnprocessable();
    }

    public function test_ficha_borrador_autorizar_y_retirar(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicio();
        $revision = $this->como($admin, 'GET', "servicios/{$servicio->id}")->assertOk()->json('data.revision');

        $borrador = $this->como($admin, 'PUT', "servicios/{$servicio->id}/publicacion", [...$this->ficha(), '_revision' => $revision])
            ->assertOk()->assertJsonPath('data.estado_editorial', 'borrador')->assertJsonPath('data.ficha.titulo', 'Redes');

        // Autorizar exige la confirmación explícita.
        $this->como($admin, 'POST', "servicios/{$servicio->id}/publicacion/autorizar", ['_revision' => $borrador->json('data.revision')])
            ->assertUnprocessable()->assertJsonValidationErrors(['confirmacion_publica']);

        $publicada = $this->como($admin, 'POST', "servicios/{$servicio->id}/publicacion/autorizar", ['confirmacion_publica' => true, '_revision' => $borrador->json('data.revision')])
            ->assertOk()->assertJsonPath('data.estado_editorial', 'publicada');
        $this->assertSame(1, AuditLog::query()->where('action', 'servicio_publicado')->count());

        // Con la ficha publicada no se edita su contenido.
        $this->como($admin, 'PUT', "servicios/{$servicio->id}/publicacion", [...$this->ficha(), '_revision' => $publicada->json('data.revision')])->assertStatus(409);

        $this->como($admin, 'POST', "servicios/{$servicio->id}/publicacion/retirar", ['_revision' => $publicada->json('data.revision')])
            ->assertOk()->assertJsonPath('data.estado_editorial', 'retirada');
        $this->assertSame('retirada', ServicioPublicacion::query()->sole()->estado);
    }

    public function test_no_se_publica_un_servicio_inactivo_ni_sin_permiso(): void
    {
        $admin = $this->admin();
        $inactivo = $this->servicio('INACT', 'inactivo');
        $revision = $this->como($admin, 'GET', "servicios/{$inactivo->id}")->json('data.revision');
        $borrador = $this->como($admin, 'PUT', "servicios/{$inactivo->id}/publicacion", [...$this->ficha(), '_revision' => $revision])->assertOk();
        $this->como($admin, 'POST', "servicios/{$inactivo->id}/publicacion/autorizar", ['confirmacion_publica' => true, '_revision' => $borrador->json('data.revision')])->assertStatus(409);

        $lector = User::factory()->create();
        $lector->givePermissionTo(['panel.servicios.acceder', 'servicios.ver']);
        $this->como($lector, 'GET', "servicios/{$inactivo->id}")->assertOk();
        $this->como($lector, 'POST', 'servicios', ['codigo' => 'X1', 'nombre' => 'X'])->assertForbidden();
        $this->como($lector, 'PUT', "servicios/{$inactivo->id}", ['nombre' => 'Y', '_revision' => $revision])->assertForbidden();
        $this->como($lector, 'POST', "servicios/{$inactivo->id}/publicacion/autorizar", ['confirmacion_publica' => true])->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'servicio_publicado')->count());
    }

    public function test_alta_manual_de_cotizacion_es_idempotente_y_exige_contacto(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicio();
        $datos = $this->solicitud(['servicio_uuid' => $servicio->identificador_publico]);

        $nueva = $this->como($admin, 'POST', 'cotizaciones', $datos)->assertCreated()
            ->assertJsonPath('data.origen', 'administrativo')->assertJsonPath('data.estado', 'recibida')
            ->assertJsonPath('data.servicio', $servicio->nombre)->assertJsonPath('data.historial.0.evento', 'creada');
        $this->como($admin, 'POST', 'cotizaciones', $datos)->assertOk()->assertJsonPath('data.codigo', $nueva->json('data.codigo'));
        $this->assertSame(1, SolicitudCotizacion::query()->count());

        $this->como($admin, 'POST', 'cotizaciones', [...$this->solicitud(), 'contacto_email' => null, 'contacto_telefono' => null])->assertUnprocessable();
        $this->como($admin, 'POST', 'cotizaciones', [...$this->solicitud(), 'estado' => 'en_revision'])->assertUnprocessable();
        $this->como($admin, 'POST', 'cotizaciones', [...$this->solicitud(), '_clave' => 'no-es-uuid'])->assertUnprocessable();
        $this->assertSame(1, SolicitudCotizacion::query()->count());
    }

    public function test_gestion_de_estado_responsable_seguimiento_y_historial(): void
    {
        $admin = $this->admin();
        $codigo = $this->como($admin, 'POST', 'cotizaciones', $this->solicitud())->assertCreated();
        $id = $codigo->json('data.identificador');
        $revision = $codigo->json('data.revision');

        $this->como($admin, 'POST', "cotizaciones/{$id}/estado", ['estado' => 'en_revision'])->assertStatus(428);
        $revisada = $this->como($admin, 'POST', "cotizaciones/{$id}/estado", ['estado' => 'en_revision', '_revision' => $revision])
            ->assertOk()->assertJsonPath('data.estado', 'en_revision');
        $this->como($admin, 'POST', "cotizaciones/{$id}/estado", ['estado' => 'recibida', '_revision' => $revisada->json('data.revision')])->assertStatus(409);
        $this->como($admin, 'POST', "cotizaciones/{$id}/estado", ['estado' => 'en_revision', '_revision' => $revision])->assertStatus(412);

        $asignada = $this->como($admin, 'POST', "cotizaciones/{$id}/responsable", ['responsable_id' => $admin->id, '_revision' => $revisada->json('data.revision')])
            ->assertOk()->assertJsonPath('data.responsable.id', $admin->id);
        $this->como($admin, 'POST', "cotizaciones/{$id}/responsable", ['responsable_id' => 999999, '_revision' => $asignada->json('data.revision')])->assertUnprocessable();

        $seguimiento = $this->como($admin, 'POST', "cotizaciones/{$id}/seguimientos", [
            'nota' => 'Llamé al cliente.', 'proximo_seguimiento' => '2026-12-01', '_clave' => (string) Str::uuid(), '_revision' => $asignada->json('data.revision'),
        ])->assertOk()->assertJsonPath('data.proximo_seguimiento', '2026-12-01');
        $this->assertSame('seguimiento_registrado', $seguimiento->json('data.historial.0.evento'));
        $this->assertSame('Llamé al cliente.', $seguimiento->json('data.historial.0.nota'));
        $this->assertSame($admin->name, $seguimiento->json('data.historial.0.actor'));
        $this->assertSame(4, CotizacionHistorial::query()->count());
    }

    public function test_edicion_de_datos_y_opciones_de_los_selectores(): void
    {
        $admin = $this->admin();
        $creada = $this->como($admin, 'POST', 'cotizaciones', $this->solicitud())->assertCreated();
        $id = $creada->json('data.identificador');

        $this->como($admin, 'PUT', "cotizaciones/{$id}", ['contacto_nombre' => 'Nombre Corregido', '_revision' => $creada->json('data.revision')])
            ->assertOk()->assertJsonPath('data.contacto.nombre', 'Nombre Corregido');

        $this->servicio('ACT');
        $this->servicio('ARC', 'archivado');
        $this->como($admin, 'GET', 'cotizaciones/opciones/servicios')->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', 'cotizaciones/opciones/responsables')->assertOk()->assertJsonPath('data.0.id', $admin->id);
    }

    public function test_cotizaciones_siguen_siendo_solo_del_admin_y_cada_accion_exige_su_permiso(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->admin();
        $creada = $this->como($admin, 'POST', 'cotizaciones', $this->solicitud())->assertCreated();
        $id = $creada->json('data.identificador');

        $coordinador = User::factory()->create();
        $coordinador->assignRole('coordinador');
        $coordinador->givePermissionTo(['panel.servicios.acceder', 'cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.gestionar']);
        $this->como($coordinador, 'GET', "cotizaciones/{$id}")->assertForbidden();
        $this->como($coordinador, 'POST', 'cotizaciones', $this->solicitud())->assertForbidden();

        $this->como($admin, 'GET', 'cotizaciones/no-es-uuid')->assertNotFound();
        $this->como($admin, 'GET', 'cotizaciones/'.Str::uuid())->assertNotFound();
        $this->assertSame(1, SolicitudCotizacion::query()->count());
    }
}
