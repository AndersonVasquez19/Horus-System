<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\EnvioAvisosExternosService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prueba fuera de transacción global (DatabaseMigrations, como MatriculaNotificationTest)
 * el despacho real hacia Resend y WhatsApp Cloud API, verificando que sin consentimiento se omite
 * y que tras el envío el payload con PII se borra.
 */
class EnvioAvisosExternosTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
    }

    public function test_despacha_email_resend_y_whatsapp_con_consentimiento_fuera_de_transaccion(): void
    {
        Http::fake([
            'https://api.resend.com/*' => Http::response(['id' => 're_aviso_001'], 200),
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBgNNTE5OTk=']]], 200),
        ]);

        config([
            'recordatorios.mail_enabled' => true,
            'recordatorios.whatsapp.enabled' => true,
            'recordatorios.whatsapp.phone_number_id' => '112233',
            'recordatorios.whatsapp.token' => 'wa-secret',
            'recordatorios.queue_connection' => 'database',
            'services.resend.key' => 're_live_xxx',
            'mail.from.address' => 'avisos@horus.pe',
            'mail.from.name' => 'Horus',
        ]);

        $servicio = app(EnvioAvisosExternosService::class);

        $email = $servicio->registrar('email', 'recordatorio_cuota', 'mail-1', [
            'to' => 'dest@horus.pe',
            'subject' => 'Cuota',
            'text' => 'Vence pronto',
        ]);
        $this->assertSame(1, $servicio->dispatchDue());
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $email->refresh();
        $this->assertSame('accepted', $email->status);
        $this->assertSame('re_aviso_001', $email->provider_id);
        $this->assertNull($email->payload);

        $sinConsent = $servicio->registrar('whatsapp', 'recordatorio_cuota', 'wa-noconsent', [
            'telefono' => '999888777',
            'plantilla' => 'horus_recordatorio_cuota',
            'parametros' => ['Ana'],
            'consentimiento' => false,
        ]);
        $servicio->send($sinConsent->id);
        $sinConsent->refresh();
        $this->assertSame('skipped', $sinConsent->status);
        $this->assertSame('sin_consentimiento', $sinConsent->last_error);
        $this->assertNull($sinConsent->payload);

        $conConsent = $servicio->registrar('whatsapp', 'recordatorio_cuota', 'wa-ok', [
            'telefono' => '999888777',
            'plantilla' => 'horus_recordatorio_cuota',
            'parametros' => ['Ana'],
            'consentimiento' => true,
        ]);
        $servicio->send($conConsent->id);
        $conConsent->refresh();
        $this->assertSame('accepted', $conConsent->status);
        $this->assertSame('wamid.HBgNNTE5OTk=', $conConsent->provider_id);
        $this->assertNull($conConsent->payload);
    }
}
