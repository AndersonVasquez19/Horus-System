<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Jobs\AvisarEmpresaReclamacion;
use App\Jobs\EnviarReclamacionCorreo;
use App\Mail\ReclamacionMail;
use App\Models\NotificacionInterna;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use App\Services\EnvioReclamaciones;
use App\Services\GestionReclamaciones;
use App\Support\DiasHabiles;
use App\Support\Reclamaciones;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PreparaReclamaciones;

/**
 * Apertura del Libro de Reclamaciones por correo: requisitos de activación, aviso a la empresa, «Responder a», fallos
 * visibles, propuestas desactivadas y operaciones disponibles según el estado. `Mail` y la cola están simulados: no se
 * envía ningún correo. SQLite en memoria: no mide PostgreSQL ni concurrencia.
 */
final class ReclamacionesPorCorreoTest extends PanelApiTestCase
{
    use PreparaReclamaciones;

    private const EMPRESA = 'empresa@horus.test';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones(habilitar: true);
        config(['reclamaciones.propuestas_habilitadas' => false]);
    }

    private function nueva(array $extra = []): Reclamacion
    {
        return $this->registrar($extra)['reclamacion'];
    }

    /** @param array<string, mixed> $datos */
    private function gestionar(User $usuario, Reclamacion $r, array $datos): TestResponse
    {
        return $this->actingAs($usuario)
            ->json('POST', '/api/panel/v1/compartido/reclamaciones/'.$r->identificador.'/gestion', $datos, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function expedienteDe(User $usuario, Reclamacion $r): TestResponse
    {
        return $this->actingAs($usuario)
            ->json('GET', '/api/panel/v1/compartido/reclamaciones/'.$r->identificador, [], ['X-Horus-Web-Token' => self::TOKEN])
            ->assertOk();
    }

    // --- Activación ------------------------------------------------------------------------------------------

    public function test_solo_se_habilita_con_envio_activo_correo_de_la_empresa_y_datos_del_proveedor(): void
    {
        $this->assertTrue(Reclamaciones::habilitado(), 'La configuración completa de las pruebas debe habilitarlo');

        $faltantes = [
            'reclamaciones.correo.enabled' => false,
            'reclamaciones.correo.empresa' => null,
            'reclamaciones.proveedor.razon_social' => null,
            'reclamaciones.proveedor.ruc' => ' ',
            'reclamaciones.proveedor.direccion' => '',
            'reclamaciones.proveedor.codigo_libro' => null,
        ];
        foreach ($faltantes as $clave => $valor) {
            $original = config($clave);
            config([$clave => $valor]);
            $this->assertFalse(Reclamaciones::habilitado(), $clave.' debe ser obligatorio');
            config([$clave => $original]);
        }

        config(['reclamaciones.correo.empresa' => 'no-es-un-correo']);
        $this->assertFalse(Reclamaciones::habilitado());
    }

    public function test_el_formulario_publico_informa_apagado_si_falta_un_requisito(): void
    {
        config(['reclamaciones.correo.empresa' => null]);

        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->getJson('/api/public/v1/reclamaciones/formulario')
            ->assertOk()->assertJson(['habilitado' => false, 'marca' => null, 'token_envio' => null]);
    }

    public function test_los_avisos_de_plazo_siguen_aunque_se_pause_la_recepcion(): void
    {
        $this->nueva();
        config(['reclamaciones.correo.enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', 'America/Lima'));

        $this->artisan('horus:reclamaciones-vencimientos')->doesntExpectOutputToContain('desactivado')->assertSuccessful();
    }

    // --- Aviso a la empresa y «Responder a» ------------------------------------------------------------------

    public function test_cada_hoja_nueva_encola_un_solo_aviso_a_la_empresa(): void
    {
        $token = (string) Str::uuid();
        $primera = $this->registrar([], $token);
        $this->registrar([], $token);

        Queue::assertPushed(AvisarEmpresaReclamacion::class, 1);
        Queue::assertPushed(AvisarEmpresaReclamacion::class, fn ($job): bool => $job->reclamacionId === $primera['reclamacion']->id);
    }

    public function test_el_aviso_lleva_la_hoja_a_la_empresa_y_responder_a_es_el_consumidor(): void
    {
        $r = $this->nueva();

        app()->call([new AvisarEmpresaReclamacion($r->id), 'handle']);

        Mail::assertSent(ReclamacionMail::class, function (ReclamacionMail $m) use ($r): bool {
            return $m->hasTo(self::EMPRESA) && ! $m->hasTo($r->email)
                && $m->envelope()->replyTo[0]->address === $r->email
                && str_contains($m->envelope()->subject, $r->numero);
        });
        Mail::assertSentCount(1);
    }

    public function test_con_el_envio_apagado_el_aviso_no_sale(): void
    {
        $r = $this->nueva();
        config(['reclamaciones.correo.enabled' => false]);

        app()->call([new AvisarEmpresaReclamacion($r->id), 'handle']);

        Mail::assertNothingSent();
    }

    public function test_la_copia_al_consumidor_pide_responder_al_correo_de_la_empresa(): void
    {
        $r = $this->nueva();
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->where('tipo', 'copia_hoja')->sole();

        app(EnvioReclamaciones::class)->enviar($envio->id);

        Mail::assertSent(ReclamacionMail::class, fn (ReclamacionMail $m): bool => $m->hasTo($r->email) && $m->envelope()->replyTo[0]->address === self::EMPRESA);
    }

    // --- Fallos visibles --------------------------------------------------------------------------------------

    public function test_un_aviso_a_la_empresa_que_falla_del_todo_avisa_en_el_panel_sin_datos_personales(): void
    {
        $r = $this->nueva();
        NotificacionInterna::query()->delete();

        (new AvisarEmpresaReclamacion($r->id))->failed(new RuntimeException('detalle con '.$r->email));

        $aviso = NotificacionInterna::query()->where('titulo', 'Correo no enviado: reclamación '.$r->numero)->sole();
        $this->assertSame('/admin/academico/reclamaciones/'.$r->identificador, $aviso->vinculo);
        $this->assertStringNotContainsString($r->email, $aviso->mensaje);
        $this->assertStringNotContainsString($r->nombres, $aviso->mensaje);
    }

    public function test_un_envio_que_agota_sus_intentos_avisa_en_el_panel(): void
    {
        $r = $this->nueva();
        NotificacionInterna::query()->delete();
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->sole();
        $envio->forceFill(['intentos' => Reclamaciones::maxIntentos()])->save();

        app(EnvioReclamaciones::class)->enviar($envio->id);

        $this->assertSame('fallido', $envio->fresh()->estado);
        $this->assertSame(1, NotificacionInterna::query()->where('titulo', 'Correo no enviado: reclamación '.$r->numero)->count());
    }

    // --- Propuestas desactivadas ------------------------------------------------------------------------------

    public function test_las_propuestas_desactivadas_responden_409_sin_suspender_el_plazo(): void
    {
        $r = $this->nueva();
        $limite = $r->fecha_limite->toDateString();

        $operaciones = [
            ['operacion' => 'propuesta', 'asunto' => 'Propuesta', 'cuerpo' => 'Texto.', 'confirmar_envio' => true],
            ['operacion' => 'rechazo', 'fecha_recepcion' => '2026-10-26'],
            ['operacion' => 'aceptacion', 'fecha_recepcion' => '2026-10-26', 'medio' => 'correo', 'constancia' => 'Acepto'],
        ];
        foreach ($operaciones as $datos) {
            $this->gestionar($this->admin, $r, $datos)->assertStatus(409)->assertJsonFragment(['message' => 'Las propuestas de solución están desactivadas.']);
        }

        $r->refresh();
        $this->assertNull($r->suspension_desde);
        $this->assertSame($limite, $r->fecha_limite->toDateString());
        $this->assertSame(0, ReclamacionEnvio::query()->where('tipo', 'propuesta')->count());
    }

    // --- Operaciones según el estado --------------------------------------------------------------------------

    public function test_el_expediente_ofrece_solo_las_operaciones_validas_de_un_caso_abierto(): void
    {
        $r = $this->nueva();

        $meta = $this->expedienteDe($this->admin, $r)->json('meta');

        $this->assertSame(['responsable', 'tipo', 'nota', 'respuesta'], $meta['operaciones']);
        $this->assertSame(['correo', 'carta'], $meta['canales_respuesta']);
        $this->assertNull($meta['respuesta_correo']);
    }

    public function test_con_una_respuesta_por_correo_sin_entregar_solo_queda_la_carta(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'correo', 'confirmar_envio' => true])
            ->assertOk()->assertJson(['message' => 'Respuesta registrada. El caso pasará a «Respondido» cuando el correo se envíe.']);

        $meta = $this->expedienteDe($this->admin, $r)->json('meta');

        $this->assertSame(['carta'], $meta['canales_respuesta']);
        $this->assertSame('pendiente', $meta['respuesta_correo']);
        Queue::assertPushed(EnviarReclamacionCorreo::class);
    }

    public function test_con_el_envio_apagado_el_mensaje_dice_que_la_respuesta_queda_pendiente(): void
    {
        $r = $this->nueva();
        config(['reclamaciones.correo.enabled' => false]);

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'correo'])
            ->assertOk()->assertJson(['message' => 'Respuesta registrada, pero el envío de correos está apagado: queda pendiente y el caso sigue abierto.']);
    }

    public function test_un_caso_cerrado_solo_admite_responsable_y_nota(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'carta', 'constancia' => 'Cargo 1'])
            ->assertOk()->assertJson(['message' => 'Respuesta formal registrada.']);

        $meta = $this->expedienteDe($this->admin, $r)->json('meta');

        $this->assertSame(['responsable', 'nota'], $meta['operaciones']);
        $this->assertSame([], $meta['canales_respuesta']);
    }

    public function test_una_queja_sin_bien_no_puede_pasar_a_reclamo_y_cada_permiso_ve_lo_suyo(): void
    {
        $queja = $this->nueva(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]);
        $gestor = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver', 'reclamaciones.gestionar']);
        $respondedor = $this->usuarioConPermisos('secretaria', ['reclamaciones.ver', 'reclamaciones.responder']);
        $lector = $this->usuarioConPermisos('secretaria', ['reclamaciones.ver']);

        $this->assertSame(['responsable', 'nota'], $this->expedienteDe($gestor, $queja)->json('meta.operaciones'));
        $this->assertSame(['respuesta'], $this->expedienteDe($respondedor, $queja)->json('meta.operaciones'));
        $this->assertSame([], $this->expedienteDe($lector, $queja)->json('meta.operaciones'));
    }

    public function test_con_las_propuestas_activas_se_ofrecen_segun_la_suspension(): void
    {
        config(['reclamaciones.propuestas_habilitadas' => true]);
        $r = $this->nueva();

        $this->assertSame(['responsable', 'tipo', 'nota', 'propuesta', 'respuesta'], $this->expedienteDe($this->admin, $r)->json('meta.operaciones'));

        $this->gestionar($this->admin, $r, ['operacion' => 'propuesta', 'asunto' => 'Propuesta', 'cuerpo' => 'Texto.', 'confirmar_envio' => true])->assertOk();

        $this->assertSame(['responsable', 'tipo', 'nota', 'rechazo', 'aceptacion'], $this->expedienteDe($this->admin, $r)->json('meta.operaciones'));
    }

    public function test_el_servicio_tambien_rechaza_una_propuesta_desactivada(): void
    {
        $r = $this->nueva();

        try {
            app(GestionReclamaciones::class)->enviarPropuesta($this->admin, $r->identificador, 'Asunto', 'Cuerpo');
            $this->fail('Debió responder 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // --- Feriados y privacidad ---------------------------------------------------------------------------------

    public function test_los_feriados_nacionales_de_2026_y_2027_no_cuentan_como_dias_habiles(): void
    {
        foreach (['2026-12-08', '2026-12-09', '2026-12-25', '2027-01-01', '2027-03-25', '2027-03-26', '2027-07-29'] as $feriado) {
            $this->assertFalse(DiasHabiles::esHabil(CarbonImmutable::parse($feriado, 'America/Lima')), $feriado);
        }
        $this->assertTrue(DiasHabiles::esHabil(CarbonImmutable::parse('2026-12-10', 'America/Lima')));
    }

    public function test_la_version_de_privacidad_ya_no_es_un_borrador_y_cabe_en_la_columna(): void
    {
        $version = (string) config('reclamaciones.privacidad_version');

        $this->assertStringNotContainsString('borrador', $version);
        $this->assertLessThanOrEqual(20, strlen($version));
    }
}
