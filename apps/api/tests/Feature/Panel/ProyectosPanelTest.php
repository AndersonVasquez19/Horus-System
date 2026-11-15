<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Oportunidad;
use App\Models\Proyecto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Proyectos del panel de servicios (`/api/panel/v1/servicios/proyectos`, Ola 3): permisos, validación, código
 * secuencial, estados, hitos con avance calculado, proyecto desde una oportunidad ganada (idempotente) y auditoría
 * sin datos personales.
 */
final class ProyectosPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/proyectos'.($ruta === '' ? '' : '/'.$ruta), $datos);
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

    private function crear(User $admin, ?Cliente $cliente = null, array $cambios = []): Proyecto
    {
        $respuesta = $this->como($admin, 'POST', '', ['cliente' => ($cliente ?? $this->cliente())->identificador, 'nombre' => 'Red de la sede', ...$cambios])->assertCreated();

        return Proyecto::query()->where('identificador', $respuesta->json('data.identificador'))->firstOrFail();
    }

    private function estado(User $admin, Proyecto $proyecto, string $estado): TestResponse
    {
        return $this->como($admin, 'POST', $proyecto->identificador.'/estado', ['estado' => $estado, '_revision' => $proyecto->fresh()->etag()]);
    }

    private function hito(User $admin, Proyecto $proyecto, string $nombre = 'Levantamiento'): string
    {
        $hitos = $this->como($admin, 'POST', $proyecto->identificador.'/hitos', ['nombre' => $nombre, '_revision' => $proyecto->fresh()->etag()])
            ->assertCreated()->json('data.hitos_detalle');

        return (string) end($hitos)['identificador'];
    }

    private function ganada(User $admin, ?Cliente $cliente = null): Oportunidad
    {
        $cliente ??= $this->cliente();
        $oportunidad = new Oportunidad(['titulo' => 'Cableado ganado', 'monto_estimado' => 3200.5]);
        $oportunidad->identificador = (string) Str::uuid();
        $oportunidad->cliente_id = $cliente->id;
        $oportunidad->etapa = 'ganada';
        $oportunidad->probabilidad = 100;
        $oportunidad->cerrada_at = now();
        $oportunidad->revision = 1;
        $oportunidad->responsable_id = $admin->id;
        $oportunidad->save();

        return $oportunidad;
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_el_personal_academico_es_403(): void
    {
        $this->como(null, 'GET')->assertUnauthorized();
        foreach (['coordinador', 'secretaria'] as $rol) {
            $usuario = $this->usuarioConRol($rol, $rol.'@horus.test');
            $this->como($usuario, 'GET')->assertForbidden();
            $this->como($usuario, 'POST', '', ['cliente' => $this->cliente()->identificador, 'nombre' => 'x'])->assertForbidden();
        }
        $this->assertSame(0, Proyecto::query()->count());
    }

    public function test_un_admin_sin_el_permiso_concreto_no_puede_esa_accion(): void
    {
        $admin = $this->admin();
        $proyecto = $this->crear($admin);
        $rol = Role::findByName('admin');

        foreach ([
            ['proyectos.crear', fn () => $this->como($admin->fresh(), 'POST', '', ['cliente' => $this->cliente()->identificador, 'nombre' => 'x'])],
            ['proyectos.editar', fn () => $this->estado($admin->fresh(), $proyecto, 'en_curso')],
            ['proyectos.editar', fn () => $this->como($admin->fresh(), 'POST', $proyecto->identificador.'/hitos', ['nombre' => 'x', '_revision' => $proyecto->etag()])],
            ['proyectos.ver', fn () => $this->como($admin->fresh(), 'GET', $proyecto->identificador)],
        ] as [$permiso, $llamada]) {
            $rol->revokePermissionTo($permiso);
            $llamada()->assertForbidden();
            $rol->givePermissionTo($permiso);
        }
        $this->assertSame('planificado', $proyecto->fresh()->estado);
    }

    // ---------------------------------------------------------------- alta y código

    public function test_crear_fija_estado_responsable_codigo_y_audita_sin_datos_personales(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente(nombre: 'Marta Ficticia');
        $anio = CarbonImmutable::now('America/Lima')->format('Y');

        $respuesta = $this->como($admin, 'POST', '', [
            'cliente' => strtoupper($cliente->identificador), 'nombre' => 'Proyecto Secreto', 'descripcion' => 'Detalle reservado',
            'fecha_inicio' => '2026-12-01', 'fecha_fin_estimada' => '2026-12-31', 'presupuesto' => 1500.5,
        ])->assertCreated();

        $respuesta->assertJsonPath('data.estado', 'planificado')
            ->assertJsonPath('data.codigo', "PRY-{$anio}-0001")
            ->assertJsonPath('data.presupuesto', '1500.50')
            ->assertJsonPath('data.cliente.nombre', 'Marta Ficticia')
            ->assertJsonPath('data.responsable.id', $admin->id)
            ->assertJsonPath('data.hitos.avance', null)
            ->assertJsonMissingPath('data.id')
            ->assertHeader('Cache-Control', 'no-store, private');

        $volcado = (string) json_encode(AuditLog::query()->where('action', 'proyecto_creado')->sole()->getAttributes());
        foreach (['Marta', 'Secreto', 'reservado'] as $personal) {
            $this->assertStringNotContainsString($personal, $volcado);
        }
    }

    public function test_los_codigos_son_secuenciales_por_anio_y_no_se_reutilizan(): void
    {
        $admin = $this->admin();
        $anio = CarbonImmutable::now('America/Lima')->format('Y');

        $uno = $this->crear($admin);
        $dos = $this->crear($admin);
        $uno->delete(); // un proyecto en papelera no libera su número
        $tres = $this->crear($admin);

        $this->assertSame(["PRY-{$anio}-0001", "PRY-{$anio}-0002", "PRY-{$anio}-0003"], [$uno->codigo, $dos->codigo, $tres->codigo]);
        $this->assertSame(3, (int) DB::table('secuencias_codigo')->where('tipo', 'PRY')->value('ultimo'));
    }

    public function test_validaciones_de_alta(): void
    {
        $admin = $this->admin();
        $c = $this->cliente()->identificador;

        $this->como($admin, 'POST', '', ['nombre' => 'x'])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', '', ['cliente' => $c])->assertJsonValidationErrors('nombre');
        $this->como($admin, 'POST', '', ['cliente' => $c, 'nombre' => '<b>x</b>'])->assertJsonValidationErrors('nombre');
        $this->como($admin, 'POST', '', ['cliente' => $c, 'nombre' => 'x', 'presupuesto' => -1])->assertJsonValidationErrors('presupuesto');
        $this->como($admin, 'POST', '', ['cliente' => $c, 'nombre' => 'x', 'fecha_inicio' => '2026-12-10', 'fecha_fin_estimada' => '2026-12-01'])->assertJsonValidationErrors('fecha_fin_estimada');
        $this->como($admin, 'POST', '', ['cliente' => $c, 'nombre' => 'x', 'fecha_inicio' => '10/12/2026'])->assertJsonValidationErrors('fecha_inicio');
        $this->como($admin, 'POST', '', ['cliente' => $c, 'nombre' => 'x', 'estado' => 'completado'])->assertJsonValidationErrors('estado');
        $this->como($admin, 'POST', '', ['cliente' => $c, 'nombre' => 'x', 'codigo' => 'PRY-1'])->assertJsonValidationErrors('codigo');
        $this->como($admin, 'POST', '', ['cliente' => (string) Str::uuid(), 'nombre' => 'x'])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'POST', '', ['cliente' => $c, 'oportunidad' => (string) Str::uuid()])->assertJsonValidationErrors('cliente');
        $this->assertSame(0, Proyecto::query()->count());
    }

    public function test_no_se_abre_un_proyecto_para_un_cliente_inactivo(): void
    {
        $this->como($this->admin(), 'POST', '', ['cliente' => $this->cliente('inactivo')->identificador, 'nombre' => 'x'])->assertJsonValidationErrors('cliente');
        $this->assertSame(0, Proyecto::query()->count());
    }

    // ---------------------------------------------------------------- edición y estados

    public function test_actualizar_exige_la_revision_y_no_edita_uno_cerrado(): void
    {
        $admin = $this->admin();
        $proyecto = $this->crear($admin);
        $cambios = ['nombre' => 'Red ampliada', 'descripcion' => null, 'presupuesto' => 2000, 'fecha_inicio' => null, 'fecha_fin_estimada' => null];

        $this->como($admin, 'PUT', $proyecto->identificador, $cambios)->assertStatus(428);
        $this->como($admin, 'PUT', $proyecto->identificador, $cambios + ['_revision' => '"proyecto-'.$proyecto->identificador.'-v9"'])->assertStatus(412);
        $this->como($admin, 'PUT', $proyecto->identificador, $cambios + ['cliente' => $this->cliente()->identificador, '_revision' => $proyecto->etag()])->assertJsonValidationErrors('cliente');
        $this->como($admin, 'PUT', $proyecto->identificador, $cambios + ['_revision' => $proyecto->etag()])->assertOk()->assertJsonPath('data.nombre', 'Red ampliada');
        $this->assertSame(2, $proyecto->fresh()->revision);

        $this->como($admin, 'PUT', $proyecto->identificador, $cambios + ['_revision' => $proyecto->fresh()->etag()])->assertOk();
        $this->assertSame(2, $proyecto->fresh()->revision);

        $this->estado($admin, $proyecto, 'cancelado')->assertOk();
        $this->como($admin, 'PUT', $proyecto->identificador, $cambios + ['_revision' => $proyecto->fresh()->etag()])->assertStatus(409);
    }

    public function test_las_transiciones_de_estado_y_las_fechas_automaticas(): void
    {
        $admin = $this->admin();
        $proyecto = $this->crear($admin);
        $hoy = CarbonImmutable::now('America/Lima')->toDateString();

        $this->estado($admin, $proyecto, 'completado')->assertStatus(409);
        $this->estado($admin, $proyecto, 'pausado')->assertStatus(409);
        $this->estado($admin, $proyecto, 'inventado')->assertJsonValidationErrors('estado');
        $this->estado($admin, $proyecto, 'en_curso')->assertOk()->assertJsonPath('data.fecha_inicio', $hoy)->assertJsonPath('data.fecha_fin_real', null);
        $this->estado($admin, $proyecto, 'pausado')->assertOk();
        $this->estado($admin, $proyecto, 'en_curso')->assertOk();
        $this->estado($admin, $proyecto, 'completado')->assertOk()->assertJsonPath('data.fecha_fin_real', $hoy);

        // Terminal: ya no hay salida.
        $this->estado($admin, $proyecto, 'en_curso')->assertStatus(409);
        $this->estado($admin, $proyecto, 'cancelado')->assertStatus(409);
        $this->assertSame(4, AuditLog::query()->where('action', 'proyecto_estado_cambiado')->count());
    }

    public function test_repetir_el_estado_no_cambia_nada_y_la_revision_obsoleta_es_412(): void
    {
        $admin = $this->admin();
        $proyecto = $this->crear($admin);
        $vieja = $proyecto->etag();

        $this->estado($admin, $proyecto, 'planificado')->assertOk();
        $this->assertSame(1, $proyecto->fresh()->revision);
        $this->estado($admin, $proyecto, 'en_curso')->assertOk();
        $this->como($admin, 'POST', $proyecto->identificador.'/estado', ['estado' => 'pausado', '_revision' => $vieja])->assertStatus(412);
        $this->assertSame('en_curso', $proyecto->fresh()->estado);
    }

    public function test_el_responsable_solo_puede_ser_un_admin_con_permiso(): void
    {
        $admin = $this->admin();
        $proyecto = $this->crear($admin);
        $coordinador = $this->usuarioConRol('coordinador', 'coord@horus.test');

        $this->como($admin, 'POST', $proyecto->identificador.'/responsable', ['responsable_id' => $coordinador->id, '_revision' => $proyecto->etag()])->assertJsonValidationErrors('responsable_id');
        $this->como($admin, 'POST', $proyecto->identificador.'/responsable', ['responsable_id' => null, '_revision' => $proyecto->etag()])->assertOk()->assertJsonPath('data.responsable', null);
    }

    // ---------------------------------------------------------------- hitos

    public function test_los_hitos_se_agregan_se_completan_y_calculan_el_avance(): void
    {
        $admin = $this->admin();
        $proyecto = $this->crear($admin);
        $primero = $this->hito($admin, $proyecto, 'Levantamiento');
        $segundo = $this->hito($admin, $proyecto, 'Instalación');

        $this->como($admin, 'POST', $proyecto->identificador.'/hitos/'.$primero.'/completar', ['completado' => true, '_revision' => $proyecto->fresh()->etag()])
            ->assertOk()->assertJsonPath('data.hitos.total', 2)->assertJsonPath('data.hitos.completados', 1)->assertJsonPath('data.hitos.avance', 50);

        $respuesta = $this->como($admin, 'GET', $proyecto->identificador)->assertOk();
        $this->assertSame([1, 2], array_column($respuesta->json('data.hitos_detalle'), 'orden'));
        $this->assertNotNull($respuesta->json('data.hitos_detalle.0.completado_en'));

        // Repetir no cambia la revisión; desmarcar la baja el avance.
        $revision = $proyecto->fresh()->revision;
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos/'.$primero.'/completar', ['completado' => true, '_revision' => $proyecto->fresh()->etag()])->assertOk();
        $this->assertSame($revision, $proyecto->fresh()->revision);
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos/'.$primero.'/completar', ['completado' => false, '_revision' => $proyecto->fresh()->etag()])
            ->assertOk()->assertJsonPath('data.hitos.avance', 0);

        $this->como($admin, 'PUT', $proyecto->identificador.'/hitos/'.$segundo, ['nombre' => 'Instalación final', 'fecha_objetivo' => '2026-12-20', '_revision' => $proyecto->fresh()->etag()])
            ->assertOk();
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos/'.$segundo.'/eliminar', ['_revision' => $proyecto->fresh()->etag()])
            ->assertOk()->assertJsonPath('data.hitos.total', 1);

        $acciones = AuditLog::query()->whereIn('action', ['hito_agregado', 'hito_completado', 'hito_reabierto', 'hito_actualizado', 'hito_eliminado'])->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['hito_agregado', 'hito_agregado', 'hito_completado', 'hito_reabierto', 'hito_actualizado', 'hito_eliminado'], $acciones);
        $this->assertStringNotContainsString('Instalación', (string) json_encode(AuditLog::query()->where('action', 'hito_actualizado')->sole()->getAttributes()));
    }

    public function test_los_hitos_exigen_revision_proyecto_activo_y_un_limite(): void
    {
        $admin = $this->admin();
        $proyecto = $this->crear($admin);
        $hito = $this->hito($admin, $proyecto);

        $this->como($admin, 'POST', $proyecto->identificador.'/hitos', ['nombre' => 'x'])->assertStatus(428);
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos', ['nombre' => 'x', '_revision' => '"proyecto-'.$proyecto->identificador.'-v99"'])->assertStatus(412);
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos', ['nombre' => '', '_revision' => $proyecto->fresh()->etag()])->assertJsonValidationErrors('nombre');
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos/'.Str::uuid().'/completar', ['completado' => true, '_revision' => $proyecto->fresh()->etag()])->assertNotFound();

        for ($n = 2; $n <= Proyecto::MAX_HITOS; $n++) {
            $this->como($admin, 'POST', $proyecto->identificador.'/hitos', ['nombre' => "Hito {$n}", '_revision' => $proyecto->fresh()->etag()])->assertCreated();
        }
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos', ['nombre' => 'uno más', '_revision' => $proyecto->fresh()->etag()])->assertJsonValidationErrors('nombre');

        $this->estado($admin, $proyecto, 'cancelado')->assertOk();
        $this->como($admin, 'POST', $proyecto->identificador.'/hitos/'.$hito.'/completar', ['completado' => true, '_revision' => $proyecto->fresh()->etag()])->assertStatus(409);
    }

    // ---------------------------------------------------------------- desde una oportunidad

    public function test_un_proyecto_nace_de_una_oportunidad_ganada_y_es_idempotente(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->ganada($admin);

        $primera = $this->como($admin, 'POST', '', ['oportunidad' => $oportunidad->identificador])->assertCreated();
        $primera->assertJsonPath('data.nombre', 'Cableado ganado')->assertJsonPath('data.presupuesto', '3200.50')
            ->assertJsonPath('data.cliente.identificador', $oportunidad->cliente->identificador)->assertJsonPath('data.desde_oportunidad', true);

        $segunda = $this->como($admin, 'POST', '', ['oportunidad' => $oportunidad->identificador, 'nombre' => 'Otro nombre'])->assertOk();
        $this->assertSame($primera->json('data.identificador'), $segunda->json('data.identificador'));
        $this->assertSame(1, Proyecto::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'proyecto_creado')->count());

        // El enlace aparece en el detalle de la oportunidad.
        $this->actingAs($admin)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->getJson('/api/panel/v1/servicios/oportunidades/'.$oportunidad->identificador)
            ->assertOk()->assertJsonPath('data.proyecto.codigo', $primera->json('data.codigo'));
    }

    public function test_una_oportunidad_no_ganada_no_da_proyecto_y_los_datos_indicados_mandan(): void
    {
        $admin = $this->admin();
        $abierta = $this->ganada($admin);
        $abierta->forceFill(['etapa' => 'propuesta', 'cerrada_at' => null])->save();

        $this->como($admin, 'POST', '', ['oportunidad' => $abierta->identificador])->assertStatus(409);
        $this->assertSame(0, Proyecto::query()->count());

        $abierta->forceFill(['etapa' => 'ganada', 'cerrada_at' => now()])->save();
        $this->como($admin, 'POST', '', ['oportunidad' => $abierta->identificador, 'nombre' => 'Nombre propio', 'presupuesto' => 10])
            ->assertCreated()->assertJsonPath('data.nombre', 'Nombre propio')->assertJsonPath('data.presupuesto', '10.00');
    }

    public function test_desde_una_oportunidad_exige_poder_verla(): void
    {
        $admin = $this->admin();
        $oportunidad = $this->ganada($admin);
        Role::findByName('admin')->revokePermissionTo('pipeline.ver');

        $this->como($admin->fresh(), 'POST', '', ['oportunidad' => $oportunidad->identificador])->assertForbidden();
        $this->assertSame(0, Proyecto::query()->count());
    }

    // ---------------------------------------------------------------- listado y privacidad

    public function test_el_listado_filtra_oculta_el_cliente_sin_permiso_y_muestra_el_avance(): void
    {
        $admin = $this->admin();
        $uno = $this->crear($admin, null, ['nombre' => 'Cableado norte']);
        $this->crear($admin, null, ['nombre' => 'Servidor sur']);
        $this->hito($admin, $uno);
        $this->estado($admin, $uno, 'en_curso')->assertOk();

        $this->como($admin, 'GET', '?busqueda=Servidor')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Servidor sur');
        $this->como($admin, 'GET', '?busqueda='.$uno->codigo)->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', '?estado=en_curso')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.hitos.total', 1);
        $this->como($admin, 'GET', '?estado=inventado')->assertOk()->assertJsonCount(2, 'data');
        $this->como($admin, 'GET', '?cliente='.$uno->cliente->identificador)->assertOk()->assertJsonCount(1, 'data');
        $this->como($admin, 'GET', '')->assertOk()->assertJsonMissingPath('data.0.id')->assertHeader('Cache-Control', 'no-store, private');

        Role::findByName('admin')->revokePermissionTo('clientes.ver');
        $this->como($admin->fresh(), 'GET', '')->assertOk()->assertJsonPath('data.0.cliente', null);
        $this->como($admin->fresh(), 'GET', $uno->identificador)->assertOk()->assertJsonPath('data.cliente', null);
    }
}
