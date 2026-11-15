<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Oportunidad;
use App\Models\Prospecto;
use App\Models\Proyecto;
use App\Models\ProyectoHito;
use App\Models\TicketSoporte;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Reportes comerciales (`/api/panel/v1/servicios/reportes-comerciales`, Ola 3): permisos por bloque, cifras del embudo,
 * oportunidades, soporte y proyectos con periodo de Lima, filtros, validación y exportación con constancia de acceso.
 */
final class ReportesComercialesPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/reportes-comerciales'.($ruta === '' ? '' : '/'.$ruta), $datos);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    private function cliente(): Cliente
    {
        $cliente = new Cliente(['tipo' => 'persona', 'nombre' => 'Cliente Ficticio']);
        $cliente->identificador = (string) Str::uuid();
        $cliente->estado = 'activo';
        $cliente->revision = 1;
        $cliente->save();

        return $cliente;
    }

    private function prospecto(string $estado, CarbonImmutable $creado, ?int $responsable = null, ?int $cliente = null): void
    {
        $p = new Prospecto(['nombre_contacto' => 'P', 'email' => Str::random(6).'@example.test', 'origen' => 'web']);
        $p->identificador = (string) Str::uuid();
        $p->estado = $estado;
        $p->motivo_descarte = $estado === 'descartado' ? 'x' : null;
        $p->cliente_id = $cliente;
        $p->responsable_id = $responsable;
        $p->revision = 1;
        $p->forceFill(['created_at' => $creado, 'updated_at' => $creado])->save();
    }

    private function oportunidad(string $etapa, string $monto, int $probabilidad, ?CarbonImmutable $cerrada = null, ?CarbonImmutable $creada = null, ?int $responsable = null): void
    {
        $o = new Oportunidad(['titulo' => 'O', 'monto_estimado' => $monto]);
        $o->identificador = (string) Str::uuid();
        $o->cliente_id = $this->cliente()->id;
        $o->etapa = $etapa;
        $o->probabilidad = $probabilidad;
        $o->motivo_perdida = $etapa === 'perdida' ? 'x' : null;
        $o->cerrada_at = $cerrada;
        $o->responsable_id = $responsable;
        $o->revision = 1;
        $o->forceFill(['created_at' => $creada ?? now(), 'updated_at' => now()])->save();
    }

    private function ticket(string $estado, string $prioridad, CarbonImmutable $creado, ?\DateTimeInterface $resuelto = null, ?\DateTimeInterface $vence = null, ?int $asignado = null): void
    {
        $t = new TicketSoporte(['asunto' => 'T', 'prioridad' => $prioridad, 'canal' => 'web']);
        $t->identificador = (string) Str::uuid();
        $t->codigo = 'SOP-2026-'.random_int(1000, 9999).random_int(0, 9);
        $t->cliente_id = $this->cliente()->id;
        $t->estado = $estado;
        $t->vence_at = $vence ?? $creado->addDay();
        $t->resuelto_at = $resuelto;
        $t->cerrado_at = $estado === 'cerrado' ? now() : null;
        $t->asignado_a = $asignado;
        $t->revision = 1;
        $t->forceFill(['created_at' => $creado, 'updated_at' => $creado])->save();
    }

    private function proyecto(string $estado, ?int $responsable = null): Proyecto
    {
        $p = new Proyecto(['nombre' => 'Proy']);
        $p->identificador = (string) Str::uuid();
        $p->codigo = 'PRY-2026-'.random_int(10000, 99999);
        $p->cliente_id = $this->cliente()->id;
        $p->estado = $estado;
        $p->fecha_fin_real = $estado === 'completado' ? CarbonImmutable::now('America/Lima')->startOfDay() : null;
        $p->responsable_id = $responsable;
        $p->revision = 1;
        $p->save();

        return $p;
    }

    private function hito(Proyecto $proyecto, string $fecha, bool $completado = false): void
    {
        $h = new ProyectoHito(['nombre' => 'H', 'fecha_objetivo' => $fecha]);
        $h->identificador = (string) Str::uuid();
        $h->proyecto_id = $proyecto->id;
        $h->orden = 1;
        $h->completado_at = $completado ? now() : null;
        $h->save();
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_el_personal_academico_es_403(): void
    {
        $this->como(null, 'GET')->assertUnauthorized();
        foreach (['coordinador', 'secretaria'] as $rol) {
            $usuario = $this->usuarioConRol($rol, $rol.'@horus.test');
            $this->como($usuario, 'GET')->assertForbidden();
            $this->como($usuario, 'POST', 'exportar')->assertForbidden();
        }
    }

    public function test_ver_y_exportar_son_permisos_distintos(): void
    {
        $admin = $this->admin();
        $rol = Role::findByName('admin');

        $rol->revokePermissionTo('reportes-comerciales.exportar');
        $this->como($admin->fresh(), 'GET')->assertOk();
        $this->como($admin->fresh(), 'POST', 'exportar')->assertForbidden();
        $rol->givePermissionTo('reportes-comerciales.exportar');

        $rol->revokePermissionTo('reportes-comerciales.ver');
        $this->como($admin->fresh(), 'GET')->assertForbidden();
        $this->como($admin->fresh(), 'GET', 'opciones/responsables')->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'reporte_comercial_exportado')->count());
    }

    public function test_cada_bloque_exige_el_permiso_de_su_modulo(): void
    {
        $admin = $this->admin();
        $rol = Role::findByName('admin');
        $this->como($admin, 'GET')->assertOk()->assertJsonStructure(['data' => ['periodo', 'bloques' => ['prospectos', 'oportunidades', 'soporte', 'proyectos']]]);

        foreach (['prospectos' => 'prospectos.ver', 'oportunidades' => 'pipeline.ver', 'soporte' => 'soporte.ver', 'proyectos' => 'proyectos.ver'] as $bloque => $permiso) {
            $rol->revokePermissionTo($permiso);
            $this->como($admin->fresh(), 'GET')->assertOk()->assertJsonMissingPath("data.bloques.{$bloque}");
            $rol->givePermissionTo($permiso);
        }
    }

    // ---------------------------------------------------------------- cifras

    public function test_el_embudo_cuenta_por_estado_y_calcula_la_conversion_solo_del_periodo(): void
    {
        $admin = $this->admin();
        $dentro = CarbonImmutable::now('America/Lima')->subDays(3)->setTimezone('UTC');
        $fuera = CarbonImmutable::now('America/Lima')->subDays(90)->setTimezone('UTC');
        $cliente = $this->cliente()->id;

        $this->prospecto('nuevo', $dentro);
        $this->prospecto('calificado', $dentro);
        $this->prospecto('descartado', $dentro);
        $this->prospecto('convertido', $dentro, null, $cliente);
        $this->prospecto('convertido', $fuera, null, $cliente);

        $r = $this->como($admin, 'GET')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $r->assertJsonPath('data.bloques.prospectos.creados', 4)
            ->assertJsonPath('data.bloques.prospectos.por_estado.nuevo', 1)
            ->assertJsonPath('data.bloques.prospectos.por_estado.contactado', 0)
            ->assertJsonPath('data.bloques.prospectos.convertidos', 1)
            ->assertJsonPath('data.bloques.prospectos.tasa_conversion', 25);
    }

    public function test_sin_datos_las_tasas_son_nulas_y_los_conteos_cero(): void
    {
        $r = $this->como($this->admin(), 'GET')->assertOk();
        $r->assertJsonPath('data.bloques.prospectos.creados', 0)->assertJsonPath('data.bloques.prospectos.tasa_conversion', null)
            ->assertJsonPath('data.bloques.oportunidades.tasa_exito', null)->assertJsonPath('data.bloques.oportunidades.abiertas.monto_ponderado', '0.00')
            ->assertJsonPath('data.bloques.soporte.tiempo_medio_resolucion_horas', null)->assertJsonPath('data.bloques.proyectos.hitos_vencidos', 0);
        $this->assertSame(['nueva', 'calificada', 'propuesta', 'negociacion', 'ganada', 'perdida'], array_keys($r->json('data.bloques.oportunidades.creadas_por_etapa')));
    }

    public function test_oportunidades_valor_abierto_ponderado_ganado_y_tasa_de_exito(): void
    {
        $admin = $this->admin();
        $dentro = CarbonImmutable::now('UTC')->subDays(2);
        $fuera = CarbonImmutable::now('UTC')->subDays(100);

        $this->oportunidad('nueva', '1000.00', 10);       // 100 ponderado
        $this->oportunidad('negociacion', '2000.00', 75); // 1500 ponderado
        $this->oportunidad('ganada', '500.50', 100, $dentro);
        $this->oportunidad('ganada', '700.00', 100, $dentro);
        $this->oportunidad('perdida', '300.00', 0, $dentro);
        $this->oportunidad('ganada', '9999.00', 100, $fuera, $fuera); // fuera del periodo

        $r = $this->como($admin, 'GET')->assertOk();
        $r->assertJsonPath('data.bloques.oportunidades.abiertas.total', 2)
            ->assertJsonPath('data.bloques.oportunidades.abiertas.monto', '3000.00')
            ->assertJsonPath('data.bloques.oportunidades.abiertas.monto_ponderado', '1600.00')
            ->assertJsonPath('data.bloques.oportunidades.ganadas.total', 2)
            ->assertJsonPath('data.bloques.oportunidades.ganadas.monto', '1200.50')
            ->assertJsonPath('data.bloques.oportunidades.perdidas.total', 1)
            ->assertJsonPath('data.bloques.oportunidades.tasa_exito', 66.7)
            ->assertJsonPath('data.bloques.oportunidades.creadas', 5);
    }

    public function test_soporte_tickets_por_estado_prioridad_tiempo_medio_y_vencidos(): void
    {
        $admin = $this->admin();
        $base = CarbonImmutable::now('UTC')->subDays(5);

        $this->ticket('abierto', 'alta', $base, null, now()->subHour());                          // vencido
        $this->ticket('en_progreso', 'media', $base, null, now()->addDay());                      // en plazo
        $this->ticket('resuelto', 'media', $base, $base->addHours(10), $base->addHours(24));       // 10 h
        $this->ticket('cerrado', 'baja', $base, $base->addHours(20), $base->addHours(72));         // 20 h
        $this->ticket('abierto', 'critica', CarbonImmutable::now('UTC')->subDays(200));            // fuera del periodo

        $r = $this->como($admin, 'GET')->assertOk();
        $r->assertJsonPath('data.bloques.soporte.creados', 4)
            ->assertJsonPath('data.bloques.soporte.por_estado.abierto', 1)
            ->assertJsonPath('data.bloques.soporte.por_prioridad.media', 2)
            ->assertJsonPath('data.bloques.soporte.por_prioridad.critica', 0)
            ->assertJsonPath('data.bloques.soporte.resueltos', 2)
            ->assertJsonPath('data.bloques.soporte.tiempo_medio_resolucion_horas', 15)
            ->assertJsonPath('data.bloques.soporte.vencidos', 2); // el de hace 200 días también sigue vencido hoy
    }

    public function test_proyectos_por_estado_y_hitos_vencidos_solo_de_proyectos_activos(): void
    {
        $admin = $this->admin();
        $ayer = CarbonImmutable::now('America/Lima')->subDay()->toDateString();
        $manana = CarbonImmutable::now('America/Lima')->addDay()->toDateString();

        $activo = $this->proyecto('en_curso');
        $this->hito($activo, $ayer);          // vencido
        $this->hito($activo, $ayer, true);    // completado: no cuenta
        $this->hito($activo, $manana);        // aún en plazo
        $cerrado = $this->proyecto('cancelado');
        $this->hito($cerrado, $ayer);         // proyecto cerrado: no cuenta
        $this->proyecto('completado');

        $r = $this->como($admin, 'GET')->assertOk();
        $r->assertJsonPath('data.bloques.proyectos.hitos_vencidos', 1)
            ->assertJsonPath('data.bloques.proyectos.por_estado.en_curso', 1)
            ->assertJsonPath('data.bloques.proyectos.por_estado.cancelado', 1)
            ->assertJsonPath('data.bloques.proyectos.creados', 3)
            ->assertJsonPath('data.bloques.proyectos.completados', 1);
    }

    public function test_el_periodo_son_dias_completos_de_lima(): void
    {
        $admin = $this->admin();
        // 23:30 en Lima del 10/12 son las 04:30 UTC del 11/12: cuenta para el día 10 de Lima, no para el 11.
        $this->prospecto('nuevo', CarbonImmutable::parse('2026-12-11 04:30:00', 'UTC'));

        $this->como($admin, 'GET', '?desde=2026-12-10&hasta=2026-12-10')->assertOk()->assertJsonPath('data.bloques.prospectos.creados', 1);
        $this->como($admin, 'GET', '?desde=2026-12-11&hasta=2026-12-11')->assertOk()->assertJsonPath('data.bloques.prospectos.creados', 0);
        $this->como($admin, 'GET', '?desde=2026-12-10&hasta=2026-12-11')->assertOk()->assertJsonPath('data.periodo', ['desde' => '2026-12-10', 'hasta' => '2026-12-11']);
    }

    public function test_el_filtro_de_responsable_acota_los_bloques(): void
    {
        $admin = $this->admin();
        $otro = $this->usuarioConRol('admin', 'otro@horus.test');
        $hace = CarbonImmutable::now('UTC')->subDay();

        $this->prospecto('nuevo', $hace, $admin->id);
        $this->prospecto('nuevo', $hace, $otro->id);
        $this->oportunidad('nueva', '100.00', 10, null, null, $admin->id);
        $this->oportunidad('nueva', '400.00', 10, null, null, $otro->id);
        $this->ticket('abierto', 'alta', $hace, null, null, $otro->id);
        $this->proyecto('en_curso', $admin->id);

        $r = $this->como($admin, 'GET', '?responsable_id='.$otro->id)->assertOk();
        $r->assertJsonPath('data.bloques.prospectos.creados', 1)->assertJsonPath('data.bloques.oportunidades.abiertas.monto', '400.00')
            ->assertJsonPath('data.bloques.soporte.creados', 1)->assertJsonPath('data.bloques.proyectos.por_estado.en_curso', 0);
        $this->como($admin, 'GET')->assertOk()->assertJsonPath('data.bloques.prospectos.creados', 2);
    }

    public function test_validacion_del_periodo(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'GET', '?desde=10/12/2026')->assertJsonValidationErrors('desde');
        $this->como($admin, 'GET', '?desde=2026-12-10&hasta=2026-12-01')->assertJsonValidationErrors('hasta');
        $this->como($admin, 'GET', '?desde=2025-01-01&hasta=2026-12-31')->assertJsonValidationErrors('hasta');
        $this->como($admin, 'GET', '?responsable_id=abc')->assertJsonValidationErrors('responsable_id');
        $this->como($admin, 'GET', '?desde=2026-01-01&hasta=2026-12-31')->assertOk(); // 365 días: dentro del máximo
        $this->como($admin, 'GET', '?desde=&hasta=')->assertOk(); // vacío = por defecto
    }

    // ---------------------------------------------------------------- exportación

    public function test_exportar_entrega_un_xlsx_con_las_mismas_cifras_y_deja_constancia(): void
    {
        $admin = $this->admin();
        $dentro = CarbonImmutable::now('UTC')->subDays(2);
        $this->prospecto('nuevo', $dentro);
        $this->oportunidad('ganada', '1200.50', 100, $dentro);

        $respuesta = $this->como($admin, 'POST', 'exportar?desde='.CarbonImmutable::now('America/Lima')->subDays(5)->toDateString())->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/reporte-comercial-\d{8}-\d{6}\.xlsx/', (string) $respuesta->headers->get('Content-Disposition'));

        $archivo = tempnam(sys_get_temp_dir(), 'rc');
        file_put_contents($archivo, $respuesta->baseResponse->getFile()->getContent());
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray();
        unlink($archivo);
        $this->assertSame(['Bloque', 'Indicador', 'Valor'], $filas[0]);
        $plano = array_map(fn (array $f): string => implode('|', $f), $filas);
        $this->assertContains('Prospectos|Creados|1', $plano);
        $this->assertContains('Oportunidades|Ganadas: monto|1200.50', $plano);

        $constancia = AuditLog::query()->where('action', 'reporte_comercial_exportado')->sole();
        $this->assertSame($admin->id, $constancia->user_id);
        $this->assertSame(['prospectos', 'oportunidades', 'soporte', 'proyectos'], $constancia->changes['bloques']);
        $this->assertStringNotContainsString('1200', (string) json_encode($constancia->getAttributes()));
    }

    public function test_exportar_valida_filtros_y_no_deja_constancia_si_falla(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'POST', 'exportar?desde=2026-12-10&hasta=2026-12-01')->assertStatus(422);
        $this->assertSame(0, AuditLog::query()->where('action', 'reporte_comercial_exportado')->count());
    }

    public function test_las_opciones_de_responsable_solo_listan_administradores(): void
    {
        $admin = $this->admin();
        $this->usuarioConRol('coordinador', 'coord@horus.test');

        $r = $this->como($admin, 'GET', 'opciones/responsables')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$admin->id], array_column($r->json('data'), 'id'));
    }
}
