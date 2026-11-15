<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\CotizacionHistorial;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Vínculo entre una solicitud de cotización y un cliente (`POST servicios/cotizaciones/{id}/cliente`, Ola 3): vincular a
 * uno existente o crear uno desde los datos de contacto, sin sustituir ni fusionar, con la revisión de la solicitud.
 */
final class CotizacionClientePanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/'.$ruta, $datos);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    /** @return array{0: string, 1: string} identificador y revisión de una solicitud nueva */
    private function solicitud(User $admin, array $cambios = []): array
    {
        $respuesta = $this->como($admin, 'POST', 'cotizaciones', [
            'contacto_nombre' => 'Rosa Contacto', 'necesidad_cliente' => 'Cablear dos pisos.', 'contacto_email' => 'Rosa@Example.test',
            'contacto_telefono' => '987654321', '_clave' => (string) Str::uuid(), ...$cambios,
        ])->assertCreated();

        return [$respuesta->json('data.identificador'), $respuesta->json('data.revision')];
    }

    private function cliente(string $nombre = 'Cliente Existente', ?string $email = null): Cliente
    {
        $cliente = new Cliente(['tipo' => 'persona', 'nombre' => $nombre, 'email' => $email]);
        $cliente->identificador = (string) Str::uuid();
        $cliente->estado = 'activo';
        $cliente->revision = 1;
        $cliente->save();

        return $cliente;
    }

    public function test_vincular_a_un_cliente_existente_avanza_la_revision_y_deja_constancia(): void
    {
        $admin = $this->admin();
        [$id, $revision] = $this->solicitud($admin);
        $cliente = $this->cliente();

        $respuesta = $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $cliente->identificador, '_revision' => $revision])->assertOk();

        $respuesta->assertJsonPath('data.cliente.identificador', $cliente->identificador)->assertJsonPath('data.cliente.nombre', 'Cliente Existente');
        $this->assertNotSame($revision, $respuesta->json('data.revision'));
        $solicitud = SolicitudCotizacion::query()->where('identificador', $id)->sole();
        $this->assertSame($cliente->id, $solicitud->cliente_id);

        $evento = CotizacionHistorial::query()->where('solicitud_id', $solicitud->id)->orderByDesc('id')->first();
        $this->assertSame('informacion_actualizada', $evento->evento);
        $this->assertSame(['cliente_id'], $evento->metadatos['campos']);
        $fila = AuditLog::query()->where('action', 'cotizacion_informacion_actualizada')->latest('id')->first();
        $this->assertStringNotContainsString('Cliente Existente', (string) json_encode($fila->getAttributes()));
    }

    public function test_repetir_el_mismo_vinculo_no_cambia_nada_y_otro_cliente_es_409(): void
    {
        $admin = $this->admin();
        [$id, $revision] = $this->solicitud($admin);
        $uno = $this->cliente('Uno');
        $otro = $this->cliente('Otro');
        $nueva = $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $uno->identificador, '_revision' => $revision])->assertOk()->json('data.revision');

        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $uno->identificador, '_revision' => $nueva])
            ->assertOk()->assertJsonPath('data.revision', $nueva);
        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $otro->identificador, '_revision' => $nueva])->assertStatus(409);
        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['crear_cliente' => true, '_revision' => $nueva])->assertStatus(409);
        $this->assertSame($uno->id, SolicitudCotizacion::query()->where('identificador', $id)->sole()->cliente_id);
    }

    public function test_crear_el_cliente_desde_los_datos_de_contacto(): void
    {
        $admin = $this->admin();
        [$id, $revision] = $this->solicitud($admin);

        $respuesta = $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['crear_cliente' => true, '_revision' => $revision])->assertOk();

        $cliente = Cliente::query()->sole();
        $this->assertSame('Rosa Contacto', $cliente->nombre);
        $this->assertSame('rosa@example.test', $cliente->email);
        $this->assertSame('persona', $cliente->tipo);
        $this->assertSame($admin->id, $cliente->responsable_id);
        $respuesta->assertJsonPath('data.cliente.identificador', $cliente->identificador);
        $this->assertSame(1, AuditLog::query()->where('action', 'cliente_creado')->count());
    }

    public function test_no_se_fusiona_con_un_cliente_que_ya_tiene_ese_correo(): void
    {
        $admin = $this->admin();
        [$id, $revision] = $this->solicitud($admin);
        $this->cliente('Rosa Ya Registrada', 'rosa@example.test');

        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['crear_cliente' => true, '_revision' => $revision])->assertJsonValidationErrors('cliente');
        $this->assertSame(1, Cliente::query()->count());
        $this->assertNull(SolicitudCotizacion::query()->where('identificador', $id)->sole()->cliente_id);
    }

    public function test_validaciones_revision_y_permisos(): void
    {
        $admin = $this->admin();
        [$id, $revision] = $this->solicitud($admin);
        $cliente = $this->cliente();

        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['_revision' => $revision])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => 'no-uuid', '_revision' => $revision])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => (string) Str::uuid(), '_revision' => $revision])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $cliente->identificador])->assertStatus(428);
        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $cliente->identificador, '_revision' => '"cotizacion-'.$id.'-v99"'])->assertStatus(412);

        // Sin `clientes.ver` no se puede vincular uno existente, y sin `clientes.crear` no se puede crear.
        $rol = Role::findByName('admin');
        $rol->revokePermissionTo('clientes.ver');
        $this->como($admin->fresh(), 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $cliente->identificador, '_revision' => $revision])->assertForbidden();
        $rol->revokePermissionTo('clientes.crear');
        $this->como($admin->fresh(), 'POST', "cotizaciones/{$id}/cliente", ['crear_cliente' => true, '_revision' => $revision])->assertForbidden();
        $this->assertNull(SolicitudCotizacion::query()->where('identificador', $id)->sole()->cliente_id);
    }

    public function test_el_detalle_oculta_el_cliente_a_quien_no_puede_consultarlo(): void
    {
        $admin = $this->admin();
        [$id, $revision] = $this->solicitud($admin);
        $cliente = $this->cliente();
        $this->como($admin, 'POST', "cotizaciones/{$id}/cliente", ['cliente' => $cliente->identificador, '_revision' => $revision])->assertOk();

        Role::findByName('admin')->revokePermissionTo('clientes.ver');
        $this->como($admin->fresh(), 'GET', "cotizaciones/{$id}")->assertOk()->assertJsonPath('data.cliente', null);
    }

    public function test_una_solicitud_sin_cliente_lo_muestra_nulo(): void
    {
        $admin = $this->admin();
        [$id] = $this->solicitud($admin);

        $this->como($admin, 'GET', "cotizaciones/{$id}")->assertOk()->assertJsonPath('data.cliente', null);
    }
}
