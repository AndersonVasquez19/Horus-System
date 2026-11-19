<?php

declare(strict_types=1);

use App\Exceptions\ConsultaDniException;
use App\Models\Estudiante;
use App\Models\User;
use App\Services\JsonPeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Consulta de DNI con JSON.pe. SIEMPRE con Http::fake: nunca se llama al proveedor real ni se usa el token real. El contrato
 * simulado es el documentado por el propietario (POST + Bearer; `success` y `data.numero|nombres|apellido_paterno|…`).
 * SQLite en memoria; la caché de pruebas es `array`.
 */

const TOKEN_FALSO = 'token-falso-solo-para-pruebas-0123456789';

function respuestaExitosa(string $dni = '27427864', array $cambios = []): array
{
    return ['success' => true, 'data' => [
        'numero' => $dni, 'nombres' => 'ANA MARIA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'SOTO',
        'codigo_verificacion' => 7, ...$cambios,
    ]];
}

beforeEach(function (): void {
    config(['services.jsonpe.token' => TOKEN_FALSO, 'services.jsonpe.url' => 'https://api.json.pe/api/dni']);
    Cache::flush();
    Http::preventStrayRequests();
});

it('consulta el DNI con token Bearer del servidor y devuelve solo los campos necesarios', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(respuestaExitosa())]);

    $respuesta = $this->panelComo($this->usuarioConRol('secretaria'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertOk()
        ->assertJsonPath('data.dni', '27427864')
        ->assertJsonPath('data.nombres', 'ANA MARIA')
        ->assertJsonPath('data.apellido_paterno', 'QUISPE')
        ->assertJsonPath('data.apellido_materno', 'SOTO')
        ->assertJsonPath('data.apellidos', 'QUISPE SOTO')
        ->assertJsonPath('data.ya_registrado_como_estudiante', false);

    expect($respuesta->json('data'))->not->toHaveKey('codigo_verificacion');
    expect((string) $respuesta->headers->get('Cache-Control'))->toContain('no-store');
    Http::assertSent(fn (ClientRequest $r): bool => $r->url() === 'https://api.json.pe/api/dni'
        && $r->method() === 'POST'
        && $r->hasHeader('Authorization', 'Bearer '.TOKEN_FALSO)
        && $r['dni'] === '27427864');
    Http::assertSentCount(1);
});

it('nunca expone el token ni la URL del proveedor en la respuesta', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(respuestaExitosa())]);

    $texto = (string) $this->panelComo($this->usuarioConRol('secretaria'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->getContent();

    expect($texto)->not->toContain(TOKEN_FALSO)->not->toContain('json.pe');
});

it('avisa si el DNI ya está registrado como estudiante (incluso eliminado) solo a quien puede ver estudiantes', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(respuestaExitosa('27427864'))]);
    Estudiante::create(['dni' => '27427864', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo'])->delete();

    $this->panelComo($this->usuarioConRol('secretaria'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertOk()->assertJsonPath('data.ya_registrado_como_estudiante', true);
});

it('rechaza un DNI que no tiene exactamente 8 dígitos sin llamar al proveedor', function (mixed $dni): void {
    Http::fake();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => $dni])
        ->assertStatus(422)->assertJsonValidationErrors('dni');

    Http::assertNothingSent();
})->with(['corto' => ['1234567'], 'largo' => ['123456789'], 'letras' => ['1234567A'], 'con espacios' => [' 2742786'], 'vacío' => [''], 'nulo' => [null], 'arreglo' => [['27427864']], 'numérico largo' => ['2742786400']]);

it('exige permiso y sesión', function (): void {
    Http::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->panelComo(null, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertUnauthorized();
    $this->panelComo(User::factory()->create(), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertForbidden();
    Http::assertNothingSent();
});

it('trata success=false como DNI no encontrado sin reenviar el mensaje del proveedor', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(['success' => false, 'message' => 'texto interno del proveedor'])]);

    $r = $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertNotFound()->assertJsonPath('code', ConsultaDniException::NO_ENCONTRADO);

    expect((string) $r->getContent())->not->toContain('texto interno del proveedor');
});

it('mapea los errores del proveedor a respuestas controladas', function (int $estadoProveedor, int $estadoEsperado, string $codigo): void {
    Http::fake(['api.json.pe/*' => Http::response(['message' => 'detalle interno'], $estadoProveedor)]);

    $r = $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertStatus($estadoEsperado)->assertJsonPath('code', $codigo);

    expect((string) $r->getContent())->not->toContain('detalle interno')->not->toContain(TOKEN_FALSO);
})->with([
    'token inválido 401' => [401, 503, ConsultaDniException::NO_AUTORIZADO],
    'prohibido 403' => [403, 503, ConsultaDniException::NO_AUTORIZADO],
    'no encontrado 404' => [404, 404, ConsultaDniException::NO_ENCONTRADO],
    'límite 429' => [429, 429, ConsultaDniException::LIMITE],
    'error del proveedor 500' => [500, 503, ConsultaDniException::NO_DISPONIBLE],
    'puerta de enlace 502' => [502, 503, ConsultaDniException::NO_DISPONIBLE],
]);

it('responde con un error controlado cuando el proveedor no responde o agota el tiempo', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout con token '.TOKEN_FALSO));
    Log::spy();

    $r = $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertStatus(503)->assertJsonPath('code', ConsultaDniException::NO_DISPONIBLE);

    expect((string) $r->getContent())->not->toContain(TOKEN_FALSO)->not->toContain('cURL');
    Log::shouldHaveReceived('warning')->with('jsonpe.sin_conexion')->once();
});

it('rechaza respuestas incompletas o de otro DNI en lugar de inventar datos', function (array $cuerpo): void {
    Http::fake(['api.json.pe/*' => Http::response($cuerpo)]);

    $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertStatus(502)->assertJsonPath('code', ConsultaDniException::RESPUESTA_INCOMPLETA);
})->with([
    'sin nombres' => [respuestaExitosa('27427864', ['nombres' => ''])],
    'sin apellido paterno' => [respuestaExitosa('27427864', ['apellido_paterno' => null])],
    'otro DNI' => [respuestaExitosa('11111111')],
    'sin número' => [respuestaExitosa('27427864', ['numero' => null])],
    'sin success' => [['data' => ['numero' => '27427864']]],
    'no es JSON' => [[]],
]);

it('acepta un segundo apellido vacío', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(respuestaExitosa('27427864', ['apellido_materno' => '']))]);

    $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertOk()->assertJsonPath('data.apellidos', 'QUISPE');
});

it('no se puede consultar sin token configurado ni con una URL que no sea HTTPS', function (?string $token, string $url): void {
    config(['services.jsonpe.token' => $token, 'services.jsonpe.url' => $url]);
    Http::fake();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertStatus(503)->assertJsonPath('code', ConsultaDniException::NO_CONFIGURADO);

    Http::assertNothingSent();
})->with([
    'sin token' => [null, 'https://api.json.pe/api/dni'],
    'token vacío' => ['  ', 'https://api.json.pe/api/dni'],
    'URL http' => [TOKEN_FALSO, 'http://api.json.pe/api/dni'],
]);

it('guarda la consulta correcta 10 minutos y no repite la llamada al proveedor', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(respuestaExitosa())]);
    $usuario = $this->usuarioConRol('admin');

    $this->panelComo($usuario, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertOk();
    $this->panelComo($usuario, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertOk();

    Http::assertSentCount(1);
});

it('no guarda en caché las consultas fallidas', function (): void {
    Http::fake(['api.json.pe/*' => Http::sequence()->push([], 500)->push(respuestaExitosa())]);
    $usuario = $this->usuarioConRol('admin');

    $this->panelComo($usuario, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertStatus(503);
    $this->panelComo($usuario, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertOk();

    Http::assertSentCount(2);
});

it('limita las consultas a 10 por minuto y usuario', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(respuestaExitosa())]);
    $usuario = $this->usuarioConRol('admin');

    foreach (range(1, 10) as $i) {
        $this->panelComo($usuario, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertOk();
    }
    $this->panelComo($usuario, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])
        ->assertStatus(429)->assertJsonPath('code', 'demasiadas_solicitudes');
});

it('el servicio rechaza un DNI mal formado sin llamar al proveedor', function (): void {
    Http::fake();

    expect(fn () => app(JsonPeService::class)->consultarDni('123'))->toThrow(ConsultaDniException::class);
    Http::assertNothingSent();
});

it('no escribe ningún dato: consultar no registra al estudiante', function (): void {
    Http::fake(['api.json.pe/*' => Http::response(respuestaExitosa())]);

    $this->panelComo($this->usuarioConRol('admin'), 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertOk();

    expect(Estudiante::withTrashed()->count())->toBe(0);
});
