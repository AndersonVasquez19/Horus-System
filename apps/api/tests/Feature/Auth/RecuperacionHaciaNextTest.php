<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\RestablecerContrasenaWebNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

/**
 * El correo de recuperación apunta siempre a apps/web: Laravel ya no tiene página de restablecimiento (Fase 3 del retiro
 * de Blade), así que `HORUS_WEB_URL` es obligatoria. Datos ficticios.
 */
class RecuperacionHaciaNextTest extends TestCase
{
    use RefreshDatabase;

    private function enlace(string $token = 'token-ficticio'): string
    {
        $user = User::factory()->create(['email' => 'persona@example.test']);

        return (string) (new RestablecerContrasenaWebNotification($token))->toMail($user)->viewData['url'];
    }

    public function test_el_enlace_apunta_a_apps_web_con_token_y_correo_en_el_fragmento(): void
    {
        config(['horus-web.url' => 'https://web.example.test']);

        $url = $this->enlace();

        $this->assertStringStartsWith('https://web.example.test/restablecer-contrasena#', $url);
        $this->assertStringContainsString('token=token-ficticio', $url);
        $this->assertStringContainsString('email=persona%40example.test', $url);
        $this->assertNull(parse_url($url, PHP_URL_QUERY), 'Nada viaja en la query: el fragmento no sale del navegador.');
    }

    public function test_el_usuario_envia_siempre_la_notificacion_hacia_apps_web(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $user->sendPasswordResetNotification('token-ficticio');

        Notification::assertSentTo($user, RestablecerContrasenaWebNotification::class);
    }

    public function test_sin_horus_web_url_valida_no_se_inventa_ningun_enlace(): void
    {
        $user = User::factory()->create();
        foreach (['', 'javascript:alert(1)'] as $invalida) {
            config(['horus-web.url' => $invalida]);

            try {
                (new RestablecerContrasenaWebNotification('token-ficticio'))->toMail($user);
                $this->fail('Debió rechazar la URL: '.$invalida);
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }
}
