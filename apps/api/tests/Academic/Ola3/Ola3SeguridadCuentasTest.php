<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\User;
use App\Notifications\RestablecerContrasenaWebNotification;
use App\Services\JwtTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('suspender y desbloquear usuario revoca sesiones y JWT e impide autosuspensión o suspender al último admin', function (): void {
    config(['session.driver' => 'database']);
    $admin = $this->usuarioConRol('admin', 'admin1@example.test');
    $coord = $this->usuarioConRol('coordinador', 'coord1@example.test');

    // Sesiones activas + JWT del coordinador
    $jwt = app(JwtTokenService::class)->issue($coord)['access_token'];
    DB::table('sessions')->insert([
        'id' => 'sesion-coord-activa-11111111111111111',
        'user_id' => $coord->id,
        'ip_address' => '198.51.100.10',
        'user_agent' => 'TestBrowser',
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => now()->getTimestamp(),
    ]);

    // Autosuspensión o último admin -> 422
    $this->panelComo($admin, 'POST', "compartido/usuarios/{$admin->id}/suspender", ['dias' => 5])
        ->assertUnprocessable();

    // Suspender coordinador
    $this->panelComo($admin, 'POST', "compartido/usuarios/{$coord->id}/suspender", [
        'dias' => 7,
        'motivo' => 'Licencia temporal',
    ])->assertOk()->assertJsonPath('data.suspendido', true);

    expect(DB::table('sessions')->where('user_id', $coord->id)->count())->toBe(0);
    $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$jwt])->assertUnauthorized();

    // Intento de login al panel rechazado genéricamente
    $this->panelComo(null, 'POST', 'auth/login', ['email' => 'coord1@example.test', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Las credenciales ingresadas no son válidas.');

    // Sesión ya abierta en memoria queda bloqueada en SesionPanelVigente (401)
    $this->panelComo($coord->fresh(), 'GET', 'academico/cursos')->assertUnauthorized()->assertJsonPath('code', 'cuenta_no_vigente');

    // Desbloquear permite entrar de nuevo
    $this->panelComo($admin, 'POST', "compartido/usuarios/{$coord->id}/desbloquear")
        ->assertOk()->assertJsonPath('data.suspendido', false);
    $this->panelComo($coord->fresh(), 'GET', 'academico/cursos')->assertOk();
});

test('baja lógica y restauración de usuario mantienen integridad y revocan credenciales', function (): void {
    $admin = $this->usuarioConRol('admin');
    $sec = $this->usuarioConRol('secretaria', 'baja-logica@example.test');
    $jwt = app(JwtTokenService::class)->issue($sec)['access_token'];

    $this->panelComo($admin, 'POST', "compartido/usuarios/{$sec->id}/desactivar")
        ->assertOk()
        ->assertJsonPath('data.eliminado', true);
    expect($sec->fresh()->estaDadoDeBaja())->toBeTrue()
        ->and(AuditLog::where('action', 'user_deactivated')->count())->toBe(1);
    $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$jwt])->assertUnauthorized();

    $this->panelComo($admin, 'GET', 'compartido/usuarios?eliminados=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.eliminado', true);

    $this->panelComo($admin, 'POST', "compartido/usuarios/{$sec->id}/restaurar")
        ->assertOk()->assertJsonPath('data.eliminado', false);
    expect($sec->fresh()->estaDadoDeBaja())->toBeFalse()
        ->and(AuditLog::where('action', 'user_restored')->count())->toBe(1);
});

test('contraseña temporal obliga cambio en primer acceso antes de usar otros módulos', function (): void {
    $admin = $this->usuarioConRol('admin');
    $creadoId = (int) $this->panelComo($admin, 'POST', 'compartido/usuarios', [
        'name' => 'Temporal',
        'email' => 'temporal@example.test',
        'password' => 'Clave-Temporal-2026!',
        'password_confirmation' => 'Clave-Temporal-2026!',
        'temporal' => true,
        'roles' => ['coordinador'],
    ])->assertCreated()->assertJsonPath('data.debe_cambiar_clave', true)->json('data.id');

    $temporal = User::findOrFail($creadoId);

    // Rutas de módulos bloqueadas con 403 cambio_clave_requerido
    $this->panelComo($temporal, 'GET', 'academico/cursos')
        ->assertForbidden()->assertJsonPath('code', 'cambio_clave_requerido');

    // auth/sesion y PUT compartido/perfil siguen accesibles
    $this->panelComo($temporal, 'GET', 'auth/sesion')
        ->assertOk()->assertJsonPath('data.debe_cambiar_clave', true);

    $this->panelComo($temporal, 'PUT', 'compartido/perfil', [
        'name' => 'Temporal',
        'email' => 'temporal@example.test',
        'current_password' => 'Clave-Temporal-2026!',
        'password' => 'Nueva-Clave-Definitiva-2026!',
        'password_confirmation' => 'Nueva-Clave-Definitiva-2026!',
    ])->assertOk();

    expect($temporal->fresh()->debe_cambiar_password)->toBeFalse()
        ->and(Hash::check('Nueva-Clave-Definitiva-2026!', $temporal->fresh()->password))->toBeTrue();

    // Tras cambiarla ya accede a los módulos
    $this->panelComo($temporal->fresh(), 'GET', 'academico/cursos')->assertOk();
});

test('admin puede enviar enlace de acceso sin exponer token y cerrar sesiones remotas de otro usuario', function (): void {
    Notification::fake();
    config(['session.driver' => 'database']);

    $admin = $this->usuarioConRol('admin');
    $coord = $this->usuarioConRol('coordinador', 'invitado@example.test');
    $jwt = app(JwtTokenService::class)->issue($coord)['access_token'];
    DB::table('sessions')->insert([
        'id' => 'sesion-remota-22222222222222222222222',
        'user_id' => $coord->id,
        'ip_address' => '198.51.100.20',
        'user_agent' => 'RemoteUA',
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => now()->getTimestamp(),
    ]);

    $r = $this->panelComo($admin, 'POST', "compartido/usuarios/{$coord->id}/invitar")->assertOk();
    expect($r->getContent())->not->toContain('token');
    Notification::assertSentTo($coord, RestablecerContrasenaWebNotification::class);
    expect(AuditLog::where('action', 'user_invited')->count())->toBe(1);

    $this->panelComo($admin, 'POST', "compartido/usuarios/{$coord->id}/cerrar-sesiones")->assertOk();
    expect(DB::table('sessions')->where('user_id', $coord->id)->count())->toBe(0);
    $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$jwt])->assertUnauthorized();
    expect(AuditLog::where('action', 'user_sessions_revoked')->count())->toBe(1);
});

test('alertas de accesos fallidos y bloqueo automático respetan sus banderas apagadas por defecto y se activan bajo demanda', function (): void {
    $admin = $this->usuarioConRol('admin', 'admin-sec@example.test');
    $coord = $this->usuarioConRol('coordinador', 'objetivo-sec@example.test');

    // Con banderas apagadas por defecto, 3 fallos no bloquean ni crean alerta
    foreach (range(1, 3) as $_) {
        $this->panelComo(null, 'POST', 'auth/login', ['email' => 'objetivo-sec@example.test', 'password' => 'incorrecta'])->assertUnprocessable();
    }
    expect($coord->fresh()->estaSuspendido())->toBeFalse()
        ->and(NotificacionInterna::where('tipo', 'danger')->count())->toBe(0);

    // Con banderas activadas y umbral 3, los siguientes fallos disparan bloqueo automático y alerta
    config([
        'seguridad.bloqueo_intentos' => 3,
        'seguridad.bloqueo_minutos' => 15,
        'seguridad.bloqueo_auto_enabled' => true,
        'seguridad.alertas_login_enabled' => true,
    ]);
    $coord2 = $this->usuarioConRol('coordinador', 'objetivo2-sec@example.test');
    foreach (range(1, 3) as $_) {
        $this->panelComo(null, 'POST', 'auth/login', ['email' => 'objetivo2-sec@example.test', 'password' => 'incorrecta'])->assertUnprocessable();
    }
    expect($coord2->fresh()->estaSuspendido())->toBeTrue()
        ->and(AuditLog::where('action', 'user_locked_auto')->count())->toBe(1)
        ->and(NotificacionInterna::where('user_id', $admin->id)->where('tipo', 'danger')->count())->toBe(1);
});
