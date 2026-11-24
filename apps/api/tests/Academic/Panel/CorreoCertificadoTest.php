<?php

declare(strict_types=1);

use App\Enums\EstadoCertificado;
use App\Jobs\EnviarCertificadoCorreo;
use App\Mail\CertificadoEmitidoMail;
use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\CorreoCertificados;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Correo del certificado: aviso al estudiante con el enlace público de verificación (sin adjuntos). Apagado por defecto.
 */

function corCertificado(?string $email = 'alumno@correo.test', string $estado = 'emitido'): Certificado
{
    $curso = Curso::query()->firstOrCreate(['codigo' => 'COR-1'], ['nombre' => 'Curso correo', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
    $e = Estudiante::create(['dni' => (string) random_int(70000000, 79999999), 'nombres' => 'Lucía', 'apellidos' => 'Torres', 'email' => $email, 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    return Certificado::query()->create(['identificador' => (string) Str::uuid(), 'codigo' => 'HORUS-2026-'.random_int(1000, 9999), 'matricula_id' => $m->id,
        'estudiante_id' => $e->id, 'curso_id' => $curso->id, 'estado' => EstadoCertificado::from($estado), 'emitido_en' => now()]);
}

function corActivar(bool $auto = false): void
{
    config(['certificado-mail.enabled' => true, 'certificado-mail.auto' => $auto, 'mail.from.address' => 'matriculas@horsz.win', 'mail.from.name' => 'Horus Group',
        'horus-web.url' => 'https://horsz.win']);
}

it('apagado por defecto: responde 409 y no encola nada', function (): void {
    Queue::fake();
    $c = corCertificado();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', "academico/certificados/{$c->identificador}/enviar-correo")->assertStatus(409);
    Queue::assertNothingPushed();
});

it('activo: encola el envío, audita la solicitud y no guarda la dirección', function (): void {
    Queue::fake();
    corActivar();
    $c = corCertificado();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', "academico/certificados/{$c->identificador}/enviar-correo")->assertStatus(202);

    Queue::assertPushed(EnviarCertificadoCorreo::class, fn ($job) => $job->certificadoId === $c->id);
    $auditoria = AuditLog::query()->where('action', 'certificado_correo_solicitado')->firstOrFail();
    expect(json_encode($auditoria->changes))->not->toContain('correo.test');
});

it('rechaza un certificado anulado, un estudiante sin correo y a quien no puede emitir', function (): void {
    Queue::fake();
    corActivar();
    $otro = corCertificado('b@correo.test');
    $this->panelComo(null, 'POST', "academico/certificados/{$otro->identificador}/enviar-correo")->assertUnauthorized();
    $admin = $this->usuarioConRol('admin');

    $anulado = corCertificado('a@correo.test', 'anulado');
    $this->panelComo($admin, 'POST', "academico/certificados/{$anulado->identificador}/enviar-correo")->assertStatus(409);

    $sinCorreo = corCertificado(null);
    $this->panelComo($admin, 'POST', "academico/certificados/{$sinCorreo->identificador}/enviar-correo")->assertStatus(422);

    Queue::assertNothingPushed();
});

it('el job envía el correo con el enlace de verificación y deja constancia', function (): void {
    Mail::fake();
    corActivar();
    $c = corCertificado();

    app(CorreoCertificados::class)->enviar((int) $c->id);

    Mail::assertSent(CertificadoEmitidoMail::class, function (CertificadoEmitidoMail $m) use ($c): bool {
        return $m->hasTo('alumno@correo.test') && $m->urlVerificacion === 'https://horsz.win/verificar/'.$c->codigo && $m->codigo === $c->codigo;
    });
    expect($c->fresh()->correo_enviado_at)->not->toBeNull()->and($c->fresh()->correo_error)->toBeNull();
    $auditoria = AuditLog::query()->where('action', 'certificado_correo_enviado')->firstOrFail();
    expect(json_encode($auditoria->changes))->not->toContain('correo.test');
});

it('el job no envía si el certificado se anuló o el envío se apagó', function (): void {
    Mail::fake();
    corActivar();
    $anulado = corCertificado();
    $anulado->forceFill(['estado' => EstadoCertificado::Anulado])->save();
    app(CorreoCertificados::class)->enviar((int) $anulado->id);

    $vigente = corCertificado('c@correo.test');
    config(['certificado-mail.enabled' => false]);
    app(CorreoCertificados::class)->enviar((int) $vigente->id);

    Mail::assertNothingSent();
});

it('un fallo definitivo deja un código de error sin datos personales', function (): void {
    corActivar();
    $c = corCertificado();

    app(CorreoCertificados::class)->marcarFallo((int) $c->id);

    expect($c->fresh()->correo_error)->toBe('fallo_envio');
    expect(AuditLog::query()->where('action', 'certificado_correo_fallido')->count())->toBe(1);
});

it('al emitir, el envío automático solo se encola si está activo', function (): void {
    Queue::fake();
    $c = corCertificado();

    corActivar(false);
    app(CorreoCertificados::class)->encolarSiAutomatico($c);
    Queue::assertNothingPushed();

    corActivar(true);
    app(CorreoCertificados::class)->encolarSiAutomatico($c);
    Queue::assertPushed(EnviarCertificadoCorreo::class, 1);
});
