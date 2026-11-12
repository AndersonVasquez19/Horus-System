<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnviarReclamacionCorreo;
use App\Mail\ReclamacionMail;
use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\ReclamacionHistorial;
use App\Services\EnvioReclamaciones;
use App\Services\GestionReclamaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

class EnvioReclamacionesTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararReclamaciones();
        Mail::fake();
    }

    private function envio(): EnvioReclamaciones
    {
        return app(EnvioReclamaciones::class);
    }

    /** Alta con el envío por cola ya habilitado; devuelve la reclamación y su copia de la hoja. */
    private function altaConEnvio(): array
    {
        $this->habilitarReclamaciones(envio: true);
        $r = $this->registrar()['reclamacion'];

        return [$r, ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->where('tipo', 'copia_hoja')->sole()];
    }

    /** Simula un transporte que falla $fallos veces y luego acepta. @return object{llamadas: int} */
    private function transporteQueFalla(int $fallos): object
    {
        $estado = new \stdClass;
        $estado->llamadas = 0;
        $pendiente = Mockery::mock();
        $pendiente->shouldReceive('send')->andReturn(null);
        Mail::shouldReceive('to')->andReturnUsing(function () use ($estado, $fallos, $pendiente) {
            $estado->llamadas++;
            if ($estado->llamadas <= $fallos) {
                throw new TransportException('fallo smtp con datos de persona.ficticia@example.test');
            }

            return $pendiente;
        });

        return $estado;
    }

    public function test_envio_apagado_no_despacha_ni_envia_y_deja_la_fila_pendiente(): void
    {
        $r = $this->registrar()['reclamacion'];
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->sole();
        Queue::assertNothingPushed();
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar($envio->id));
        Mail::assertNothingSent();
        $this->assertSame(['pendiente', 0], [$envio->fresh()->estado, $envio->fresh()->intentos]);
    }

    public function test_envio_habilitado_despacha_un_job_con_solo_el_id(): void
    {
        [, $envio] = $this->altaConEnvio();
        Queue::assertPushed(EnviarReclamacionCorreo::class, 1);
        Queue::assertPushed(EnviarReclamacionCorreo::class, function (EnviarReclamacionCorreo $job) use ($envio): bool {
            $this->assertSame(4, $job->tries);
            $this->assertSame([60, 300, 900], $job->backoff());

            return $job->envioId === $envio->id;
        });
        $this->assertStringNotContainsString('example.test', serialize(new EnviarReclamacionCorreo($envio->id)));
    }

    public function test_envio_se_despacha_en_la_cola_configurada(): void
    {
        config(['reclamaciones.correo.cola' => 'reclamaciones-prueba']);

        $this->altaConEnvio();

        Queue::assertPushedOn('reclamaciones-prueba', EnviarReclamacionCorreo::class);
    }

    public function test_envio_correcto_guarda_la_evidencia_del_contenido_exacto(): void
    {
        [$r, $envio] = $this->altaConEnvio();
        $cuerpo = $envio->cuerpo;

        $this->assertSame(EnvioReclamaciones::ENVIADO, $this->envio()->enviar($envio->id));

        Mail::assertSent(ReclamacionMail::class, 1);
        Mail::assertSent(ReclamacionMail::class, function (ReclamacionMail $m) use ($envio, $cuerpo): bool {
            $m->assertHasSubject($envio->asunto);
            $this->assertTrue($m->hasTo('persona.ficticia@example.test'));
            $this->assertStringContainsString(e($cuerpo), $m->render());

            return true;
        });
        $envio->refresh();
        $this->assertSame(['enviado', 1, null], [$envio->estado, $envio->intentos, $envio->error_codigo]);
        $this->assertNotNull($envio->enviado_at);
        $this->assertSame($cuerpo, $envio->cuerpo, 'El cuerpo guardado es la constancia y no cambia');
        $this->assertSame(['registrada', 'correo_enviado'], ReclamacionHistorial::query()->orderBy('id')->pluck('evento')->all());
        $this->assertSame('copia_hoja', ReclamacionHistorial::query()->where('evento', 'correo_enviado')->sole()->metadatos['tipo']);
        $this->assertSame('abierto', $r->fresh()->estado, 'La copia de la hoja no responde el caso');
        $this->assertSame(1, AuditLog::query()->where('action', 'reclamacion_correo_enviado')->count());
    }

    public function test_el_texto_enviado_no_depende_de_cambios_posteriores_en_el_caso(): void
    {
        [$r, $envio] = $this->altaConEnvio();
        $this->servicioGestion()->reclasificarTipo($this->admin, $r->identificador, 'queja');
        $this->envio()->enviar($envio->id);
        $this->assertStringContainsString('Tipo: Reclamo', $envio->fresh()->cuerpo);
    }

    public function test_no_duplica_el_envio_si_se_invoca_dos_veces(): void
    {
        [, $envio] = $this->altaConEnvio();
        $this->assertSame(EnvioReclamaciones::ENVIADO, $this->envio()->enviar($envio->id));
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar($envio->id));
        Mail::assertSent(ReclamacionMail::class, 1);
        $this->assertSame(1, $envio->fresh()->intentos);
    }

    public function test_job_duplicado_en_cola_tampoco_duplica_el_correo(): void
    {
        [, $envio] = $this->altaConEnvio();
        (new EnviarReclamacionCorreo($envio->id))->handle($this->envio());
        (new EnviarReclamacionCorreo($envio->id))->handle($this->envio());
        Mail::assertSent(ReclamacionMail::class, 1);
    }

    public function test_reserva_vigente_de_otro_trabajador_impide_enviar(): void
    {
        [, $envio] = $this->altaConEnvio();
        DB::table('reclamacion_envios')->where('id', $envio->id)->update(['estado' => 'procesando', 'intentos' => 1, 'updated_at' => now()]);
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar($envio->id));
        Mail::assertNothingSent();
        $this->assertSame(1, $envio->fresh()->intentos);
    }

    public function test_reserva_abandonada_se_recupera_pasado_el_plazo_de_reserva(): void
    {
        [, $envio] = $this->altaConEnvio();
        DB::table('reclamacion_envios')->where('id', $envio->id)->update(['estado' => 'procesando', 'intentos' => 1, 'updated_at' => now()->subMinutes(11)]);
        $this->assertSame(EnvioReclamaciones::ENVIADO, $this->envio()->enviar($envio->id));
        Mail::assertSent(ReclamacionMail::class, 1);
        $this->assertSame(2, $envio->fresh()->intentos);
    }

    public function test_un_fallo_deja_la_fila_pendiente_y_relanza_una_excepcion_generica_sin_datos_personales(): void
    {
        [, $envio] = $this->altaConEnvio();
        $this->transporteQueFalla(99);
        Log::shouldReceive('warning')->once()->with('reclamacion.envio', ['envio_id' => $envio->id, 'outcome' => 'transporte']);

        try {
            $this->envio()->enviar($envio->id);
            $this->fail('Debió lanzar para que la cola reintente');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('example.test', $e->getMessage());
            $this->assertStringNotContainsString('persona', $e->getMessage());
        }
        $envio->refresh();
        $this->assertSame(['pendiente', 1, 'transporte'], [$envio->estado, $envio->intentos, $envio->error_codigo]);
        $this->assertSame(0, ReclamacionHistorial::query()->where('evento', 'correo_fallido')->count(), 'Aún quedan intentos');
    }

    public function test_reintenta_tras_un_fallo_transitorio_y_termina_enviado(): void
    {
        [, $envio] = $this->altaConEnvio();
        $estado = $this->transporteQueFalla(2);
        Log::shouldReceive('warning')->twice();

        foreach ([1, 2] as $_) {
            try {
                $this->envio()->enviar($envio->id);
                $this->fail('Debió fallar');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame(EnvioReclamaciones::ENVIADO, $this->envio()->enviar($envio->id));
        $envio->refresh();
        $this->assertSame(['enviado', 3, null], [$envio->estado, $envio->intentos, $envio->error_codigo]);
        $this->assertSame(3, $estado->llamadas);
        $this->assertSame(0, ReclamacionHistorial::query()->where('evento', 'correo_fallido')->count());
    }

    public function test_agota_los_cuatro_intentos_y_queda_fallido_con_constancia_sin_revertir_el_caso(): void
    {
        [$r, $envio] = $this->altaConEnvio();
        $this->transporteQueFalla(99);
        Log::shouldReceive('warning')->times(4);

        foreach ([1, 2, 3] as $_) {
            try {
                $this->envio()->enviar($envio->id);
                $this->fail('Debió relanzar');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame(EnvioReclamaciones::FALLIDO, $this->envio()->enviar($envio->id), 'El cuarto intento agota y no relanza');

        $envio->refresh();
        $this->assertSame(['fallido', 4, 'transporte'], [$envio->estado, $envio->intentos, $envio->error_codigo]);
        $this->assertSame('abierto', $r->fresh()->estado);
        $this->assertNotNull(Reclamacion::query()->find($r->id), 'El fallo de correo no revierte el registro');
        $fallo = ReclamacionHistorial::query()->where('evento', 'correo_fallido')->sole();
        $this->assertSame('transporte', $fallo->metadatos['resultado']);
        $this->assertStringNotContainsString('example.test', json_encode($fallo->metadatos));
        $this->assertSame(1, AuditLog::query()->where('action', 'reclamacion_correo_fallido')->count());

        // Un fallido no se reenvía solo.
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar($envio->id));
    }

    public function test_reintento_manual_reutiliza_la_misma_fila_y_exige_permiso(): void
    {
        [$r, $envio] = $this->altaConEnvio();
        DB::table('reclamacion_envios')->where('id', $envio->id)->update(['estado' => 'fallido', 'intentos' => 4, 'error_codigo' => 'transporte']);
        $soloVer = $this->usuarioConPermisos('secretaria', ['reclamaciones.ver']);
        try {
            $this->servicioGestion()->reintentarEnvio($soloVer, $envio->id);
            $this->fail('Debió responder 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        Queue::fake();
        $this->servicioGestion()->reintentarEnvio($this->admin, $envio->id);
        Queue::assertPushed(EnviarReclamacionCorreo::class, 1);
        $this->assertSame(['pendiente', 0, null], [$envio->fresh()->estado, $envio->fresh()->intentos, $envio->fresh()->error_codigo]);
        $this->assertSame(1, ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->count(), 'Misma fila, sin duplicar');
        $this->assertSame(EnvioReclamaciones::ENVIADO, $this->envio()->enviar($envio->id));
        // Solo se reintenta lo fallido.
        try {
            $this->servicioGestion()->reintentarEnvio($this->admin, $envio->id);
            $this->fail('Debió responder 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_correo_de_respuesta_cierra_el_caso_con_el_texto_exacto_solo_al_enviarse(): void
    {
        $this->habilitarReclamaciones(envio: true);
        $r = $this->registrar()['reclamacion'];
        $this->servicioGestion()->registrarRespuesta($this->admin, $r->identificador, 'Respuesta formal íntegra.', 'correo');
        $respuesta = ReclamacionEnvio::query()->where('tipo', 'respuesta')->sole();
        $this->assertSame('abierto', $r->fresh()->estado);

        $this->envio()->enviar($respuesta->id);

        $r->refresh();
        $this->assertSame(['respondido', 'Respuesta formal íntegra.'], [$r->estado, $r->respuesta]);
        $this->assertNotNull($r->respondida_at);
        $this->assertContains('correo_enviado', ReclamacionHistorial::query()->pluck('evento')->all());
        Mail::assertSent(ReclamacionMail::class, fn (ReclamacionMail $m) => $m->hasTo('persona.ficticia@example.test'));
    }

    public function test_si_ya_respondio_por_carta_el_correo_posterior_no_pisa_la_respuesta(): void
    {
        $this->habilitarReclamaciones(envio: true);
        $r = $this->registrar()['reclamacion'];
        $this->servicioGestion()->registrarRespuesta($this->admin, $r->identificador, 'Respuesta por correo.', 'correo');
        $this->servicioGestion()->registrarRespuesta($this->admin, $r->identificador, 'Respuesta por carta.', 'carta', 'Carta 7');
        $this->assertSame('Respuesta por carta.', $r->fresh()->respuesta);

        $this->envio()->enviar(ReclamacionEnvio::query()->where('tipo', 'respuesta')->where('canal', 'correo')->value('id'));

        $this->assertSame('Respuesta por carta.', $r->fresh()->respuesta);
        $this->assertSame('respondido', $r->fresh()->estado);
    }

    public function test_el_envio_exige_mailer_real_aunque_el_flag_de_correo_este_en_true(): void
    {
        [, $envio] = $this->altaConEnvio();
        config(['mail.default' => 'log']);
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar($envio->id));
        config(['mail.default' => 'array']);
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar($envio->id));
        Mail::assertNothingSent();
        $this->assertSame(0, $envio->fresh()->intentos);
    }

    public function test_los_envios_por_carta_no_se_procesan_como_correo(): void
    {
        $this->habilitarReclamaciones(envio: true);
        $r = $this->registrar()['reclamacion'];
        $this->servicioGestion()->registrarRespuesta($this->admin, $r->identificador, 'Texto', 'carta', 'Carta 1');
        $carta = ReclamacionEnvio::query()->where('canal', 'carta')->sole();
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar($carta->id));
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->envio()->enviar(987654));
        Mail::assertNothingSent();
        Queue::assertPushed(EnviarReclamacionCorreo::class, 1); // solo la copia de la hoja
    }

    private function servicioGestion(): GestionReclamaciones
    {
        return app(GestionReclamaciones::class);
    }
}
