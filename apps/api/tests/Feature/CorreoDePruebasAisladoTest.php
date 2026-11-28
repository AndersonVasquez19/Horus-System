<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/**
 * Salvaguarda: las pruebas nunca deben poder enviar correo real, tampoco las que olvidan `Mail::fake()` y corren con
 * la cola `sync`. El 28/11/2026 una activación simulada del libro apuntó al transporte SMTP con las credenciales del
 * `.env` local y salieron correos de prueba de verdad.
 *
 * Las comprobaciones solo leen configuración (no conectan con nada) y nunca imprimen los valores: si fallan, la salida
 * no debe revelar credenciales.
 */
final class CorreoDePruebasAisladoTest extends TestCase
{
    use PreparaReclamaciones;

    public function test_las_credenciales_de_correo_del_entorno_local_no_llegan_a_las_pruebas(): void
    {
        foreach (['mail.mailers.smtp.username', 'mail.mailers.smtp.password', 'mail.mailers.smtp.url', 'services.resend.key', 'resend.api_key'] as $clave) {
            $this->assertTrue(blank(config($clave)), $clave.' debe estar vacía en las pruebas');
        }
        $this->assertTrue(config('mail.mailers.smtp.host') === '127.0.0.1', 'El SMTP de las pruebas no debe apuntar fuera de la máquina');
        $this->assertTrue((int) config('mail.mailers.smtp.port') === 1, 'El SMTP de las pruebas apunta a un puerto sin servicio');
    }

    public function test_la_activacion_simulada_del_libro_usa_un_transporte_que_no_envia(): void
    {
        $this->habilitarReclamaciones();

        $this->assertInstanceOf(ArrayTransport::class, Mail::mailer()->getSymfonyTransport());
    }
}
