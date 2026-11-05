<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\StoreReclamacionRequest;
use App\Models\Reclamacion;
use App\Support\AntispamReclamaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/**
 * E1 — Endpoint HTTP público del Libro de Reclamaciones (SQLite en memoria). La activación se simula con
 * config([...]) en cada prueba; config/reclamaciones.php sigue con el módulo apagado.
 */
class LibroReclamacionesHttpTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    private const RUTA = '/libro-reclamaciones';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones(habilitar: false);
    }

    /** @return array<string, mixed> */
    private function cuerpo(array $extra = []): array
    {
        return $extra + $this->datosReclamacion() + [
            'marca' => AntispamReclamaciones::emitirMarca(time() - 30),
            'token_envio' => (string) Str::uuid(),
        ];
    }

    private function enviar(array $cuerpo): TestResponse
    {
        return $this->postJson(self::RUTA, $cuerpo);
    }

    /** @return list<int> */
    private function conteos(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['reclamaciones', 'reclamacion_historial', 'reclamacion_envios', 'reclamacion_contadores', 'audit_logs']);
    }

    // --- Módulo apagado (estado por defecto) ---------------------------------------------------------------

    public function test_el_modulo_sigue_apagado_en_la_configuracion_versionada(): void
    {
        $config = require base_path('config/reclamaciones.php');
        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['correo']['enabled']);
    }

    public function test_apagado_la_pagina_se_muestra_sin_marca_ni_token(): void
    {
        $respuesta = $this->get(self::RUTA)->assertOk()->assertViewIs('pages.legales.libro-reclamaciones');

        $respuesta->assertViewHas('habilitado', false);
        $respuesta->assertViewHas('marca', null);
        $respuesta->assertViewHas('tokenEnvio', null);
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }

    public function test_apagado_el_alta_responde_404_y_no_escribe_nada(): void
    {
        $antes = $this->conteos();

        $this->enviar($this->cuerpo())->assertNotFound();
        // Apagado no se valida: un cuerpo vacío también es 404, nunca 422.
        $this->enviar([])->assertNotFound();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_flag_activo_sin_correo_real_sigue_respondiendo_404(): void
    {
        config(['reclamaciones.enabled' => true, 'mail.default' => 'log', 'mail.from.address' => 'libro@horus.test']);

        $this->enviar($this->cuerpo())->assertNotFound();
        $this->assertSame(0, Reclamacion::query()->count());
    }

    // --- Módulo encendido (simulado) ------------------------------------------------------------------------

    public function test_encendido_la_pagina_entrega_marca_valida_y_token_uuid(): void
    {
        $this->habilitarReclamaciones();
        $respuesta = $this->get(self::RUTA)->assertOk();

        $respuesta->assertViewHas('habilitado', true);
        $respuesta->assertViewHas('tokenEnvio', fn (mixed $t) => is_string($t) && Str::isUuid($t));
        $marca = $respuesta->viewData('marca');
        $this->assertIsString($marca);
        $this->assertFalse(AntispamReclamaciones::marcaValida($marca), 'Recién emitida aún no alcanza el mínimo de segundos.');
        $this->assertTrue(AntispamReclamaciones::marcaValida($marca, time() + 30));
    }

    public function test_queja_sin_bien_se_registra_con_numero_real(): void
    {
        $this->habilitarReclamaciones();
        $cuerpo = $this->cuerpo(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]);

        $respuesta = $this->enviar($cuerpo)->assertCreated();

        $respuesta->assertExactJson(['numero' => '000000001-2026', 'tipo' => 'queja', 'fecha_limite' => '2026-11-16', 'repetida' => false]);
        $r = Reclamacion::query()->sole();
        $this->assertSame(['queja', 'web', 'abierto', '000000001-2026'], [$r->tipo, $r->canal, $r->estado, $r->numero]);
        $this->assertNull($r->bien_tipo);
        $this->assertSame('Pedido sintético.', $r->pedido);
        $this->assertSame(hash('sha256', $cuerpo['token_envio']), $r->token_envio, 'El token se guarda solo como hash.');
        $this->assertSame(1, DB::table('reclamacion_historial')->where('reclamacion_id', $r->id)->count());
        $this->assertSame(1, DB::table('reclamacion_envios')->where('reclamacion_id', $r->id)->count());
    }

    public function test_reclamo_con_monto_y_reclamo_con_no_aplica_se_registran_correlativos(): void
    {
        $this->habilitarReclamaciones();

        $this->enviar($this->cuerpo(['monto_bien' => '150.50']))->assertCreated()->assertJsonPath('numero', '000000001-2026');
        $this->enviar($this->cuerpo(['monto_bien' => null, 'monto_no_aplica' => true, 'email' => 'otra.persona@example.test']))
            ->assertCreated()->assertJsonPath('numero', '000000002-2026')->assertJsonPath('tipo', 'reclamo');

        $montos = Reclamacion::query()->orderBy('id')->pluck('monto_bien')->all();
        $this->assertSame('150.50', number_format((float) $montos[0], 2, '.', ''));
        $this->assertNull($montos[1], '«No aplica» guarda nulo, nunca 0.');
    }

    public function test_mismo_token_dos_veces_devuelve_200_con_el_mismo_numero_y_una_sola_fila(): void
    {
        $this->habilitarReclamaciones();
        $cuerpo = $this->cuerpo();

        $this->enviar($cuerpo)->assertCreated()->assertJsonPath('numero', '000000001-2026');
        $this->enviar($cuerpo)->assertOk()->assertExactJson([
            'numero' => '000000001-2026', 'tipo' => 'reclamo', 'fecha_limite' => '2026-11-16', 'repetida' => true,
        ]);

        $this->assertSame(1, Reclamacion::query()->count());
        $this->assertSame(1, DB::table('reclamacion_envios')->count());
    }

    public function test_la_respuesta_no_contiene_datos_personales(): void
    {
        $this->habilitarReclamaciones();
        $contenido = (string) $this->enviar($this->cuerpo())->assertCreated()->getContent();

        foreach (['Persona', 'Ficticia', '12345678', 'persona.ficticia', '900 000 000', 'Calle Ficticia', 'identificador', 'token_envio'] as $dato) {
            $this->assertStringNotContainsString($dato, $contenido);
        }
    }

    public function test_faltan_campos_obligatorios_responde_422_por_campo_sin_escribir(): void
    {
        $this->habilitarReclamaciones();
        $antes = $this->conteos();

        $this->enviar(['tipo' => 'reclamo'])->assertUnprocessable()->assertJsonValidationErrors([
            'marca', 'token_envio', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'domicilio',
            'telefono', 'email', 'bien_tipo', 'bien_descripcion', 'detalle', 'pedido',
        ]);
        $this->enviar($this->cuerpo(['tipo' => 'sugerencia']))->assertUnprocessable()->assertJsonValidationErrors(['tipo']);

        $this->assertSame($antes, $this->conteos());
    }

    public function test_campos_asignados_por_el_servidor_estan_prohibidos(): void
    {
        $this->habilitarReclamaciones();

        $this->enviar($this->cuerpo(['estado' => 'respondido', 'numero' => '999999999-2026', 'canal' => 'respaldo', 'responsable_id' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors(['estado', 'numero', 'canal', 'responsable_id']);
        $this->assertSame(0, Reclamacion::query()->count());
    }

    public function test_marca_rapida_o_invalida_y_honeypot_lleno_responden_422(): void
    {
        $this->habilitarReclamaciones();
        // El límite también cuenta los intentos inválidos (ver test_el_limite_de_envios_responde_429); aquí se
        // amplía solo para poder comprobar cuatro rechazos seguidos.
        config(['reclamaciones.antispam.limite_ip_10min' => 20, 'reclamaciones.antispam.limite_ip_dia' => 20, 'reclamaciones.antispam.limite_correo_dia' => 20]);

        $this->enviar($this->cuerpo(['marca' => AntispamReclamaciones::emitirMarca(time())]))
            ->assertUnprocessable()->assertJsonValidationErrors(['marca']);
        $this->enviar($this->cuerpo(['marca' => '123.'.str_repeat('0', 64)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['marca']);
        $this->enviar($this->cuerpo(['marca' => AntispamReclamaciones::emitirMarca(time() - 7300)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['marca']);
        $this->enviar($this->cuerpo(['sitio_web' => 'https://spam.test']))
            ->assertUnprocessable()->assertJsonValidationErrors(['sitio_web']);

        $this->assertSame(0, Reclamacion::query()->count());
    }

    public function test_el_limite_de_envios_responde_429(): void
    {
        $this->habilitarReclamaciones();
        $limite = (int) config('reclamaciones.antispam.limite_ip_10min', 3);

        for ($i = 0; $i < $limite; $i++) {
            $this->enviar($this->cuerpo(['email' => "persona{$i}@example.test"]))->assertCreated();
        }
        $this->enviar($this->cuerpo(['email' => 'otra@example.test']))->assertTooManyRequests();

        $this->assertSame($limite, Reclamacion::query()->count());
    }

    public function test_los_intentos_invalidos_tambien_consumen_el_limite(): void
    {
        $this->habilitarReclamaciones();
        $limite = (int) config('reclamaciones.antispam.limite_ip_10min', 3);

        for ($i = 0; $i < $limite; $i++) {
            $this->enviar(['tipo' => 'reclamo'])->assertUnprocessable();
        }
        $this->enviar($this->cuerpo())->assertTooManyRequests();

        $this->assertSame(0, Reclamacion::query()->count());
    }

    // --- E2: formulario público conectado ------------------------------------------------------------------

    // Opción A: apagado, el formulario completo se muestra y solo el envío queda bloqueado.
    public function test_apagado_muestra_el_formulario_completo_con_el_envio_deshabilitado(): void
    {
        $antes = $this->conteos();
        $html = (string) $this->get(self::RUTA)->assertOk()->getContent();

        foreach (['id="btn-reclamo"', 'id="btn-queja"', 'id="libroForm"', 'data-habilitado="0"', 'id="paso-1"', 'id="paso-2"', 'id="paso-3"',
            'name="nombres"', 'name="apellidos"', 'name="tipo_documento"', 'name="numero_documento"', 'name="email"', 'name="telefono"',
            'name="domicilio"', 'name="representante_nombre"', 'name="bien_tipo"', 'name="monto_bien"', 'name="monto_no_aplica"',
            'name="bien_descripcion"', 'name="detalle"', 'name="pedido"', 'id="acepta-terminos"'] as $presente) {
            $this->assertStringContainsString($presente, $html);
        }

        $this->assertStringContainsString('El formulario está disponible para su consulta, pero el registro en línea todavía no se encuentra habilitado. Próximamente podrás enviar tu queja o reclamo a través de esta plataforma.', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" class="btn-enviar" id="btn-enviar" disabled[^>]*>\s*<i[^>]*><\/i>\s*<span>Envío temporalmente deshabilitado<\/span>/', $html);

        // Nada que firmar ni confirmación posible: sin marca, sin token y sin contenedor de éxito.
        foreach (['name="marca"', 'name="token_envio"', 'id="exito-container"', 'id="numero-reclamo"', 'registro-no-disponible', 'Registro en línea no disponible'] as $ausente) {
            $this->assertStringNotContainsString($ausente, $html);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_apagado_la_pagina_y_el_post_directo_sin_marca_siguen_en_404(): void
    {
        $html = (string) $this->get(self::RUTA)->assertOk()->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $html, $csrf);
        $antes = $this->conteos();

        // Mismo cuerpo que enviaría un formulario reactivado a mano: el servidor lo rechaza igual.
        $this->post(self::RUTA, $this->cuerpo(['_token' => $csrf[1] ?? '']), ['Accept' => 'application/json'])->assertNotFound();
        $this->post(self::RUTA, $this->cuerpo())->assertNotFound();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_encendido_el_formulario_apunta_al_endpoint_con_csrf_marca_y_token(): void
    {
        $this->habilitarReclamaciones();
        $respuesta = $this->get(self::RUTA)->assertOk();
        $html = (string) $respuesta->getContent();

        $this->assertStringContainsString('action="'.route('legales.reclamaciones.store').'" method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="marca" value="'.$respuesta->viewData('marca').'"', $html);
        $this->assertStringContainsString('name="token_envio" value="'.$respuesta->viewData('tokenEnvio').'"', $html);
        $this->assertStringNotContainsString('registro-no-disponible', $html);
        // Encendido, el mismo formulario envía de verdad: sin aviso de consulta y con el botón activo.
        $this->assertStringContainsString('data-habilitado="1"', $html);
        $this->assertStringContainsString('<button type="submit" class="btn-enviar" id="btn-enviar">', $html);
        $this->assertStringContainsString('id="exito-container"', $html);
        foreach (['aviso-envio-deshabilitado', 'Envío temporalmente deshabilitado', 'id="modo-consulta"'] as $ausente) {
            $this->assertStringNotContainsString($ausente, $html);
        }
    }

    public function test_cada_campo_del_formulario_existe_en_la_validacion_del_backend(): void
    {
        $this->habilitarReclamaciones();
        $html = (string) $this->get(self::RUTA)->assertOk()->getContent();
        preg_match('/<form id="libroForm".*?<\/form>/s', $html, $form);
        preg_match_all('/\sname="([^"]+)"/', $form[0], $nombres);
        $enviados = array_values(array_unique(array_diff($nombres[1], ['_token'])));

        $reglas = array_keys((new StoreReclamacionRequest)->rules());
        $this->assertSame([], array_values(array_diff($enviados, $reglas)), 'Campos del formulario que el backend no conoce.');

        $requeridos = ['marca', 'token_envio', 'tipo', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'domicilio',
            'telefono', 'email', 'es_menor', 'representante_nombre', 'representante_domicilio', 'representante_telefono',
            'representante_email', 'bien_tipo', 'bien_descripcion', 'monto_bien', 'monto_no_aplica', 'detalle', 'pedido', 'sitio_web'];
        $this->assertSame([], array_values(array_diff($requeridos, $enviados)), 'Campos del backend que faltan en el formulario.');
        // Campos que el backend no guarda o que el servidor asigna: no deben estar en el formulario.
        foreach (['area', 'fecha-incidente', 'tipo-registro', 'acepta-comunicaciones', 'estado', 'numero', 'canal'] as $ausente) {
            $this->assertNotContains($ausente, $enviados);
        }
    }

    public function test_la_confirmacion_solo_menciona_la_copia_por_correo_si_el_envio_esta_habilitado(): void
    {
        $this->habilitarReclamaciones();
        $sinCorreo = (string) $this->get(self::RUTA)->assertOk()->getContent();
        $this->assertStringNotContainsString('id="exito-correo"', $sinCorreo);
        $this->assertStringNotContainsString('Te hemos enviado un correo', $sinCorreo);

        $this->habilitarReclamaciones(envio: true);
        $conCorreo = (string) $this->get(self::RUTA)->assertOk()->getContent();
        $this->assertStringContainsString('id="exito-correo"', $conCorreo);
        $this->assertStringContainsString('Enviaremos una copia de tu registro al correo electrónico que indicaste.', $conCorreo);
    }

    public function test_no_quedan_rastros_de_la_simulacion_ni_de_endpoints_externos(): void
    {
        $fuentes = [
            base_path('public/assets/js/libro-reclamaciones.js'),
            base_path('resources/views/pages/legales/libro-reclamaciones.blade.php'),
            base_path('public/assets/css/libro/libro-reclamaciones.css'),
        ];
        foreach ($fuentes as $archivo) {
            $contenido = (string) file_get_contents($archivo);
            foreach (['SIM-', 'localhost:3000', 'numeroSimulado', 'data-endpoint', 'exito-simulado', 'simulado'] as $rastro) {
                $this->assertStringNotContainsString($rastro, $contenido, basename($archivo).' contiene '.$rastro);
            }
        }
    }

    public function test_get_no_registra_nada_y_no_requiere_sesion(): void
    {
        $this->habilitarReclamaciones();
        $antes = $this->conteos();

        $this->get(self::RUTA)->assertOk();

        $this->assertSame($antes, $this->conteos());
        $this->assertGuest();
    }
}
