<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\AvisoExterno;
use App\Models\ComprobantePago;
use App\Models\CuotaObligacion;
use App\Models\MatriculaNotification;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Plan de mejora del panel, Fase 1 (endurecimiento). SQLite en memoria; sin HTTP real.
 */

it('los modelos de finanzas y avisos usan una lista cerrada de asignación en masa', function (string $modelo): void {
    $instancia = new $modelo;

    expect($instancia->getFillable())->not->toBeEmpty()->not->toContain('id')
        ->and($instancia->getGuarded())->toBe(['*']);
})->with([
    'obligación' => ObligacionPago::class,
    'transacción' => TransaccionPago::class,
    'cuota' => CuotaObligacion::class,
    'comprobante' => ComprobantePago::class,
    'método' => MetodoPago::class,
    'aviso externo' => AvisoExterno::class,
    'notificación de matrícula' => MatriculaNotification::class,
]);

it('una obligación no admite asignar el id ni columnas desconocidas en masa', function (): void {
    $obligacion = new ObligacionPago(['id' => 999, 'columna_inventada' => 'x', 'concepto' => 'Matrícula']);

    expect($obligacion->getAttributes())->toBe(['concepto' => 'Matrícula']);
});

it('las rutas sensibles usan limitadores con nombre y no contadores numéricos compartidos', function (): void {
    $mw = fn (string $uri): array => collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => str_ends_with($r->uri(), $uri))
        ?->gatherMiddleware() ?? [];

    expect($mw('academico/consultas/dni'))->toContain('throttle:consulta-dni')
        ->and($mw('academico/pagos/transacciones/{transaccion}/comprobante'))->toContain('throttle:comprobante-ver')
        ->and($mw('academico/pagos/transacciones/{transaccion}/confirmar'))->toContain('throttle:pagos-resolver')
        ->and($mw('academico/pagos/transacciones/{transaccion}/reversar'))->toContain('throttle:pagos-resolver')
        ->and($mw('academico/pagos/obligaciones/{obligacion}/anular'))->toContain('throttle:pagos-resolver');
});

it('agotar el cupo de consulta de DNI no bloquea la lectura de comprobantes', function (): void {
    config(['services.jsonpe.token' => 'token-falso-0123456789', 'services.jsonpe.url' => 'https://api.json.pe/api/dni']);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['api.json.pe/*' => Http::response(['success' => true, 'data' => [
        'numero' => '27427864', 'nombres' => 'ANA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'SOTO',
    ]])]);
    $admin = $this->usuarioConRol('admin');

    for ($i = 0; $i < 10; $i++) {
        $this->panelComo($admin, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertOk();
    }
    $this->panelComo($admin, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertStatus(429);

    // Cupo propio: ver un comprobante (aquí inexistente → 404) no comparte el contador agotado de DNI.
    $this->panelComo($admin, 'GET', 'academico/pagos/transacciones/'.Str::uuid().'/comprobante')->assertNotFound();
});

describe('consulta de DNI', function (): void {
    beforeEach(function (): void {
        config(['services.jsonpe.token' => 'token-falso-0123456789', 'services.jsonpe.url' => 'https://api.json.pe/api/dni']);
        Cache::flush();
        Http::preventStrayRequests();
    });

    it('audita quién consultó y el resultado, nunca el DNI ni los datos de la persona', function (): void {
        Http::fake(['api.json.pe/*' => Http::response(['success' => true, 'data' => [
            'numero' => '27427864', 'nombres' => 'ANA MARIA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'SOTO',
        ]])]);
        $secretaria = $this->usuarioConRol('secretaria');

        $this->panelComo($secretaria, 'POST', 'academico/consultas/dni', ['dni' => '27427864'])->assertOk();

        $registro = AuditLog::query()->where('action', 'consulta_dni')->firstOrFail();
        expect($registro->user_id)->toBe($secretaria->id)
            ->and($registro->changes)->toBe(['resultado' => 'ok'])
            ->and(json_encode($registro->changes))->not->toContain('27427864')->not->toContain('QUISPE');
    });

    it('también audita la consulta fallida con su código', function (): void {
        config(['services.jsonpe.token' => '']);
        Http::fake();

        $this->panelComo($this->usuarioConRol('secretaria'), 'POST', 'academico/consultas/dni', ['dni' => '27427864']);

        $registro = AuditLog::query()->where('action', 'consulta_dni')->firstOrFail();
        expect($registro->changes['resultado'])->toBe('error')->and($registro->changes)->toHaveKey('codigo');
        Http::assertNothingSent();
    });
});

it('audita la generación del PDF de caja diaria sin guardar los movimientos', function (): void {
    $admin = $this->usuarioConRol('admin');

    $this->panelComo($admin, 'POST', 'academico/pagos/caja-diaria/pdf')->assertOk();

    $registro = AuditLog::query()->where('action', 'caja_diaria_pdf')->firstOrFail();
    expect($registro->user_id)->toBe($admin->id)
        ->and($registro->changes)->toHaveKeys(['fecha', 'cantidad'])
        ->and($registro->changes)->not->toHaveKey('movimientos');
});
