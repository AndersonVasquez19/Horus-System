<?php

declare(strict_types=1);

use App\Models\AvisoExterno;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function firmarResend(string $tipo, string $emailId, string $svixId, string $secretoCrudo): TestResponse|Illuminate\Testing\TestResponse
{
    $cuerpo = json_encode(['type' => $tipo, 'data' => ['email_id' => $emailId]], JSON_THROW_ON_ERROR);
    $ts = time();
    $firma = base64_encode(hash_hmac('sha256', "{$svixId}.{$ts}.{$cuerpo}", $secretoCrudo, true));

    return test()->call('POST', '/api/webhooks/resend', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => $svixId,
        'HTTP_SVIX_TIMESTAMP' => (string) $ts,
        'HTTP_SVIX_SIGNATURE' => "v1,{$firma}",
    ], $cuerpo);
}

test('webhook de Resend: un evento atrasado no degrada el estado y un reintento con el mismo svix-id se ignora', function (): void {
    Cache::flush();
    $crudo = 'secreto-webhook-orden-12345';
    config(['recordatorios.webhooks.resend_secret' => 'whsec_'.base64_encode($crudo)]);

    $aviso = AvisoExterno::create([
        'canal' => 'email', 'tipo' => 'recordatorio_cuota', 'clave_unica' => 'orden-1',
        'delivery_key' => (string) Str::uuid(), 'status' => 'accepted', 'provider_id' => 're_orden_1',
    ]);

    firmarResend('email.bounced', 're_orden_1', 'msg_a', $crudo)->assertOk()->assertJsonPath('actualizados', 1);
    expect($aviso->fresh()->provider_status)->toBe('bounced');

    // «delivered» llega tarde (fuera de orden): no pisa el rebote.
    firmarResend('email.delivered', 're_orden_1', 'msg_b', $crudo)->assertOk()->assertJsonPath('actualizados', 0);
    expect($aviso->fresh()->provider_status)->toBe('bounced')->and($aviso->fresh()->delivered_at)->toBeNull();

    // Mismo svix-id reenviado: duplicado, no se procesa.
    firmarResend('email.bounced', 're_orden_1', 'msg_a', $crudo)->assertOk()->assertJsonPath('duplicado', true);
});

test('el login JWT rechaza cuentas con 2FA activo o cambio de contraseña pendiente', function (): void {
    $con2fa = $this->usuarioConRol('coordinador', 'jwt-2fa@example.test');
    $con2fa->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
    $this->postJson('/api/auth/login', ['email' => 'jwt-2fa@example.test', 'password' => 'password'])
        ->assertForbidden()->assertJsonPath('code', '2fa_requerido');

    $cambio = $this->usuarioConRol('coordinador', 'jwt-clave@example.test');
    $cambio->forceFill(['debe_cambiar_password' => true])->save();
    $this->postJson('/api/auth/login', ['email' => 'jwt-clave@example.test', 'password' => 'password'])
        ->assertForbidden()->assertJsonPath('code', 'cambio_clave_requerido');

    $normal = $this->usuarioConRol('coordinador', 'jwt-normal@example.test');
    $this->postJson('/api/auth/login', ['email' => 'jwt-normal@example.test', 'password' => 'password'])
        ->assertOk()->assertJsonStructure(['access_token']);
    expect(User::where('email', 'jwt-normal@example.test')->exists())->toBeTrue();
});
