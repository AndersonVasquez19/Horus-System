<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\RestablecerContrasenaWebNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

final class AutenticacionPanelTest extends PanelApiTestCase
{
    public function test_sin_token_de_servicio_configurado_la_api_no_existe(): void
    {
        config(['horus-web.service_token' => 'corto']);

        $this->panel('GET', 'auth/csrf')->assertNotFound();
    }

    public function test_un_token_de_servicio_incorrecto_responde_401_sin_abrir_sesion(): void
    {
        $this->withHeaders(['X-Horus-Web-Token' => str_repeat('x', 48)])
            ->getJson('/api/panel/v1/auth/csrf')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'No autorizado.', 'code' => 'servicio_no_autorizado'])
            ->assertCookieMissing((string) config('session.cookie'));
    }

    public function test_csrf_abre_una_sesion_anonima_sin_cache(): void
    {
        $respuesta = $this->panel('GET', 'auth/csrf')->assertOk();

        $this->assertIsString($respuesta->json('csrf_token'));
        $this->assertNotSame('', $respuesta->json('csrf_token'));
        $this->assertNotNull($this->cookieSesion);
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }

    public function test_admin_inicia_sesion_con_ambos_paneles_y_nueva_sesion(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->exigirCsrf();

        $csrfAnonimo = $this->tokenCsrf();
        $cookieAnonima = $this->cookieSesion;

        $respuesta = $this->panel('POST', 'auth/login', ['email' => 'ADMIN@horus.test ', 'password' => self::PASSWORD], [
            'X-CSRF-TOKEN' => $csrfAnonimo,
        ])->assertOk();

        $respuesta->assertJsonPath('data.usuario.email', 'admin@horus.test')
            ->assertJsonPath('data.paneles', ['academico' => true, 'servicios' => true])
            ->assertJsonPath('data.roles', ['admin']);
        $this->assertContains('panel.servicios.acceder', $respuesta->json('data.permisos'));
        $this->assertArrayNotHasKey('password', $respuesta->json('data.usuario'));

        // Fijación de sesión: cambian identificador de sesión y token CSRF.
        $this->assertNotSame($cookieAnonima, $this->cookieSesion);
        $this->assertNotSame($csrfAnonimo, $respuesta->json('data.csrf_token'));
        $this->assertIsInt($respuesta->json('data.expira_en'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $admin->getKey()]);
        $this->panel('GET', 'auth/sesion')->assertOk()->assertJsonPath('data.usuario.id', $admin->getKey());
    }

    public function test_coordinador_y_secretaria_solo_acceden_al_panel_academico(): void
    {
        foreach (['coordinador' => 'coordinador@horus.test', 'secretaria' => 'secretaria@horus.test'] as $rol => $email) {
            $this->cookieSesion = null;
            $this->usuarioConRol($rol, $email);

            $this->iniciarSesion($email)->assertOk()
                ->assertJsonPath('data.paneles', ['academico' => true, 'servicios' => false]);
        }
    }

    public function test_credenciales_incorrectas_responden_422_generico_y_se_auditan(): void
    {
        $this->usuarioConRol('admin', 'admin@horus.test');

        $this->iniciarSesion('admin@horus.test', 'otra-clave')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validacion')
            ->assertJsonPath('errors.email.0', 'Las credenciales ingresadas no son válidas.');

        $this->assertGuest();
        $registro = AuditLog::query()->where('action', 'login_failed')->sole();
        $this->assertSame('panel', $registro->changes['canal']);
        $this->assertTrue($registro->changes['cuenta_existente']);
        $this->assertStringNotContainsString('admin@horus.test', json_encode($registro->changes, JSON_THROW_ON_ERROR));
    }

    public function test_una_cuenta_sin_permiso_de_entrada_recibe_la_misma_respuesta_y_no_abre_sesion(): void
    {
        $this->usuarioConRol('admin', 'admin@horus.test');
        User::factory()->create(['email' => 'publico@horus.test', 'password' => self::PASSWORD]);

        $this->iniciarSesion('publico@horus.test')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales ingresadas no son válidas.');

        $this->panel('GET', 'auth/sesion')->assertUnauthorized()->assertJsonPath('code', 'no_autenticado');
    }

    public function test_un_rol_base_al_que_se_retiro_el_permiso_de_entrada_ya_no_puede_entrar(): void
    {
        $user = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $user->roles()->first()->revokePermissionTo('panel.academico.acceder');

        $this->iniciarSesion('secretaria@horus.test')->assertStatus(422);
    }

    public function test_el_login_sin_token_csrf_responde_419(): void
    {
        $this->usuarioConRol('admin', 'admin@horus.test');
        $this->exigirCsrf();
        $this->tokenCsrf();

        $this->panel('POST', 'auth/login', ['email' => 'admin@horus.test', 'password' => self::PASSWORD])
            ->assertStatus(419)
            ->assertExactJson([
                'message' => 'La verificación de seguridad expiró. Recarga e inténtalo de nuevo.',
                'code' => 'csrf',
            ]);
        $this->assertGuest();
    }

    public function test_sesion_sin_autenticar_responde_401_en_json_sin_redirigir(): void
    {
        $this->panel('GET', 'auth/sesion')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Inicia sesión para continuar.', 'code' => 'no_autenticado']);
    }

    public function test_la_sesion_expira_por_vida_maxima_aunque_haya_actividad(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $limite = (int) config('panel.sesion_absoluta_minutos');

        $this->actingAs($admin)
            ->withSession(['panel.autenticado_en' => now()->subMinutes($limite)->subSecond()->getTimestamp()])
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->getJson('/api/panel/v1/auth/sesion')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'sesion_expirada');

        $this->assertGuest();
    }

    public function test_una_sesion_sin_marca_de_inicio_recibe_una_al_primer_uso(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');

        $respuesta = $this->actingAs($admin)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->getJson('/api/panel/v1/auth/sesion')
            ->assertOk();

        $this->assertIsInt($respuesta->json('data.expira_en'));
    }

    public function test_logout_cierra_la_sesion_y_revoca_los_jwt(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->exigirCsrf();
        $csrf = $this->iniciarSesion('admin@horus.test')->assertOk()->json('data.csrf_token');

        $this->panel('POST', 'auth/logout', [], ['X-CSRF-TOKEN' => $csrf])->assertOk();

        $this->assertSame(1, $admin->fresh()->jwt_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $admin->getKey()]);
        $this->panel('GET', 'auth/sesion')->assertUnauthorized();
    }

    public function test_logout_sin_token_csrf_responde_419_y_conserva_la_sesion(): void
    {
        $this->usuarioConRol('admin', 'admin@horus.test');
        $this->exigirCsrf();
        $this->iniciarSesion('admin@horus.test')->assertOk();

        $this->panel('POST', 'auth/logout')->assertStatus(419);
        $this->panel('GET', 'auth/sesion')->assertOk();
    }

    public function test_solicitar_restablecimiento_no_revela_si_la_cuenta_existe(): void
    {
        Notification::fake();
        config(['horus-web.url' => 'https://horus.example']);
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');

        $existente = $this->panel('POST', 'auth/contrasena/solicitar', ['email' => 'admin@horus.test'])->assertStatus(202);
        $inexistente = $this->panel('POST', 'auth/contrasena/solicitar', ['email' => 'nadie@horus.test'])->assertStatus(202);

        $this->assertSame($existente->json(), $inexistente->json());
        Notification::assertSentTo($admin, RestablecerContrasenaWebNotification::class, function ($notificacion) use ($admin): bool {
            $url = $notificacion->toMail($admin)->viewData['url'];

            return str_starts_with($url, 'https://horus.example/restablecer-contrasena#token=')
                && str_contains($url, 'email=admin%40horus.test')
                && ! str_contains($url, '?');
        });
        Notification::assertCount(1);
    }

    public function test_el_enlace_de_recuperacion_rechaza_una_url_web_invalida(): void
    {
        $this->expectException(\LogicException::class);

        RestablecerContrasenaWebNotification::enlace('javascript:alert(1)', 'token', 'a@b.test');
    }

    public function test_restablecer_cambia_la_clave_y_revoca_jwt(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $token = Password::createToken($admin);

        $this->panel('POST', 'auth/contrasena/restablecer', [
            'token' => $token,
            'email' => 'admin@horus.test',
            'password' => 'Nueva-Clave-Segura-2026!',
            'password_confirmation' => 'Nueva-Clave-Segura-2026!',
        ])->assertOk();

        $admin->refresh();
        $this->assertTrue(Hash::check('Nueva-Clave-Segura-2026!', $admin->password));
        $this->assertSame(1, $admin->jwt_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset', 'user_id' => $admin->getKey()]);
    }

    public function test_restablecer_con_token_invalido_responde_422_generico(): void
    {
        $this->usuarioConRol('admin', 'admin@horus.test');

        $this->panel('POST', 'auth/contrasena/restablecer', [
            'token' => 'token-falso',
            'email' => 'admin@horus.test',
            'password' => 'Nueva-Clave-Segura-2026!',
            'password_confirmation' => 'Nueva-Clave-Segura-2026!',
        ])->assertStatus(422)->assertJsonPath('code', 'validacion')->assertJsonValidationErrors(['token']);
    }

    public function test_el_limite_de_login_cuenta_por_ip_del_visitante_y_no_por_el_bff(): void
    {
        $this->usuarioConRol('admin', 'admin@horus.test');

        $this->ipCliente = '198.51.100.1';
        for ($intento = 1; $intento <= 10; $intento++) {
            $this->iniciarSesion("atacante{$intento}@horus.test", 'mala')->assertStatus(422);
        }
        $this->iniciarSesion('atacante11@horus.test', 'mala')
            ->assertStatus(429)
            ->assertJsonPath('code', 'demasiadas_solicitudes')
            ->assertHeader('Retry-After');

        // Otro visitante detrás del mismo BFF no queda bloqueado.
        $this->ipCliente = '198.51.100.2';
        $this->cookieSesion = null;
        $this->iniciarSesion('admin@horus.test')->assertOk();
    }

    public function test_una_ip_de_cliente_invalida_se_ignora(): void
    {
        $this->ipCliente = 'no-es-una-ip';

        $this->panel('GET', 'auth/csrf')->assertOk();
    }
}
