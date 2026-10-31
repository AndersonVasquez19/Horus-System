<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RecepcionReclamacionesHabilitada;
use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\ReclamacionHistorial;
use App\Models\User;
use App\Services\GestionReclamaciones;
use App\Services\NumeradorReclamaciones;
use App\Support\AuditoriaReclamaciones;
use App\Support\Reclamaciones;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

class GestionReclamacionesTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones();
    }

    private function servicio(): GestionReclamaciones
    {
        return app(GestionReclamaciones::class);
    }

    private function conteos(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['reclamaciones', 'reclamacion_historial', 'reclamacion_envios', 'reclamacion_contadores', 'audit_logs', 'notificaciones_internas']);
    }

    // --- Flag ---------------------------------------------------------------------------------------------

    public function test_configuracion_apagada_por_defecto_y_solo_true_estricto_habilita(): void
    {
        $this->assertFalse((require base_path('config/reclamaciones.php'))['enabled']);
        $this->assertFalse((require base_path('config/reclamaciones.php'))['correo']['enabled']);
        foreach (['true', 1, '1', 'on', null] as $valor) {
            config(['reclamaciones.enabled' => $valor]);
            $this->assertFalse(Reclamaciones::habilitado(), var_export($valor, true));
        }
    }

    public function test_flag_deshabilitado_no_recibe_ni_guarda_nada(): void
    {
        config(['reclamaciones.enabled' => false]);
        $antes = $this->conteos();
        try {
            $this->registrar();
            $this->fail('Debió responder 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_habilitado_exige_correo_real_aunque_el_flag_este_en_true(): void
    {
        config(['reclamaciones.enabled' => true, 'mail.default' => 'array', 'mail.from.address' => 'libro@horus.test']);
        $this->assertFalse(Reclamaciones::habilitado());
        config(['mail.default' => 'log']);
        $this->assertFalse(Reclamaciones::habilitado());
        config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@example.com']);
        $this->assertFalse(Reclamaciones::habilitado());
        config(['mail.from.address' => 'invalido']);
        $this->assertFalse(Reclamaciones::habilitado());
        config(['mail.from.address' => 'libro@horus.test']);
        $this->assertTrue(Reclamaciones::habilitado());
    }

    public function test_middleware_responde_404_apagado_y_deja_pasar_habilitado(): void
    {
        $siguiente = fn () => response('ok');
        config(['reclamaciones.enabled' => false]);
        try {
            (new RecepcionReclamacionesHabilitada)->handle(Request::create('/x', 'POST'), $siguiente);
            $this->fail('Debió responder 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->habilitarReclamaciones();
        $this->assertSame('ok', (new RecepcionReclamacionesHabilitada)->handle(Request::create('/x', 'POST'), $siguiente)->getContent());
    }

    public function test_no_existe_ninguna_ruta_que_reciba_reclamaciones(): void
    {
        foreach (app('router')->getRoutes()->getRoutes() as $ruta) {
            if (str_contains($ruta->uri(), 'reclamacion') || str_contains($ruta->uri(), 'libro-reclamaciones')) {
                $this->assertSame(['GET', 'HEAD'], $ruta->methods(), $ruta->uri());
            }
        }
    }

    // --- Alta pública, numeración, idempotencia ----------------------------------------------------------

    public function test_alta_publica_crea_reclamacion_historial_envio_y_auditoria(): void
    {
        ['reclamacion' => $r, 'repetida' => $repetida] = $this->registrar();

        $this->assertFalse($repetida);
        $this->assertSame('000000001-2026', $r->numero);
        $this->assertTrue(Str::isUuid($r->identificador));
        $this->assertSame('abierto', $r->estado);
        $this->assertSame('2026-11-16', $r->fecha_limite->toDateString(), '15 días hábiles desde el lunes 26/10/2026');
        $this->assertNotNull($r->informado_at);
        $this->assertSame('web', $r->canal);

        $h = ReclamacionHistorial::query()->where('reclamacion_id', $r->id)->get();
        $this->assertSame(['registrada'], $h->pluck('evento')->all());
        $this->assertSame('Sistema', $h[0]->actor_nombre);
        $this->assertNull($h[0]->actor_id);

        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->sole();
        $this->assertSame(['copia_hoja', 'correo', 'pendiente', 0], [$envio->tipo, $envio->canal, $envio->estado, $envio->intentos]);
        $this->assertStringContainsString('000000001-2026', $envio->asunto);
        $this->assertStringContainsString('Calle Ficticia 123, Cajamarca', $envio->cuerpo);
        $this->assertStringContainsString('no configurado', $envio->cuerpo, 'Los datos del proveedor ausentes no se inventan');

        $audit = AuditLog::query()->where('action', 'reclamacion_registrada')->sole();
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->ip_address, 'No se guarda la IP del consumidor');
        $this->assertSame(['esquema' => 1, 'numero' => '000000001-2026', 'canal' => 'web', 'tipo' => 'reclamo'], $audit->changes);
    }

    public function test_numeracion_correlativa_por_anio_sin_huecos(): void
    {
        $this->assertSame('000000001-2026', $this->registrar()['reclamacion']->numero);
        $this->assertSame('000000002-2026', $this->registrar()['reclamacion']->numero);
        $this->travelTo(Carbon::parse('2027-01-04 10:00:00', 'America/Lima'));
        $this->assertSame('000000001-2027', $this->registrar()['reclamacion']->numero, 'El contador reinicia por año');
        $this->assertSame('000000003-2026', app(NumeradorReclamaciones::class)->siguiente(2026), 'La numeración de 2026 continúa donde quedó');
    }

    // La exigencia de transacción abierta del numerador se prueba sin base de datos en
    // tests/Unit/NumeradorReclamacionesTest (antes revertía aquí la transacción de RefreshDatabase,
    // lo que no es portable a PostgreSQL).

    public function test_el_numero_no_se_consume_si_la_transaccion_revierte(): void
    {
        $this->assertSame('000000001-2026', $this->registrar()['reclamacion']->numero);
        // El fallo de auditoría (última escritura) revierte todo: reclamación, historial, envío, notificaciones y contador.
        $antes = $this->conteos();
        AuditLog::creating(function (): void {
            throw new RuntimeException('fallo de auditoría simulado');
        });
        try {
            $this->registrar();
            $this->fail('Debió fallar');
        } catch (RuntimeException) {
        }
        AuditLog::flushEventListeners();
        $this->assertSame($antes, $this->conteos());
        $this->assertSame(1, (int) DB::table('reclamacion_contadores')->where('anio', 2026)->value('ultimo'));
        $this->assertSame('000000002-2026', $this->registrar()['reclamacion']->numero, 'Sin huecos tras el rollback');
    }

    public function test_reenviar_el_mismo_formulario_devuelve_la_misma_reclamacion_sin_duplicar(): void
    {
        $token = (string) Str::uuid();
        $primera = $this->registrar([], $token);
        $antes = $this->conteos();
        $segunda = $this->registrar(['detalle' => 'Texto distinto en el reenvío.'], $token);

        $this->assertTrue($segunda['repetida']);
        $this->assertSame($primera['reclamacion']->id, $segunda['reclamacion']->id);
        $this->assertSame($antes, $this->conteos());
        $this->assertSame(1, Reclamacion::query()->count());
    }

    public function test_el_token_se_guarda_solo_como_hash_y_no_se_serializa(): void
    {
        $token = (string) Str::uuid();
        $r = $this->registrar([], $token)['reclamacion'];
        $this->assertSame(hash('sha256', $token), $r->token_envio);
        $this->assertArrayNotHasKey('token_envio', $r->toArray());
    }

    public function test_queja_sin_bien_y_reclamo_sin_monto_guardan_null_nunca_cero(): void
    {
        $queja = $this->registrar(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => '99'])['reclamacion'];
        $this->assertNull($queja->monto_bien);
        $this->assertNull($queja->bien_tipo);
        $reclamo = $this->registrar(['monto_bien' => null])['reclamacion'];
        $this->assertNull($reclamo->fresh()->monto_bien);
        $menor = $this->registrar(['es_menor' => false, 'representante_nombre' => 'Ignorado'])['reclamacion'];
        $this->assertNull($menor->representante_nombre);
    }

    public function test_reclamo_sin_bien_es_rechazado_por_la_base_y_no_deja_rastro(): void
    {
        $antes = $this->conteos();
        try {
            $this->registrar(['tipo' => 'reclamo', 'bien_tipo' => null, 'bien_descripcion' => null]);
            $this->fail('Debió rechazarse');
        } catch (QueryException) {
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_el_alta_ignora_campos_que_asigna_el_servidor(): void
    {
        $r = $this->registrar(['estado' => 'respondido', 'numero' => 'FALSO', 'fecha_limite' => '2030-01-01', 'responsable_id' => $this->admin->id, 'identificador' => 'x'])['reclamacion'];
        $this->assertSame('abierto', $r->estado);
        $this->assertSame('000000001-2026', $r->numero);
        $this->assertSame('2026-11-16', $r->fecha_limite->toDateString());
        $this->assertNull($r->responsable_id);
    }

    public function test_aviso_interno_sin_datos_personales_a_quienes_pueden_ver(): void
    {
        $this->registrar();
        $avisos = NotificacionInterna::query()->get();
        $this->assertSame([$this->admin->id], $avisos->pluck('user_id')->all());
        $this->assertStringContainsString('000000001-2026', $avisos[0]->titulo);
        foreach (['Persona', 'Ficticia', 'example.test', '12345678', 'Calle'] as $dato) {
            $this->assertStringNotContainsString($dato, $avisos[0]->titulo.$avisos[0]->mensaje);
        }
    }

    // --- Inmutabilidad ------------------------------------------------------------------------------------

    public function test_los_datos_del_consumidor_no_se_editan_ni_se_elimina_la_reclamacion(): void
    {
        $r = $this->registrar()['reclamacion'];
        foreach (['nombres', 'email', 'detalle', 'numero', 'recibida_at', 'tipo_documento'] as $campo) {
            $copia = Reclamacion::query()->find($r->id);
            $copia->forceFill([$campo => $campo === 'recibida_at' ? now()->addDay() : 'Alterado']);
            try {
                $copia->save();
                $this->fail("No debió permitir editar $campo");
            } catch (LogicException $e) {
                $this->assertStringContainsString($campo, $e->getMessage());
            }
        }
        try {
            $r->delete();
            $this->fail('No debió eliminar');
        } catch (LogicException) {
        }
        $this->assertSame('Persona', $r->fresh()->nombres);
    }

    public function test_historial_y_envios_no_se_modifican_ni_eliminan_desde_la_aplicacion(): void
    {
        $r = $this->registrar()['reclamacion'];
        $h = ReclamacionHistorial::query()->where('reclamacion_id', $r->id)->first();
        foreach ([fn () => $h->update(['nota' => 'x']), fn () => $h->delete(), fn () => $r->envios()->first()->delete()] as $intento) {
            try {
                $intento();
                $this->fail('Debió rechazarse');
            } catch (LogicException) {
            }
        }
        $this->assertSame(1, ReclamacionHistorial::query()->count());
    }

    // --- Permisos -----------------------------------------------------------------------------------------

    public function test_operaciones_de_panel_exigen_el_permiso_de_cada_accion(): void
    {
        $r = $this->registrar()['reclamacion'];
        $sinPermisos = $this->usuarioConPermisos('coordinador');
        $soloVer = $this->usuarioConPermisos('secretaria', ['reclamaciones.ver']);
        $gestor = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver', 'reclamaciones.gestionar']);
        $anonimo = new User(['name' => 'x']);
        $anonimo->id = 999999;

        $this->assertForbidden(fn () => $this->servicio()->consultar($sinPermisos, $r->identificador));
        $this->assertSame($r->id, $this->servicio()->consultar($soloVer, $r->identificador)->id);
        $this->assertForbidden(fn () => $this->servicio()->agregarNota($soloVer, $r->identificador, 'n'));
        $this->assertForbidden(fn () => $this->servicio()->consultar($anonimo, $r->identificador));
        $this->servicio()->agregarNota($gestor, $r->identificador, 'nota válida');
        $this->assertForbidden(fn () => $this->servicio()->registrarRespuesta($gestor, $r->identificador, 'texto', 'correo'));
        $this->assertForbidden(fn () => $this->servicio()->registrarAceptacion($gestor, $r->identificador, '2026-10-26', 'correo', 'c'));
        $this->assertForbidden(fn () => $this->servicio()->registrarRespaldo($soloVer, $this->datosReclamacion(), 'H-1'));
        $this->assertForbidden(fn () => $this->servicio()->reintentarEnvio($soloVer, 1));
    }

    public function test_un_usuario_con_el_permiso_pero_sin_rol_de_panel_no_opera(): void
    {
        $r = $this->registrar()['reclamacion'];
        $externo = User::factory()->create();
        $externo->givePermissionTo('reclamaciones.ver');
        $this->assertForbidden(fn () => $this->servicio()->consultar($externo, $r->identificador));
    }

    public function test_el_permiso_se_relee_de_la_base_en_cada_operacion(): void
    {
        $r = $this->registrar()['reclamacion'];
        $gestor = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver', 'reclamaciones.gestionar']);
        $this->servicio()->agregarNota($gestor, $r->identificador, 'primera');
        $gestor->revokePermissionTo('reclamaciones.gestionar');
        $this->assertForbidden(fn () => $this->servicio()->agregarNota($gestor, $r->identificador, 'segunda'));
    }

    // --- Gestión ------------------------------------------------------------------------------------------

    public function test_asignar_responsable_exige_usuario_del_panel_con_permiso_ver(): void
    {
        $r = $this->registrar()['reclamacion'];
        $ok = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);
        $sinPermiso = $this->usuarioConPermisos('coordinador');
        $this->servicio()->asignarResponsable($this->admin, $r->identificador, $ok->id);
        $this->assertSame($ok->id, $r->fresh()->responsable_id);
        foreach ([$sinPermiso->id, 987654] as $invalido) {
            try {
                $this->servicio()->asignarResponsable($this->admin, $r->identificador, $invalido);
                $this->fail('Debió rechazar al responsable');
            } catch (ValidationException) {
            }
        }
        $this->servicio()->asignarResponsable($this->admin, $r->identificador, null);
        $this->assertNull($r->fresh()->responsable_id);
        $eventos = ReclamacionHistorial::query()->where('evento', 'responsable_asignado')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame($this->admin->name, $eventos[0]->actor_nombre);
    }

    public function test_reclasificar_tipo_y_validaciones(): void
    {
        $queja = $this->registrar(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null])['reclamacion'];
        try {
            $this->servicio()->reclasificarTipo($this->admin, $queja->identificador, 'reclamo');
            $this->fail('Un reclamo exige bien');
        } catch (ValidationException) {
        }
        $reclamo = $this->registrar()['reclamacion'];
        $this->servicio()->reclasificarTipo($this->admin, $reclamo->identificador, 'queja');
        $this->assertSame('queja', $reclamo->fresh()->tipo);
        $this->servicio()->reclasificarTipo($this->admin, $reclamo->identificador, 'queja'); // sin cambio: sin evento
        $this->assertSame(1, ReclamacionHistorial::query()->where('evento', 'tipo_reclasificado')->count());
        $this->expectException(ValidationException::class);
        $this->servicio()->reclasificarTipo($this->admin, $reclamo->identificador, 'sugerencia');
    }

    public function test_notas_van_al_historial_y_no_a_la_auditoria(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->agregarNota($this->admin, $r->identificador, 'Llamé a Persona Ficticia al 900000000.');
        $this->assertSame('Llamé a Persona Ficticia al 900000000.', ReclamacionHistorial::query()->where('evento', 'nota_interna')->sole()->nota);
        $audit = AuditLog::query()->where('action', 'reclamacion_nota')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertStringNotContainsString('Persona', json_encode($audit->changes));
        foreach (['', '   ', str_repeat('x', 2001)] as $mala) {
            try {
                $this->servicio()->agregarNota($this->admin, $r->identificador, $mala);
                $this->fail('Nota inválida');
            } catch (ValidationException) {
            }
        }
    }

    // --- Identificadores UUID (B3) ------------------------------------------------------------------------

    public function test_uuid_valido_inexistente_responde_no_encontrada(): void
    {
        $this->registrar();
        $this->expectException(ModelNotFoundException::class);
        $this->servicio()->consultar($this->admin, (string) Str::uuid());
    }

    public function test_cadena_malformada_no_llega_a_consultar_la_columna_uuid(): void
    {
        $this->registrar();
        $consultas = [];
        DB::listen(function (QueryExecuted $q) use (&$consultas): void {
            $consultas[] = $q->sql;
        });
        foreach (['no-es-uuid', '', "' OR 1=1 --", '00000000-0000-0000-0000-00000000000Z', str_repeat('a', 300)] as $malformada) {
            $consultas = [];
            try {
                $this->servicio()->consultar($this->admin, $malformada);
                $this->fail('Debió responder no encontrada');
            } catch (ModelNotFoundException $e) {
                $this->assertSame(Reclamacion::class, $e->getModel());
            }
            $this->assertSame([], array_values(array_filter($consultas, fn (string $sql) => str_contains($sql, 'reclamaciones'))), 'La cadena no debe alcanzar la consulta');
        }
    }

    public function test_uuid_en_mayusculas_se_normaliza(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->assertSame($r->id, $this->servicio()->consultar($this->admin, strtoupper($r->identificador))->id);
    }

    // --- Booleanos que llegan como texto (B7) ---------------------------------------------------------------

    public function test_es_menor_como_texto_se_interpreta_como_booleano(): void
    {
        $noMenor = $this->registrar(['es_menor' => 'false', 'representante_nombre' => 'No debe guardarse'])['reclamacion']->fresh();
        $this->assertFalse($noMenor->es_menor);
        $this->assertNull($noMenor->representante_nombre);
        $this->assertFalse($this->registrar(['es_menor' => '0'])['reclamacion']->fresh()->es_menor);

        $menor = $this->registrar(['es_menor' => 'true', 'representante_nombre' => 'Tutor', 'representante_domicilio' => 'Calle 2',
            'representante_telefono' => '900111222', 'representante_email' => 'tutor@example.test'])['reclamacion']->fresh();
        $this->assertTrue($menor->es_menor);
        $this->assertSame('Tutor', $menor->representante_nombre);
    }

    public function test_es_menor_no_booleano_se_rechaza_sin_escribir(): void
    {
        $antes = $this->conteos();
        try {
            $this->registrar(['es_menor' => 'quizá']);
            $this->fail('Debió rechazarse');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('es_menor', $e->errors());
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_consultar_deja_constancia_del_acceso_sin_datos_personales(): void
    {
        $r = $this->registrar()['reclamacion'];
        $detalle = $this->servicio()->consultar($this->admin, $r->identificador);
        $this->assertCount(1, $detalle->historial);
        $this->assertCount(1, $detalle->envios);
        $audit = AuditLog::query()->where('action', 'reclamacion_consultada')->sole();
        $this->assertSame(['esquema' => 1, 'numero' => '000000001-2026'], $audit->changes);
    }

    public function test_registro_en_libro_de_respaldo_exige_gestionar_y_no_genera_correo(): void
    {
        $r = $this->servicio()->registrarRespaldo($this->admin, $this->datosReclamacion(), 'H-0042')['reclamacion'];
        $this->assertSame(['respaldo', 'H-0042'], [$r->canal, $r->numero_hoja_respaldo]);
        $this->assertSame(0, ReclamacionEnvio::query()->count());
        $this->assertSame(['registrada', 'hoja_respaldo_registrada'], ReclamacionHistorial::query()->orderBy('id')->pluck('evento')->all());
        $this->assertSame($this->admin->id, AuditLog::query()->where('action', 'reclamacion_registrada')->sole()->user_id);
        foreach (['', str_repeat('H', 21)] as $hoja) {
            try {
                $this->servicio()->registrarRespaldo($this->admin, $this->datosReclamacion(), $hoja);
                $this->fail('Hoja inválida');
            } catch (ValidationException) {
            }
        }
    }

    public function test_el_respaldo_funciona_aunque_el_modulo_publico_este_apagado(): void
    {
        config(['reclamaciones.enabled' => false]);
        $r = $this->servicio()->registrarRespaldo($this->admin, $this->datosReclamacion(), 'H-1')['reclamacion'];
        $this->assertSame('respaldo', $r->canal);
    }

    // --- Propuesta, rechazo, aceptación, vencimiento -----------------------------------------------------

    public function test_propuesta_suspende_el_plazo_cinco_dias_habiles(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'Propuesta de solución', 'Le ofrecemos reprogramar el curso.');
        $r->refresh();
        $this->assertSame(['2026-10-26', '2026-11-02'], [$r->suspension_desde->toDateString(), $r->suspension_hasta->toDateString()]);
        $envio = ReclamacionEnvio::query()->where('tipo', 'propuesta')->sole();
        $this->assertSame('Le ofrecemos reprogramar el curso.', $envio->cuerpo);
        $evento = ReclamacionHistorial::query()->where('evento', 'propuesta_enviada')->sole();
        $this->assertSame($envio->id, $evento->metadatos['envio_id']);

        $this->assertConflicto(fn () => $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'Otra', 'Otra propuesta'));
        $this->assertConflicto(fn () => $this->servicio()->registrarRespuesta($this->admin, $r->identificador, 'Respuesta', 'correo'));
    }

    public function test_propuesta_solo_para_reclamos_y_con_textos_validos(): void
    {
        $queja = $this->registrar(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null])['reclamacion'];
        $this->assertConflicto(fn () => $this->servicio()->enviarPropuesta($this->admin, $queja->identificador, 'Asunto', 'Cuerpo'));
        $reclamo = $this->registrar()['reclamacion'];
        $this->expectException(ValidationException::class);
        $this->servicio()->enviarPropuesta($this->admin, $reclamo->identificador, '', 'Cuerpo');
    }

    public function test_rechazo_reanuda_y_desplaza_el_plazo_por_los_dias_habiles_transcurridos(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'Propuesta', 'Texto de propuesta');
        $this->servicio()->registrarRechazo($this->admin, $r->identificador, '2026-10-29', 'Rechazó por correo.');
        $r->refresh();
        $this->assertNull($r->suspension_desde);
        $this->assertSame('2026-11-19', $r->fecha_limite->toDateString(), '16/11 + 3 días hábiles suspendidos');
        $evento = ReclamacionHistorial::query()->where('evento', 'propuesta_rechazada')->sole();
        $this->assertSame(['fecha_recepcion' => '2026-10-29', 'dias_suspendidos' => 3, 'fecha_limite_nueva' => '2026-11-19'], $evento->metadatos);
        $this->assertSame('Rechazó por correo.', $evento->nota);
        $this->assertSame('abierto', $r->estado);
        // Ya no hay propuesta pendiente: un segundo rechazo es un conflicto.
        $this->assertConflicto(fn () => $this->servicio()->registrarRechazo($this->admin, $r->identificador, '2026-10-30'));
    }

    public function test_rechazo_con_fecha_fuera_de_ventana_o_mal_formada(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        $this->assertConflicto(fn () => $this->servicio()->registrarRechazo($this->admin, $r->identificador, '2026-10-20'));
        $this->assertConflicto(fn () => $this->servicio()->registrarRechazo($this->admin, $r->identificador, '2026-11-09'));
        $this->expectException(ValidationException::class);
        $this->servicio()->registrarRechazo($this->admin, $r->identificador, '2026-02-31');
    }

    public function test_aceptacion_concluye_por_acuerdo_y_no_es_una_respuesta_formal(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        $this->servicio()->registrarAceptacion($this->admin, $r->identificador, '2026-10-28', 'correo', 'De acuerdo, acepto la propuesta.');
        $r->refresh();
        $this->assertSame('concluido_por_acuerdo', $r->estado);
        $this->assertNull($r->respuesta);
        $this->assertNull($r->suspension_desde);
        $this->assertSame(0, ReclamacionHistorial::query()->where('evento', 'respuesta_formal_registrada')->count());
        $this->assertSame('De acuerdo, acepto la propuesta.', ReclamacionHistorial::query()->where('evento', 'propuesta_aceptada')->sole()->nota);
        // Estado final: no se retrocede ni se responde.
        $this->assertConflicto(fn () => $this->servicio()->registrarRespuesta($this->admin, $r->identificador, 'Respuesta', 'carta', 'Carta 1'));
        $this->assertConflicto(fn () => $this->servicio()->reclasificarTipo($this->admin, $r->identificador, 'queja'));
    }

    public function test_aceptacion_valida_medio_y_constancia(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        foreach ([['2026-10-28', 'whatsapp', 'c'], ['2026-10-28', 'correo', '   ']] as [$fecha, $medio, $constancia]) {
            try {
                $this->servicio()->registrarAceptacion($this->admin, $r->identificador, $fecha, $medio, $constancia);
                $this->fail('Debió rechazar');
            } catch (ValidationException) {
            }
        }
        $this->assertSame('abierto', $r->fresh()->estado);
    }

    public function test_vencimiento_perezoso_reanuda_con_los_cinco_dias_y_queda_en_historial(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        $this->assertFalse($this->servicio()->resolverVencimiento($r->identificador), 'Aún dentro de la ventana');
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'America/Lima'));
        $this->assertTrue($this->servicio()->resolverVencimiento($r->identificador));
        $this->assertFalse($this->servicio()->resolverVencimiento($r->identificador), 'Idempotente');
        $r->refresh();
        $this->assertNull($r->suspension_hasta);
        $this->assertSame('2026-11-23', $r->fecha_limite->toDateString(), '16/11 + 5 días hábiles');
        $evento = ReclamacionHistorial::query()->where('evento', 'propuesta_vencida')->sole();
        $this->assertSame(5, $evento->metadatos['dias_suspendidos']);
        $this->assertSame('Sistema', $evento->actor_nombre);
        $this->assertSame(1, AuditLog::query()->where('action', 'reclamacion_propuesta_vencida')->count());
    }

    public function test_el_vencimiento_tambien_se_evalua_al_consultar_o_escribir(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        $this->travelTo(Carbon::parse('2026-11-04 09:00:00', 'America/Lima'));
        $this->servicio()->agregarNota($this->admin, $r->identificador, 'nota tras vencer');
        $this->assertNull($r->fresh()->suspension_desde);
        $this->assertSame(1, ReclamacionHistorial::query()->where('evento', 'propuesta_vencida')->count());
    }

    // --- Respuesta formal ---------------------------------------------------------------------------------

    public function test_respuesta_por_correo_deja_el_caso_abierto_hasta_que_el_correo_se_envia(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->registrarRespuesta($this->admin, $r->identificador, 'Texto de la respuesta formal.', 'correo');
        $r->refresh();
        $this->assertSame('abierto', $r->estado);
        $this->assertNull($r->respuesta);
        $envio = ReclamacionEnvio::query()->where('tipo', 'respuesta')->sole();
        $this->assertSame(['correo', 'pendiente'], [$envio->canal, $envio->estado]);
        $this->assertSame('Texto de la respuesta formal.', $envio->cuerpo);
        $this->assertConflicto(fn () => $this->servicio()->registrarRespuesta($this->admin, $r->identificador, 'Otra', 'correo'));
    }

    public function test_respuesta_por_carta_exige_constancia_y_responde_de_inmediato(): void
    {
        $r = $this->registrar()['reclamacion'];
        try {
            $this->servicio()->registrarRespuesta($this->admin, $r->identificador, 'Texto', 'carta');
            $this->fail('La carta exige constancia');
        } catch (ValidationException) {
        }
        $this->servicio()->registrarRespuesta($this->admin, $r->identificador, 'Texto por carta.', 'carta', 'Carta entregada el 27/10, cargo 0042');
        $r->refresh();
        $this->assertSame(['respondido', 'Texto por carta.'], [$r->estado, $r->respuesta]);
        $this->assertNotNull($r->respondida_at);
        $envio = ReclamacionEnvio::query()->where('canal', 'carta')->sole();
        $this->assertSame(['enviado', 'Carta entregada el 27/10, cargo 0042'], [$envio->estado, $envio->constancia]);
        $this->assertSame(['registrada', 'respuesta_formal_registrada', 'carta_registrada'], ReclamacionHistorial::query()->orderBy('id')->pluck('evento')->all());
        $this->assertSame('respondido', AuditLog::query()->where('action', 'reclamacion_respondida')->sole()->changes['estado']);
        $this->assertConflicto(fn () => $this->servicio()->reclasificarTipo($this->admin, $r->identificador, 'queja'));
    }

    // --- Auditoría ----------------------------------------------------------------------------------------

    public function test_la_auditoria_rechaza_claves_y_valores_con_posibles_datos_personales(): void
    {
        $r = $this->registrar()['reclamacion'];
        foreach ([['email' => 'x'], ['nombres' => 'Persona'], ['estado' => 'persona@example.test'], ['estado' => 'Persona Ficticia'], ['campos' => ['a b']]] as $mala) {
            try {
                AuditoriaReclamaciones::registrar('prueba', $r, $mala, null);
                $this->fail('Debió rechazar '.json_encode($mala));
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(0, AuditLog::query()->where('action', 'reclamacion_prueba')->count());
    }

    public function test_ningun_registro_de_auditoria_contiene_datos_del_consumidor(): void
    {
        $r = $this->registrar()['reclamacion'];
        $this->servicio()->agregarNota($this->admin, $r->identificador, 'nota');
        $this->servicio()->enviarPropuesta($this->admin, $r->identificador, 'P', 'Texto');
        $this->servicio()->registrarRechazo($this->admin, $r->identificador, '2026-10-27');
        $this->servicio()->registrarRespuesta($this->admin, $r->identificador, 'Respuesta', 'carta', 'Carta 1');
        $volcado = AuditLog::query()->where('action', 'like', 'reclamacion_%')->get()->map(fn ($a) => json_encode($a->changes).$a->model_type)->implode('|');
        foreach (['Persona', 'Ficticia', 'persona.ficticia', 'example.test', '12345678', 'Calle Ficticia', '900', 'Curso sintético', 'Detalle sintético'] as $dato) {
            $this->assertStringNotContainsString($dato, $volcado);
        }
        $this->assertGreaterThanOrEqual(5, AuditLog::query()->where('action', 'like', 'reclamacion_%')->count());
    }

    public function test_el_historial_conserva_la_autoria_aunque_se_elimine_la_cuenta(): void
    {
        $r = $this->registrar()['reclamacion'];
        $gestor = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver', 'reclamaciones.gestionar']);
        $this->servicio()->agregarNota($gestor, $r->identificador, 'nota de autoría');
        $nombre = $gestor->name;
        $gestor->delete();
        $fila = ReclamacionHistorial::query()->where('evento', 'nota_interna')->sole();
        $this->assertNull($fila->actor_id);
        $this->assertSame($nombre, $fila->actor_nombre);
    }

    // --- Sin correos reales -------------------------------------------------------------------------------

    public function test_el_alta_nunca_envia_correo_con_el_envio_apagado(): void
    {
        $this->registrar();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    private function assertForbidden(callable $accion): void
    {
        try {
            $accion();
            $this->fail('Debió responder 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function assertConflicto(callable $accion): void
    {
        try {
            $accion();
            $this->fail('Debió responder 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
