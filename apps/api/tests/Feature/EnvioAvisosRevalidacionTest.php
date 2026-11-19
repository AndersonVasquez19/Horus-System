<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\EnvioAvisosExternosService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fuera de transacción global (como EnvioAvisosExternosTest): un aviso ya encolado se revalida al enviar y no sale si la persona
 * retiró el consentimiento, cambió el teléfono o la cuenta se suspendió.
 */
class EnvioAvisosRevalidacionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_aviso_whatsapp_encolado_se_revalida_al_enviar(): void
    {
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        config([
            'recordatorios.whatsapp.enabled' => true,
            'recordatorios.whatsapp.phone_number_id' => '112233',
            'recordatorios.whatsapp.token' => 'wa-secret',
            'recordatorios.queue_connection' => 'database',
        ]);

        $user = User::create(['name' => 'Aviso', 'email' => 'aviso-wa@example.test', 'password' => bcrypt('password')]);
        $servicio = app(EnvioAvisosExternosService::class);
        $registrar = fn (string $clave) => $servicio->registrar('whatsapp', 'recordatorio_cuota', $clave, [
            'telefono' => '999888777', 'plantilla' => 'horus_recordatorio_cuota', 'parametros' => ['1', '2026-11-20'], 'consentimiento' => true,
        ], (int) $user->id);
        $prefs = fn (bool $consentimiento): array => ['whatsapp' => true, 'whatsapp_consentimiento' => $consentimiento];

        $user->forceFill(['telefono' => '999888777', 'notificacion_preferencias' => $prefs(false)])->save();
        $a = $registrar('wa-retirado');
        $servicio->send($a->id);
        $this->assertSame('skipped', $a->fresh()->status);
        $this->assertSame('sin_consentimiento', $a->fresh()->last_error);

        $user->forceFill(['telefono' => '911111111', 'notificacion_preferencias' => $prefs(true)])->save();
        $b = $registrar('wa-telefono');
        $servicio->send($b->id);
        $this->assertSame('telefono_cambiado', $b->fresh()->last_error);

        $user->forceFill(['telefono' => '999888777', 'bloqueado_hasta' => now()->addDay()])->save();
        $c = $registrar('wa-suspendida');
        $servicio->send($c->id);
        $this->assertSame('cuenta_no_disponible', $c->fresh()->last_error);

        $user->forceFill(['bloqueado_hasta' => null])->save();
        $d = $registrar('wa-ok');
        $servicio->send($d->id);
        $this->assertSame('accepted', $d->fresh()->status);
    }
}
