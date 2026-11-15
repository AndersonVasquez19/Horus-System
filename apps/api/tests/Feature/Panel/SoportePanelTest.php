<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\TicketNota;
use App\Models\TicketSoporte;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Soporte técnico del panel de servicios (`/api/panel/v1/servicios/tickets`, Ola 3): permisos, validación, código
 * secuencial, SLA por prioridad, estados con reapertura acotada, notas de solo lectura y auditoría sin datos personales.
 */
final class SoportePanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/tickets'.($ruta === '' ? '' : '/'.$ruta), $datos);
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

    private function proyecto(Cliente $cliente): Proyecto
    {
        $proyecto = new Proyecto(['nombre' => 'Proyecto']);
        $proyecto->identificador = (string) Str::uuid();
        $proyecto->codigo = 'PRY-2026-'.random_int(1000, 9999);
        $proyecto->cliente_id = $cliente->id;
        $proyecto->estado = 'planificado';
        $proyecto->revision = 1;
        $proyecto->save();

        return $proyecto;
    }

    /** @return array<string, mixed> */
    private function datos(Cliente $cliente, array $cambios = []): array
    {
        return ['cliente' => $cliente->identificador, 'asunto' => 'Sin internet en la sede', 'descripcion' => 'Se cayó el enlace', 'prioridad' => 'alta', 'canal' => 'telefono', ...$cambios];
    }

    private function crear(User $admin, ?Cliente $cliente = null, array $cambios = []): TicketSoporte
    {
        $respuesta = $this->como($admin, 'POST', '', $this->datos($cliente ?? $this->cliente(), $cambios))->assertCreated();

        return TicketSoporte::query()->where('identificador', $respuesta->json('data.identificador'))->firstOrFail();
    }

    private function estado(User $admin, TicketSoporte $ticket, string $estado): TestResponse
    {
        return $this->como($admin, 'POST', $ticket->identificador.'/estado', ['estado' => $estado, '_revision' => $ticket->fresh()->etag()]);
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_el_personal_academico_es_403(): void
    {
        $this->como(null, 'GET')->assertUnauthorized();
        foreach (['coordinador', 'secretaria'] as $rol) {
            $usuario = $this->usuarioConRol($rol, $rol.'@horus.test');
            $this->como($usuario, 'GET')->assertForbidden();
            $this->como($usuario, 'POST', '', $this->datos($this->cliente()))->assertForbidden();
        }
        $this->assertSame(0, TicketSoporte::query()->count());
    }

    public function test_un_admin_sin_el_permiso_concreto_no_puede_esa_accion(): void
    {
        $admin = $this->admin();
        $ticket = $this->crear($admin);
        $rol = Role::findByName('admin');

        foreach ([
            ['soporte.crear', fn () => $this->como($admin->fresh(), 'POST', '', $this->datos($this->cliente()))],
            ['soporte.gestionar', fn () => $this->estado($admin->fresh(), $ticket, 'en_progreso')],
            ['soporte.gestionar', fn () => $this->como($admin->fresh(), 'POST', $ticket->identificador.'/notas', ['texto' => 'x', 'interna' => true])],
            ['soporte.ver', fn () => $this->como($admin->fresh(), 'GET', $ticket->identificador)],
        ] as [$permiso, $llamada]) {
            $rol->revokePermissionTo($permiso);
            $llamada()->assertForbidden();
            $rol->givePermissionTo($permiso);
        }
        $this->assertSame('abierto', $ticket->fresh()->estado);
        $this->assertSame(0, TicketNota::query()->count());
    }

    // ---------------------------------------------------------------- alta, código y SLA

    public function test_crear_fija_estado_codigo_vencimiento_y_audita_sin_datos_personales(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente(nombre: 'Marta Ficticia');
        $anio = CarbonImmutable::now('America/Lima')->format('Y');

        $respuesta = $this->como($admin, 'POST', '', $this->datos($cliente, ['asunto' => 'Asunto Reservado', 'descripcion' => 'Clave: 1234 reservada']))->assertCreated();

        $respuesta->assertJsonPath('data.estado', 'abierto')
            ->assertJsonPath('data.codigo', "SOP-{$anio}-0001")
            ->assertJsonPath('data.prioridad', 'alta')
            ->assertJsonPath('data.cliente.nombre', 'Marta Ficticia')
            ->assertJsonPath('data.asignado', null)
            ->assertJsonPath('data.vencido', false)
            ->assertJsonPath('data.notas', [])
            ->assertJsonMissingPath('data.id')
            ->assertHeader('Cache-Control', 'no-store, private');

        $ticket = TicketSoporte::query()->sole();
        $this->assertEqualsWithDelta(8 * 3600, $ticket->vence_at->diffInSeconds($ticket->created_at, true), 5);

        $volcado = (string) json_encode(AuditLog::query()->where('action', 'ticket_creado')->sole()->getAttributes());
        foreach (['Marta', 'Reservado', 'reservada', '1234'] as $personal) {
            $this->assertStringNotContainsString($personal, $volcado);
        }
    }

    public function test_el_sla_depende_de_la_prioridad_y_cambiarla_recalcula_desde_la_creacion(): void
    {
        $admin = $this->admin();

        foreach (['critica' => 4, 'alta' => 8, 'media' => 24, 'baja' => 72] as $prioridad => $horas) {
            $t = $this->crear($admin, null, ['prioridad' => $prioridad]);
            $this->assertEqualsWithDelta($horas * 3600, $t->vence_at->diffInSeconds($t->created_at, true), 5, $prioridad);
        }

        $ticket = $this->crear($admin, null, ['prioridad' => 'baja']);
        $this->como($admin, 'PUT', $ticket->identificador, $this->datos($ticket->cliente, ['prioridad' => 'critica']) + ['_revision' => $ticket->etag()] + [])
            ->assertStatus(422); // el cliente no se reenvía en la edición
        $cuerpo = ['asunto' => $ticket->asunto, 'prioridad' => 'critica', 'canal' => 'correo', '_revision' => $ticket->etag()];
        $this->como($admin, 'PUT', $ticket->identificador, $cuerpo)->assertOk()->assertJsonPath('data.prioridad', 'critica');
        $actual = $ticket->fresh();
        $this->assertEqualsWithDelta(4 * 3600, $actual->vence_at->diffInSeconds($actual->created_at, true), 5);
    }

    public function test_los_codigos_son_secuenciales_y_no_se_mezclan_con_los_de_proyectos(): void
    {
        $admin = $this->admin();
        $anio = CarbonImmutable::now('America/Lima')->format('Y');

        $uno = $this->crear($admin);
        $dos = $this->crear($admin);
        $uno->delete();
        $tres = $this->crear($admin);

        $this->assertSame(["SOP-{$anio}-0001", "SOP-{$anio}-0002", "SOP-{$anio}-0003"], [$uno->codigo, $dos->codigo, $tres->codigo]);
        $this->assertSame(0, (int) DB::table('secuencias_codigo')->where('tipo', 'PRY')->count());
    }

    public function test_validaciones_de_alta(): void
    {
        $admin = $this->admin();
        $c = $this->cliente();

        $this->como($admin, 'POST', '', $this->datos($c, ['asunto' => null]))->assertJsonValidationErrors('asunto');
        $this->como($admin, 'POST', '', $this->datos($c, ['asunto' => '<b>x</b>']))->assertJsonValidationErrors('asunto');
        $this->como($admin, 'POST', '', $this->datos($c, ['prioridad' => 'urgente']))->assertJsonValidationErrors('prioridad');
        $this->como($admin, 'POST', '', $this->datos($c, ['canal' => 'paloma']))->assertJsonValidationErrors('canal');
        $this->como($admin, 'POST', '', $this->datos($c, ['estado' => 'cerrado']))->assertJsonValidationErrors('estado');
        $this->como($admin, 'POST', '', $this->datos($c, ['asignado_a' => 1]))->assertJsonValidationErrors('asignado_a');
        $this->como($admin, 'POST', '', ['asunto' => 'x', 'prioridad' => 'alta', 'canal' => 'web'])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', '', $this->datos($c, ['cliente' => (string) Str::uuid()]))->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', '', $this->datos($this->cliente('inactivo')))->assertJsonValidationErrors('cliente');
        $this->assertSame(0, TicketSoporte::query()->count());
    }

    public function test_el_proyecto_debe_ser_del_mismo_cliente(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente();
        $propio = $this->proyecto($cliente);
        $ajeno = $this->proyecto($this->cliente());

        $this->como($admin, 'POST', '', $this->datos($cliente, ['proyecto' => $ajeno->identificador]))->assertJsonValidationErrors('proyecto');
        $this->como($admin, 'POST', '', $this->datos($cliente, ['proyecto' => $propio->identificador]))
            ->assertCreated()->assertJsonPath('data.proyecto.codigo', $propio->codigo);

        Role::findByName('admin')->revokePermissionTo('proyectos.ver');
        $this->como($admin->fresh(), 'POST', '', $this->datos($cliente, ['proyecto' => $propio->identificador]))->assertForbidden();
    }

    // ---------------------------------------------------------------- edición y estados

    public function test_actualizar_exige_la_revision_y_no_edita_uno_cerrado(): void
    {
        $admin = $this->admin();
        $ticket = $this->crear($admin);
        $cuerpo = ['asunto' => 'Asunto nuevo', 'descripcion' => null, 'prioridad' => 'alta', 'canal' => 'correo'];

        $this->como($admin, 'PUT', $ticket->identificador, $cuerpo)->assertStatus(428);
        $this->como($admin, 'PUT', $ticket->identificador, $cuerpo + ['_revision' => '"ticket-'.$ticket->identificador.'-v9"'])->assertStatus(412);
        $this->como($admin, 'PUT', $ticket->identificador, $cuerpo + ['_revision' => $ticket->etag()])->assertOk()->assertJsonPath('data.asunto', 'Asunto nuevo');
        $this->assertSame(2, $ticket->fresh()->revision);
        $this->como($admin, 'PUT', $ticket->identificador, $cuerpo + ['_revision' => $ticket->fresh()->etag()])->assertOk();
        $this->assertSame(2, $ticket->fresh()->revision);

        foreach (['en_progreso', 'resuelto', 'cerrado'] as $paso) {
            $this->estado($admin, $ticket, $paso)->assertOk();
        }
        $this->como($admin, 'PUT', $ticket->identificador, $cuerpo + ['_revision' => $ticket->fresh()->etag()])->assertStatus(409);
    }

    public function test_el_ciclo_de_estados_y_sus_fechas(): void
    {
        $admin = $this->admin();
        $ticket = $this->crear($admin);

        $this->estado($admin, $ticket, 'resuelto')->assertStatus(409);
        $this->estado($admin, $ticket, 'cerrado')->assertStatus(409);
        $this->estado($admin, $ticket, 'inventado')->assertJsonValidationErrors('estado');
        $this->estado($admin, $ticket, 'en_progreso')->assertOk();
        $this->estado($admin, $ticket, 'en_espera_cliente')->assertOk();
        $this->estado($admin, $ticket, 'resuelto')->assertStatus(409); // desde la espera se vuelve a en_progreso
        $this->estado($admin, $ticket, 'en_progreso')->assertOk();
        $this->estado($admin, $ticket, 'resuelto')->assertOk()->assertJsonPath('data.cerrado_en', null);
        $this->assertNotNull($ticket->fresh()->resuelto_at);
        $this->estado($admin, $ticket, 'cerrado')->assertOk();
        $this->assertNotNull($ticket->fresh()->cerrado_at);

        // Terminal.
        $this->estado($admin, $ticket, 'en_progreso')->assertStatus(409);
        $this->assertSame(5, AuditLog::query()->where('action', 'ticket_estado_cambiado')->count());
    }

    public function test_reabrir_un_resuelto_solo_dentro_de_siete_dias(): void
    {
        $admin = $this->admin();
        $reciente = $this->crear($admin);
        $this->estado($admin, $reciente, 'en_progreso')->assertOk();
        $this->estado($admin, $reciente, 'resuelto')->assertOk();
        $this->estado($admin, $reciente, 'en_progreso')->assertOk();
        $this->assertNull($reciente->fresh()->resuelto_at);

        $viejo = $this->crear($admin);
        $this->estado($admin, $viejo, 'en_progreso')->assertOk();
        $this->estado($admin, $viejo, 'resuelto')->assertOk();
        TicketSoporte::query()->whereKey($viejo->id)->update(['resuelto_at' => now()->subDays(8)]);
        $this->estado($admin, $viejo, 'en_progreso')->assertStatus(409);
        $this->assertSame('resuelto', $viejo->fresh()->estado);
        // Aun así se puede cerrar.
        $this->estado($admin, $viejo, 'cerrado')->assertOk();
    }

    public function test_repetir_el_estado_no_cambia_nada_y_la_revision_obsoleta_es_412(): void
    {
        $admin = $this->admin();
        $ticket = $this->crear($admin);
        $vieja = $ticket->etag();

        $this->estado($admin, $ticket, 'abierto')->assertOk();
        $this->assertSame(1, $ticket->fresh()->revision);
        $this->estado($admin, $ticket, 'en_progreso')->assertOk();
        $this->como($admin, 'POST', $ticket->identificador.'/estado', ['estado' => 'resuelto', '_revision' => $vieja])->assertStatus(412);
        $this->assertSame('en_progreso', $ticket->fresh()->estado);
    }

    public function test_asignar_solo_a_un_admin_con_permiso_y_no_en_un_cerrado(): void
    {
        $admin = $this->admin();
        $ticket = $this->crear($admin);
        $coordinador = $this->usuarioConRol('coordinador', 'coord@horus.test');

        $this->como($admin, 'POST', $ticket->identificador.'/asignar', ['asignado_a' => $coordinador->id, '_revision' => $ticket->etag()])->assertJsonValidationErrors('asignado_a');
        $this->como($admin, 'POST', $ticket->identificador.'/asignar', ['asignado_a' => $admin->id, '_revision' => $ticket->etag()])
            ->assertOk()->assertJsonPath('data.asignado.id', $admin->id);

        foreach (['en_progreso', 'resuelto', 'cerrado'] as $paso) {
            $this->estado($admin, $ticket, $paso)->assertOk();
        }
        $this->como($admin, 'POST', $ticket->identificador.'/asignar', ['asignado_a' => null, '_revision' => $ticket->fresh()->etag()])->assertStatus(409);
    }

    // ---------------------------------------------------------------- notas

    public function test_las_notas_se_agregan_sin_revision_no_cambian_el_ticket_y_se_auditan_sin_texto(): void
    {
        $admin = $this->admin();
        $ticket = $this->crear($admin);

        $this->como($admin, 'POST', $ticket->identificador.'/notas', ['texto' => 'Reiniciamos el router; clave temporal 998877', 'interna' => true])
            ->assertCreated()->assertJsonPath('data.notas.0.interna', true)->assertJsonPath('data.notas.0.autor', $admin->name);
        $this->como($admin, 'POST', $ticket->identificador.'/notas', ['texto' => 'Le avisamos al cliente', 'interna' => false])->assertCreated();

        $detalle = $this->como($admin, 'GET', $ticket->identificador)->assertOk();
        $this->assertSame(['Le avisamos al cliente', 'Reiniciamos el router; clave temporal 998877'], array_column($detalle->json('data.notas'), 'texto'));
        $this->assertSame(1, $ticket->fresh()->revision);

        $volcado = (string) json_encode(AuditLog::query()->where('action', 'ticket_nota_agregada')->get()->map->getAttributes());
        $this->assertStringNotContainsString('998877', $volcado);
        $this->assertStringNotContainsString('router', $volcado);

        // Solo se crean: no hay ruta para editar ni borrar notas.
        $this->como($admin, 'PUT', $ticket->identificador.'/notas', ['texto' => 'x', 'interna' => true])->assertStatus(405);
        $this->como($admin, 'DELETE', $ticket->identificador.'/notas')->assertStatus(405);
    }

    public function test_validaciones_de_notas_y_ticket_cerrado(): void
    {
        $admin = $this->admin();
        $ticket = $this->crear($admin);

        $this->como($admin, 'POST', $ticket->identificador.'/notas', ['texto' => '', 'interna' => true])->assertJsonValidationErrors('texto');
        $this->como($admin, 'POST', $ticket->identificador.'/notas', ['texto' => '<script>', 'interna' => true])->assertJsonValidationErrors('texto');
        $this->como($admin, 'POST', $ticket->identificador.'/notas', ['texto' => str_repeat('a', 2001), 'interna' => true])->assertJsonValidationErrors('texto');
        $this->como($admin, 'POST', $ticket->identificador.'/notas', ['texto' => 'x'])->assertJsonValidationErrors('interna');
        $this->assertSame(0, TicketNota::query()->count());

        foreach (['en_progreso', 'resuelto', 'cerrado'] as $paso) {
            $this->estado($admin, $ticket, $paso)->assertOk();
        }
        $this->como($admin, 'POST', $ticket->identificador.'/notas', ['texto' => 'tarde', 'interna' => false])->assertStatus(409);
    }

    // ---------------------------------------------------------------- listado y privacidad

    public function test_el_listado_filtra_marca_vencidos_y_oculta_el_cliente_sin_permiso(): void
    {
        $admin = $this->admin();
        $uno = $this->crear($admin, null, ['asunto' => 'Sin internet', 'prioridad' => 'critica']);
        $dos = $this->crear($admin, null, ['asunto' => 'Impresora', 'prioridad' => 'baja']);
        TicketSoporte::query()->whereKey($uno->id)->update(['vence_at' => now()->subHour()]);

        $respuesta = $this->como($admin, 'GET', '?sort=vencimiento')->assertOk();
        $this->assertSame([true, false], array_column($respuesta->json('data'), 'vencido'));
        $this->como($admin, 'GET', '?vencidos=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', $uno->codigo);
        $this->como($admin, 'GET', '?busqueda=Impresora')->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', '?busqueda='.$dos->codigo)->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', '?prioridad=baja')->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', '?prioridad=urgente')->assertOk()->assertJsonCount(2, 'data');
        $this->como($admin, 'GET', '?cliente='.$uno->cliente->identificador)->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', '')->assertOk()->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.descripcion')->assertHeader('Cache-Control', 'no-store, private');

        // Un resuelto vencido ya no cuenta como vencido.
        $this->estado($admin, $uno, 'en_progreso')->assertOk();
        $this->estado($admin, $uno, 'resuelto')->assertOk();
        $this->como($admin, 'GET', '?vencidos=1')->assertOk()->assertJsonCount(0, 'data');

        Role::findByName('admin')->revokePermissionTo('clientes.ver');
        $this->como($admin->fresh(), 'GET', '')->assertOk()->assertJsonPath('data.0.cliente', null);
        $this->como($admin->fresh(), 'GET', $uno->identificador)->assertOk()->assertJsonPath('data.cliente', null);
    }
}
