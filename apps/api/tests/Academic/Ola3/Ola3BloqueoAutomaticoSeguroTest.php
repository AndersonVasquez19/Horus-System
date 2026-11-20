<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Support\SeguridadCuentas;
use Illuminate\Support\Facades\Cache;

/** Simula que la petición actual llega desde otra IP (el contador del bloqueo automático es por cuenta + IP). */
function desdeIp(string $ip): void
{
    request()->server->set('REMOTE_ADDR', $ip);
}

beforeEach(function (): void {
    Cache::flush();
    config([
        'seguridad.bloqueo_intentos' => 3,
        'seguridad.bloqueo_minutos' => 15,
        'seguridad.bloqueo_auto_enabled' => true,
        'seguridad.alertas_login_enabled' => false,
    ]);
});

test('una cuenta administradora no se bloquea automáticamente por muchos intentos fallidos', function (): void {
    $admin = $this->usuarioConRol('admin', 'admin-dos@example.test');

    foreach (range(1, 12) as $_) {
        SeguridadCuentas::registrarFalloParaCuentaExistente('admin-dos@example.test');
    }

    expect($admin->fresh()->bloqueado_hasta)->toBeNull()
        ->and(AuditLog::where('action', 'user_locked_auto')->count())->toBe(0);
});

test('los fallos desde otra IP no bloquean a la persona real', function (): void {
    $coord = $this->usuarioConRol('coordinador', 'coord-ip@example.test');

    desdeIp('203.0.113.10');
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ip@example.test');
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ip@example.test');
    desdeIp('203.0.113.20');
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ip@example.test');
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ip@example.test');

    expect($coord->fresh()->bloqueado_hasta)->toBeNull('cuatro fallos repartidos entre dos IP no alcanzan el umbral en ninguna');

    // Un tercer fallo desde la primera IP sí alcanza el umbral allí.
    desdeIp('203.0.113.10');
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ip@example.test');
    expect($coord->fresh()->bloqueado_hasta)->not->toBeNull();
});

test('al desbloquear o expirar el bloqueo no se vuelve a bloquear con el siguiente fallo', function (): void {
    $coord = $this->usuarioConRol('coordinador', 'coord-relock@example.test');
    desdeIp('203.0.113.30');

    foreach (range(1, 3) as $_) {
        SeguridadCuentas::registrarFalloParaCuentaExistente('coord-relock@example.test');
    }
    expect($coord->fresh()->bloqueado_hasta)->not->toBeNull();

    // La persona real fue desbloqueada y sigue fallando una vez más: el contador ya pasó del umbral, no hay re-bloqueo inmediato.
    $coord->forceFill(['bloqueado_hasta' => null, 'motivo_bloqueo' => null])->save();
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-relock@example.test');

    expect($coord->fresh()->bloqueado_hasta)->toBeNull()
        ->and(AuditLog::where('action', 'user_locked_auto')->count())->toBe(1);
});

test('la ventana es fija: la ráfaga no renueva el contador y un acceso correcto lo reinicia', function (): void {
    $coord = $this->usuarioConRol('coordinador', 'coord-ventana@example.test');
    desdeIp('203.0.113.40');

    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ventana@example.test');
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ventana@example.test');
    SeguridadCuentas::limpiarIntentos($coord);
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ventana@example.test');
    SeguridadCuentas::registrarFalloParaCuentaExistente('coord-ventana@example.test');

    expect($coord->fresh()->bloqueado_hasta)->toBeNull('el acceso correcto reinició el conteo');
});

test('horus:desbloquear-usuario quita el bloqueo, lo registra y rechaza correos desconocidos', function (): void {
    $coord = $this->usuarioConRol('coordinador', 'coord-cli@example.test');
    $coord->forceFill(['bloqueado_hasta' => now()->addHour(), 'motivo_bloqueo' => 'Prueba'])->save();

    $this->artisan('horus:desbloquear-usuario', ['email' => 'nadie@example.test'])->assertFailed();
    $this->artisan('horus:desbloquear-usuario', ['email' => 'coord-cli@example.test'])->assertSuccessful();

    expect($coord->fresh()->bloqueado_hasta)->toBeNull()
        ->and(AuditLog::where('action', 'user_unlocked')->count())->toBe(1);
});

test('horus:reiniciar-2fa desactiva el segundo factor, revoca JWT y lo audita', function (): void {
    $coord = $this->usuarioConRol('coordinador', 'coord-2fa@example.test');
    $coord->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => 123])->save();
    $version = (int) $coord->jwt_version;

    $this->artisan('horus:reiniciar-2fa', ['email' => 'coord-2fa@example.test'])->assertSuccessful();

    $coord->refresh();
    expect($coord->tiene2faActivo())->toBeFalse()
        ->and($coord->two_factor_last_step)->toBeNull()
        ->and((int) $coord->jwt_version)->toBe($version + 1)
        ->and(AuditLog::where('action', 'two_factor_reset_console')->count())->toBe(1);
});
