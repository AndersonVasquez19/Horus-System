<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\GestionarReclamacionRequest;
use App\Http\Requests\StoreReclamacionRequest;
use App\Support\AntispamReclamaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

class ReclamacionRequestsTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararReclamaciones();
    }

    /** @return array<string, mixed> */
    private function cuerpo(array $extra = []): array
    {
        $datos = $this->datosReclamacion() + [];
        unset($datos['monto_bien']);

        return $extra + $datos + [
            'monto_bien' => '150.00', 'marca' => AntispamReclamaciones::emitirMarca(time() - 30), 'token_envio' => (string) Str::uuid(),
        ];
    }

    private function validar(array $cuerpo): \Illuminate\Contracts\Validation\Validator
    {
        $peticion = StoreReclamacionRequest::create('/x', 'POST', $cuerpo);
        $peticion->setContainer(app())->setRedirector(app('redirect'));
        $reflexion = new \ReflectionMethod($peticion, 'prepareForValidation');
        $reflexion->invoke($peticion);
        $validador = Validator::make($peticion->all(), $peticion->rules(), $peticion->messages());
        $peticion->withValidator($validador);

        return $validador;
    }

    public function test_una_peticion_valida_pasa(): void
    {
        $this->assertTrue($this->validar($this->cuerpo())->passes(), json_encode($this->validar($this->cuerpo())->errors()->all()));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function casosInvalidos(): array
    {
        return [
            'sin nombres' => [['nombres' => ''], 'nombres'],
            'sin apellidos' => [['apellidos' => ''], 'apellidos'],
            'sin documento' => [['numero_documento' => ''], 'numero_documento'],
            'dni corto' => [['numero_documento' => '1234'], 'numero_documento'],
            'ruc de 8' => [['tipo_documento' => 'ruc', 'numero_documento' => '12345678'], 'numero_documento'],
            'tipo documento ajeno' => [['tipo_documento' => 'libreta'], 'tipo_documento'],
            'sin domicilio' => [['domicilio' => ''], 'domicilio'],
            'sin correo' => [['email' => ''], 'email'],
            'correo invalido' => [['email' => 'no-es-correo'], 'email'],
            'correo array' => [['email' => ['a@b.test']], 'email'],
            'sin telefono' => [['telefono' => ''], 'telefono'],
            'telefono con letras' => [['telefono' => 'abc12345'], 'telefono'],
            'sin detalle' => [['detalle' => ''], 'detalle'],
            'detalle demasiado largo' => [['detalle' => str_repeat('a', 3001)], 'detalle'],
            'pedido demasiado largo' => [['pedido' => str_repeat('a', 1501)], 'pedido'],
            'sin pedido' => [['pedido' => ''], 'pedido'],
            'html en el detalle' => [['detalle' => 'Texto <script>alert(1)</script> largo'], 'detalle'],
            'tipo ajeno' => [['tipo' => 'sugerencia'], 'tipo'],
            'reclamo sin bien' => [['bien_tipo' => null, 'bien_descripcion' => null], 'bien_tipo'],
            'bien ajeno' => [['bien_tipo' => 'inmueble'], 'bien_tipo'],
            'reclamo sin monto ni no_aplica' => [['monto_bien' => ''], 'monto_bien'],
            'monto negativo' => [['monto_bien' => '-5'], 'monto_bien'],
            'monto con tres decimales' => [['monto_bien' => '10.123'], 'monto_bien'],
            'monto no numerico' => [['monto_bien' => 'mucho'], 'monto_bien'],
            'menor sin representante' => [['es_menor' => true], 'representante_nombre'],
            'canal de respuesta ajeno' => [['canal_respuesta' => 'paloma'], 'canal_respuesta'],
            'sin token de envio' => [['token_envio' => ''], 'token_envio'],
            'token de envio no uuid' => [['token_envio' => 'abc'], 'token_envio'],
            'honeypot relleno' => [['sitio_web' => 'http://spam.test'], 'sitio_web'],
            'marca ausente' => [['marca' => ''], 'marca'],
            'marca falsificada' => [['marca' => (time() - 30).'.'.str_repeat('a', 64)], 'marca'],
            'marca demasiado reciente' => [['marca' => 'AHORA'], 'marca'],
            'marca caducada' => [['marca' => 'VIEJA'], 'marca'],
            'estado impuesto' => [['estado' => 'respondido'], 'estado'],
            'numero impuesto' => [['numero' => '000000001-2026'], 'numero'],
            'fecha limite impuesta' => [['fecha_limite' => '2030-01-01'], 'fecha_limite'],
            'responsable impuesto' => [['responsable_id' => 1], 'responsable_id'],
            'canal impuesto' => [['canal' => 'respaldo'], 'canal'],
        ];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('casosInvalidos')]
    public function test_rechaza_peticiones_incompletas_o_manipuladas(array $extra, string $campo): void
    {
        if (($extra['marca'] ?? null) === 'AHORA') {
            $extra['marca'] = AntispamReclamaciones::emitirMarca(time());
        }
        if (($extra['marca'] ?? null) === 'VIEJA') {
            $extra['marca'] = AntispamReclamaciones::emitirMarca(time() - 8000);
        }
        $validador = $this->validar($this->cuerpo($extra));
        $this->assertTrue($validador->fails());
        $this->assertTrue($validador->errors()->has($campo), $campo.' => '.json_encode($validador->errors()->keys()));
    }

    public function test_queja_sin_bien_y_reclamo_con_no_aplica_son_validos_y_dan_monto_nulo(): void
    {
        $queja = $this->cuerpo(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => '']);
        $this->assertTrue($this->validar($queja)->passes());
        $noAplica = $this->cuerpo(['monto_bien' => '', 'monto_no_aplica' => true]);
        $this->assertTrue($this->validar($noAplica)->passes());
    }

    public function test_menor_con_representante_completo_es_valido(): void
    {
        $this->assertTrue($this->validar($this->cuerpo(['es_menor' => true, 'representante_nombre' => 'Tutor Ficticio', 'representante_domicilio' => 'Calle 2',
            'representante_telefono' => '900111222', 'representante_email' => 'tutor@example.test']))->passes());
    }

    public function test_marca_firmada_solo_vale_entre_el_minimo_y_el_maximo(): void
    {
        $ahora = 1_800_000_000;
        $this->assertFalse(AntispamReclamaciones::marcaValida(AntispamReclamaciones::emitirMarca($ahora), $ahora + 2));
        $this->assertTrue(AntispamReclamaciones::marcaValida(AntispamReclamaciones::emitirMarca($ahora), $ahora + 6));
        $this->assertFalse(AntispamReclamaciones::marcaValida(AntispamReclamaciones::emitirMarca($ahora), $ahora + 7201));
        $this->assertFalse(AntispamReclamaciones::marcaValida(AntispamReclamaciones::emitirMarca($ahora + 1000), $ahora), 'Marca del futuro');
        foreach ([null, 123, '', 'x.y', ($ahora).'.'.str_repeat('0', 64), strtoupper(AntispamReclamaciones::emitirMarca($ahora))] as $mala) {
            $this->assertFalse(AntispamReclamaciones::marcaValida($mala, $ahora + 10));
        }
    }

    public function test_datos_listos_para_el_servicio_excluyen_marca_token_honeypot_y_no_aplica(): void
    {
        $peticion = StoreReclamacionRequest::create('/x', 'POST', $this->cuerpo(['monto_no_aplica' => true, 'monto_bien' => '99']));
        $peticion->setContainer(app())->setRedirector(app('redirect'));
        $peticion->setValidator($this->validar($this->cuerpo(['monto_no_aplica' => true, 'monto_bien' => '99'])));
        $datos = $peticion->datosReclamacion();
        foreach (['marca', 'token_envio', 'monto_no_aplica', 'sitio_web'] as $excluido) {
            $this->assertArrayNotHasKey($excluido, $datos);
        }
        $this->assertNull($datos['monto_bien'], '«No aplica» guarda NULL, nunca un importe');
        $this->assertSame('persona.ficticia@example.test', $datos['email']);
    }

    public function test_booleanos_del_formulario_llegan_al_servicio_como_bool_real(): void
    {
        $datosDe = function (array $extra): array {
            $cuerpo = $this->cuerpo($extra);
            $peticion = StoreReclamacionRequest::create('/x', 'POST', $cuerpo);
            $peticion->setContainer(app())->setRedirector(app('redirect'));
            $validador = $this->validar($cuerpo);
            $this->assertTrue($validador->passes(), json_encode($validador->errors()->all()));
            $peticion->setValidator($validador);

            return $peticion->datosReclamacion();
        };
        $representante = ['representante_nombre' => 'Tutor Ficticio', 'representante_domicilio' => 'Calle 2',
            'representante_telefono' => '900111222', 'representante_email' => 'tutor@example.test'];

        $this->assertFalse($datosDe(['es_menor' => '0'])['es_menor']);
        $this->assertFalse($datosDe([])['es_menor'], 'Ausente = false');
        $this->assertTrue($datosDe(['es_menor' => '1'] + $representante)['es_menor']);
        $this->assertSame('99', (string) $datosDe(['monto_no_aplica' => '0', 'monto_bien' => '99'])['monto_bien'], '«0» no activa «no aplica»');
        $this->assertNull($datosDe(['monto_no_aplica' => '1', 'monto_bien' => '99'])['monto_bien']);
    }

    public function test_booleanos_textuales_ambiguos_se_rechazan_en_la_validacion(): void
    {
        foreach (['es_menor', 'monto_no_aplica'] as $campo) {
            foreach (['quizá', 'si', 'false', '2'] as $valor) {
                $validador = $this->validar($this->cuerpo([$campo => $valor]));
                $this->assertTrue($validador->errors()->has($campo), "$campo=$valor debió rechazarse");
            }
        }
    }

    public function test_gestion_selecciona_reglas_y_permiso_por_operacion(): void
    {
        $gestor = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver', 'reclamaciones.gestionar']);
        $peticion = fn (array $datos, $usuario) => tap(GestionarReclamacionRequest::create('/x', 'POST', $datos), fn ($p) => $p->setUserResolver(fn () => $usuario));

        $this->assertTrue($peticion(['operacion' => 'nota'], $gestor)->authorize());
        $this->assertFalse($peticion(['operacion' => 'respuesta'], $gestor)->authorize(), 'Responder exige reclamaciones.responder');
        $this->assertFalse($peticion(['operacion' => 'aceptacion'], $gestor)->authorize());
        $this->assertFalse($peticion(['operacion' => 'editar'], $this->admin)->authorize(), 'No existe operación de edición');
        $this->assertFalse($peticion(['operacion' => 'eliminar'], $this->admin)->authorize(), 'No existe operación de eliminación');
        $this->assertTrue($peticion(['operacion' => 'respuesta'], $this->admin)->authorize());

        $reglas = fn (array $datos) => Validator::make($datos, $peticion($datos, $this->admin)->rules());
        $this->assertTrue($reglas(['operacion' => 'respuesta', 'respuesta' => 'T', 'canal' => 'correo'])->passes());
        $this->assertTrue($reglas(['operacion' => 'respuesta', 'respuesta' => 'T', 'canal' => 'carta'])->fails(), 'La carta exige constancia');
        $this->assertTrue($reglas(['operacion' => 'rechazo', 'fecha_recepcion' => '26/10/2026'])->fails());
        $this->assertTrue($reglas(['operacion' => 'aceptacion', 'fecha_recepcion' => '2026-10-26', 'medio' => 'fax', 'constancia' => 'c'])->fails());
        $this->assertTrue($reglas(['operacion' => 'nota', 'nota' => str_repeat('x', 2001)])->fails());
    }

    public function test_limitador_reclamaciones_por_ip_y_por_correo_sin_datos_personales_en_la_clave(): void
    {
        $limitador = RateLimiter::limiter('reclamaciones');
        $this->assertNotNull($limitador);
        $peticion = Request::create('/x', 'POST', ['email' => ' Persona.Ficticia@Example.Test ']);
        $peticion->server->set('REMOTE_ADDR', '203.0.113.9');
        $limites = $limitador($peticion);
        $this->assertCount(3, $limites);
        $this->assertSame([3, 10, 3], array_map(fn ($l) => $l->maxAttempts, $limites));
        $claves = array_map(fn ($l) => $l->key, $limites);
        $this->assertSame(3, count(array_unique($claves)));
        $this->assertStringNotContainsString('persona', strtolower(implode('|', $claves)));
        $otra = Request::create('/x', 'POST', ['email' => 'persona.ficticia@example.test']);
        $this->assertSame($claves[2], $limitador($otra)[2]->key, 'El correo se normaliza antes de limitar');
    }
}
