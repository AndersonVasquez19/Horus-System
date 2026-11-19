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
}
