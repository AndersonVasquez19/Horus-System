<?php

declare(strict_types=1);

use App\Models\AvisoExterno;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MatriculaNotification;
use App\Models\MensajeContacto;
use App\Models\NotificacionInterna;
use App\Models\ReclamacionEnvio;
use App\Models\SesionClase;
use App\Services\EnvioAvisosExternosService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PreparaReclamaciones;

uses(PreparaReclamaciones::class);

test('horus:recordatorios-diarios queda apagado por defecto y genera recordatorios deduped al activarse', function (): void {
    $admin = $this->usuarioConRol('admin', 'rec-admin@example.test');
    $admin->forceFill([
        'telefono' => '+51999111222',
        'notificacion_preferencias' => [
            'interno' => true, 'email' => true, 'whatsapp' => true, 'whatsapp_consentimiento' => true,
            'recordatorio_cuotas' => true, 'recordatorio_sesiones' => true, 'recordatorio_reclamaciones' => true, 'alerta_accesos' => true,
        ],
    ])->save();

    $curso = Curso::create([
        'codigo' => 'REC3-1', 'nombre' => 'Curso Recordatorios', 'descripcion' => 'x', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '150.00', 'modalidad' => 'virtual', 'horario' => 'Lun',
        'cupos_maximos' => 10, 'cupos_disponibles' => 9, 'estado' => 'activo',
    ]);
    $conv = new Convocatoria([
        'nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDay()->toDateString(),
        'fecha_fin' => now()->addDays(20)->toDateString(), 'cupos_maximos' => 10,
    ]);
    $conv->identificador = (string) Str::uuid();
    $conv->codigo = 'CNV-2026-3001';
    $conv->curso_id = $curso->id;
    $conv->estado = 'en_curso';
    $conv->cupos_disponibles = 9;
    $conv->revision = 1;
    $conv->save();

    $est = Estudiante::create(['dni' => '76000001', 'nombres' => 'Lucía', 'apellidos' => 'Díaz', 'estado' => 'activo']);
    $mat = Matricula::create(['estudiante_id' => $est->id, 'curso_id' => $curso->id, 'convocatoria_id' => $conv->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    $this->panelComo($admin, 'POST', "academico/matriculas/{$mat->id}/obligacion", [
        'concepto' => 'Pensión', 'plan' => 'fraccionado', 'monto_total' => '150.00',
        'cuotas' => [
            ['monto' => '75.00', 'vence' => now('America/Lima')->addDays(2)->toDateString()],
            ['monto' => '75.00', 'vence' => now('America/Lima')->addDays(25)->toDateString()],
        ],
    ])->assertCreated();

    SesionClase::forceCreate([
        'identificador' => (string) Str::uuid(),
        'convocatoria_id' => $conv->id,
        'numero' => 1,
        'fecha' => now('America/Lima')->toDateString(),
        'hora_inicio' => '18:00:00',
        'hora_fin' => '20:00:00',
        'estado' => 'programada',
        'revision' => 1,
    ]);

    $mensaje = MensajeContacto::create([
        'nombre' => 'Visitante',
        'correo' => 'v@example.test',
        'asunto' => 'Cursos',
        'mensaje' => 'Consulta secreta del visitante',
        'estado' => MensajeContacto::ESTADO_NUEVO,
    ]);
    $mensaje->forceFill(['created_at' => now()->subHours(30)])->save();

    // 1) Apagado por defecto -> no escribe nada
    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    expect(NotificacionInterna::count())->toBe(0)->and(AvisoExterno::count())->toBe(0);

    // 2) Dry-run con bandera interna activa -> tampoco escribe
    config(['recordatorios.internos_enabled' => true]);
    $this->artisan('horus:recordatorios-diarios --dry-run')->assertSuccessful();
    expect(NotificacionInterna::count())->toBe(0);

    // 3) Ejecución real con email y whatsapp activos -> crea 3 notificaciones internas y 6 avisos externos (3x2) sin exponer mensaje del visitante
    config([
        'recordatorios.mail_enabled' => true,
        'recordatorios.whatsapp.enabled' => true,
        'recordatorios.whatsapp.phone_number_id' => '1234567890',
        'recordatorios.whatsapp.token' => 'wa-token-test',
        'recordatorios.queue_connection' => 'database',
        'services.resend.key' => 're_test_1234',
        'mail.from.address' => 'notificaciones@horus.pe',
        'mail.from.name' => 'Horus',
    ]);
    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();

    expect(NotificacionInterna::where('user_id', $admin->id)->count())->toBe(3)
        ->and(AvisoExterno::count())->toBe(6)
        ->and(NotificacionInterna::pluck('mensaje')->implode(' '))->not->toContain('Consulta secreta del visitante');

    // 4) Segunda corrida en el mismo día es idempotente (no duplica)
    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    expect(NotificacionInterna::where('user_id', $admin->id)->count())->toBe(3)
        ->and(AvisoExterno::count())->toBe(6);
});

test('EnvioAvisosExternosService retiene cuando el canal está deshabilitado y no despacha dentro de una transacción abierta', function (): void {
    Http::fake();
    $servicio = app(EnvioAvisosExternosService::class);

    $retenido = $servicio->registrar('email', 'recordatorio_cuota', 'ret-1', ['to' => 'docente@horus.pe', 'subject' => 'Hola', 'text' => 'Txt']);
    expect($retenido->status)->toBe(EnvioAvisosExternosService::HELD);

    // Incluso con canales activos, en RefreshDatabase (transacción abierta) send() no dispara HTTP
    config([
        'recordatorios.mail_enabled' => true,
        'recordatorios.queue_connection' => 'database',
        'services.resend.key' => 're_live_xxx',
        'mail.from.address' => 'avisos@horus.pe',
        'mail.from.name' => 'Horus',
    ]);
    $pendiente = $servicio->registrar('email', 'recordatorio_cuota', 'tx-guard-1', ['to' => 'dest@horus.pe', 'subject' => 'Cuota', 'text' => 'Vence']);
    $servicio->send($pendiente->id);
    expect($pendiente->fresh()->status)->toBe('pending');
    Http::assertNothingSent();
});

test('webhook de Resend valida firma Svix y actualiza entregas/rebotes en las 3 bandejas', function (): void {
    $this->habilitarReclamaciones();
    $admin = $this->usuarioConRol('admin', 'webhook-admin@example.test');

    $curso = Curso::create([
        'codigo' => 'WH3-1', 'nombre' => 'Curso Webhook', 'descripcion' => 'x', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '100.00', 'modalidad' => 'virtual', 'horario' => 'Lun',
        'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
    ]);
    $est = Estudiante::create(['dni' => '76111222', 'nombres' => 'Pedro', 'apellidos' => 'Soto', 'estado' => 'activo']);
    $mat = Matricula::create(['estudiante_id' => $est->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    $notifMat = MatriculaNotification::where('matricula_id', $mat->id)->firstOrFail();
    $notifMat->update(['status' => 'accepted', 'provider_id' => 're_msg_delivered']);

    $rec = $this->registrar()['reclamacion'];
    $envioRec = ReclamacionEnvio::create([
        'reclamacion_id' => $rec->id, 'tipo' => 'respuesta', 'canal' => 'correo',
        'clave' => (string) Str::uuid(), 'estado' => 'enviado', 'intentos' => 1,
        'asunto' => 'Resp', 'cuerpo' => 'Cuerpo', 'enviado_at' => now(), 'proveedor_id' => 're_msg_bounced',
    ]);

    $avisoExt = AvisoExterno::create([
        'canal' => 'email', 'tipo' => 'recordatorio_cuota', 'clave_unica' => 'ext-wh-1',
        'delivery_key' => (string) Str::uuid(), 'status' => 'accepted', 'provider_id' => 're_msg_delivered',
    ]);

    // Sin secreto -> 503
    config(['recordatorios.webhooks.resend_secret' => null]);
    $this->postJson('/api/webhooks/resend', ['type' => 'email.delivered'])->assertStatus(503);

    $secretoCrudo = 'mi-secreto-webhook-resend-12345';
    $secreto = 'whsec_'.base64_encode($secretoCrudo);
    config(['recordatorios.webhooks.resend_secret' => $secreto]);

    $firmar = function (string $cuerpo, int $ts, string $id = 'msg_01') use ($secretoCrudo): array {
        $sig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$cuerpo}", $secretoCrudo, true));

        return ['svix-id' => $id, 'svix-timestamp' => (string) $ts, 'svix-signature' => "v1,{$sig}"];
    };

    // Firma inválida -> 401
    $body1 = json_encode(['type' => 'email.delivered', 'data' => ['email_id' => 're_msg_delivered']], JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/webhooks/resend', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => 'msg_01',
        'HTTP_SVIX_TIMESTAMP' => (string) time(),
        'HTTP_SVIX_SIGNATURE' => 'v1,invalida',
    ], $body1)->assertUnauthorized();

    // Timestamp expirado -> 401
    $cabViejas = $firmar($body1, time() - 1000);
    $this->call('POST', '/api/webhooks/resend', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => $cabViejas['svix-id'],
        'HTTP_SVIX_TIMESTAMP' => $cabViejas['svix-timestamp'],
        'HTTP_SVIX_SIGNATURE' => $cabViejas['svix-signature'],
    ], $body1)->assertUnauthorized();

    // delivered válido -> actualiza matricula_notifications y avisos_externos
    $cab1 = $firmar($body1, time());
    $this->call('POST', '/api/webhooks/resend', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => $cab1['svix-id'],
        'HTTP_SVIX_TIMESTAMP' => $cab1['svix-timestamp'],
        'HTTP_SVIX_SIGNATURE' => $cab1['svix-signature'],
    ], $body1)->assertOk()->assertJsonPath('actualizados', 2);

    expect($notifMat->fresh()->provider_status)->toBe('delivered')
        ->and($notifMat->fresh()->delivered_at)->not->toBeNull()
        ->and($avisoExt->fresh()->provider_status)->toBe('delivered');

    // bounced válido -> actualiza reclamacion_envios y crea notificación de rebote al personal
    $body2 = json_encode(['type' => 'email.bounced', 'data' => ['email_id' => 're_msg_bounced']], JSON_THROW_ON_ERROR);
    $cab2 = $firmar($body2, time(), 'msg_02');
    $this->call('POST', '/api/webhooks/resend', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => $cab2['svix-id'],
        'HTTP_SVIX_TIMESTAMP' => $cab2['svix-timestamp'],
        'HTTP_SVIX_SIGNATURE' => $cab2['svix-signature'],
    ], $body2)->assertOk()->assertJsonPath('actualizados', 1);

    expect($envioRec->fresh()->provider_status)->toBe('bounced')
        ->and($envioRec->fresh()->bounced_at)->not->toBeNull()
        ->and(NotificacionInterna::where('user_id', $admin->id)->where('tipo', 'reclamacion_rebote')->count())->toBe(1);
});
