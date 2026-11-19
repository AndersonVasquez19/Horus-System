<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Support\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

test('TOTP RFC 6238 y flujo completo 2FA en perfil y login funcionan sin abrir sesión previa', function (): void {
    $admin = $this->usuarioConRol('admin', 'totp@example.test');

    // Iniciar 2FA requiere contraseña actual
    $this->panelComo($admin, 'POST', 'compartido/perfil/2fa/iniciar', ['current_password' => 'mala'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $inicio = $this->panelComo($admin, 'POST', 'compartido/perfil/2fa/iniciar', ['current_password' => 'password'])
        ->assertOk();
    $secreto = (string) $inicio->json('data.secreto');
    expect($secreto)->not->toBe('')
        ->and((string) $inicio->json('data.uri'))->toContain('otpauth://totp/');

    // Confirmar rechaza código inválido
    $this->panelComo($admin, 'POST', 'compartido/perfil/2fa/confirmar', ['codigo' => '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('codigo');

    // Confirmar con código válido activa 2FA y entrega 8 códigos de recuperación
    $codigoValido = Totp::codigoEn($secreto);
    $confirmacion = $this->panelComo($admin, 'POST', 'compartido/perfil/2fa/confirmar', ['codigo' => $codigoValido])
        ->assertOk();
    $codigosRecuperacion = $confirmacion->json('data.codigos_recuperacion');
    expect($codigosRecuperacion)->toHaveCount(8)
        ->and($admin->fresh()->tiene2faActivo())->toBeTrue()
        ->and(AuditLog::where('action', 'two_factor_enabled')->count())->toBe(1);

    // Login sin código 2FA -> 422 code=2fa_requerido sin autenticar
    Auth::logout();
    $this->assertGuest();
    $this->panelComo(null, 'POST', 'auth/login', ['email' => 'totp@example.test', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('code', '2fa_requerido')
        ->assertJsonValidationErrors('codigo_2fa');
    $this->assertGuest();

    // El código usado para confirmar no sirve otra vez (anti-reutilización): se rechaza aunque siga vigente.
    $this->panelComo(null, 'POST', 'auth/login', [
        'email' => 'totp@example.test',
        'password' => 'password',
        'codigo_2fa' => $codigoValido,
    ])->assertUnprocessable()->assertJsonPath('code', '2fa_requerido');
    $this->assertGuest();

    // Login con el código TOTP del paso siguiente -> 200; y ese tampoco se acepta una segunda vez.
    $siguiente = Totp::codigoEn($secreto, time() + 30);
    $this->panelComo(null, 'POST', 'auth/login', [
        'email' => 'totp@example.test',
        'password' => 'password',
        'codigo_2fa' => $siguiente,
    ])->assertOk()->assertJsonPath('data.two_factor_habilitado', true);
    Auth::logout();
    $this->panelComo(null, 'POST', 'auth/login', [
        'email' => 'totp@example.test',
        'password' => 'password',
        'codigo_2fa' => $siguiente,
    ])->assertUnprocessable()->assertJsonPath('code', '2fa_requerido');

    // Login con código de recuperación se consume tras el primer uso
    Auth::logout();
    $recuperacion = (string) $codigosRecuperacion[0];
    $this->panelComo(null, 'POST', 'auth/login', [
        'email' => 'totp@example.test',
        'password' => 'password',
        'codigo_2fa' => $recuperacion,
    ])->assertOk();
    Auth::logout();
    $this->panelComo(null, 'POST', 'auth/login', [
        'email' => 'totp@example.test',
        'password' => 'password',
        'codigo_2fa' => $recuperacion,
    ])->assertUnprocessable()->assertJsonPath('code', '2fa_requerido');
    $this->assertGuest();

    // Desactivar requiere clave actual y código válido: un TOTP de un paso ya usado u anterior no sirve; uno de recuperación sí.
    $this->panelComo($admin->fresh(), 'POST', 'compartido/perfil/2fa/desactivar', [
        'current_password' => 'password',
        'codigo' => Totp::codigoEn($secreto, time() - 30),
    ])->assertUnprocessable()->assertJsonValidationErrors('codigo');
    Cache::flush(); // el límite de perfil es de 5 por minuto y este flujo ya hizo varias llamadas
    $this->panelComo($admin->fresh(), 'POST', 'compartido/perfil/2fa/desactivar', [
        'current_password' => 'password',
        'codigo' => (string) $codigosRecuperacion[1],
    ])->assertOk();
    expect($admin->fresh()->tiene2faActivo())->toBeFalse()
        ->and(AuditLog::where('action', 'two_factor_disabled')->count())->toBe(1);
});

test('preferencias de notificación validan consentimiento WhatsApp y persisten en perfil', function (): void {
    $coord = $this->usuarioConRol('coordinador', 'prefs@example.test');

    // Activar WhatsApp sin consentimiento ni teléfono -> 422
    $this->panelComo($coord, 'PUT', 'compartido/perfil/preferencias', [
        'telefono' => null,
        'interno' => true,
        'email' => true,
        'whatsapp' => true,
        'whatsapp_consentimiento' => false,
        'recordatorio_cuotas' => true,
        'recordatorio_sesiones' => true,
        'recordatorio_reclamaciones' => true,
        'alerta_accesos' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('whatsapp_consentimiento');

    // Guardar con consentimiento y teléfono válidos -> 200
    $this->panelComo($coord, 'PUT', 'compartido/perfil/preferencias', [
        'telefono' => '+51 987 654 321',
        'interno' => true,
        'email' => true,
        'whatsapp' => true,
        'whatsapp_consentimiento' => true,
        'recordatorio_cuotas' => true,
        'recordatorio_sesiones' => false,
        'recordatorio_reclamaciones' => true,
        'alerta_accesos' => true,
    ])->assertOk()->assertJsonPath('data.preferencias_notificacion.whatsapp', true);

    $this->panelComo($coord->fresh(), 'GET', 'compartido/perfil')
        ->assertOk()
        ->assertJsonPath('data.telefono', '+51 987 654 321')
        ->assertJsonPath('data.preferencias_notificacion.email', true)
        ->assertJsonPath('data.preferencias_notificacion.recordatorio_sesiones', false);
});
