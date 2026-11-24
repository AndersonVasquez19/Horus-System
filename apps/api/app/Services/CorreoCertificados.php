<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoCertificado;
use App\Jobs\EnviarCertificadoCorreo;
use App\Mail\CertificadoEmitidoMail;
use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\User;
use App\Support\Entrada;
use App\Support\UrlWeb;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Envío del certificado por correo al estudiante: aviso con el enlace público de verificación (sin adjuntos).
 *
 * Apagado por defecto (`CERTIFICADO_MAIL_ENABLED`). Se envía con el botón «Enviar por correo» (`certificados.emitir`) o, solo si
 * `CERTIFICADO_MAIL_AUTO` está activo, al emitir. El envío va por la cola, con cuatro intentos, y deja constancia (`correo_enviado_at`,
 * auditoría) sin guardar la dirección. Un reenvío es una decisión explícita del personal y también queda auditado.
 */
final class CorreoCertificados
{
    public function habilitado(): bool
    {
        $remitente = strtolower(Entrada::texto(config('mail.from.address')));

        return config('certificado-mail.enabled') === true
            && filter_var($remitente, FILTER_VALIDATE_EMAIL) !== false
            && ! Str::endsWith($remitente, ['@example.com', '@example.org', '@example.net'])
            && preg_match('/[\r\n<>]/', Entrada::texto(config('mail.from.name'))) !== 1;
    }

    public function automatico(): bool
    {
        return $this->habilitado() && config('certificado-mail.auto') === true;
    }

    /** Valida y encola el envío pedido por el personal. */
    public function solicitar(User $actor, Certificado $certificado): void
    {
        abort_unless($this->habilitado(), 409, 'El envío de certificados por correo no está activado.');
        abort_unless($certificado->estado === EstadoCertificado::Emitido, 409, 'Solo se envía por correo un certificado vigente.');
        $certificado->loadMissing('estudiante');
        abort_if($this->destinatario($certificado) === null, 422, 'El estudiante no tiene un correo válido registrado.');

        AuditLog::record('certificado_correo_solicitado', Certificado::class, $certificado->id,
            ['codigo' => $certificado->codigo, 'reenvio' => $certificado->correo_enviado_at !== null], $actor->id);
        $this->encolar($certificado);
    }

    /** Al emitir: encola solo si el envío automático está activo y hay destinatario. Nunca interrumpe la emisión. */
    public function encolarSiAutomatico(Certificado $certificado): void
    {
        if (! $this->automatico()) {
            return;
        }
        try {
            $certificado->loadMissing('estudiante');
            if ($this->destinatario($certificado) !== null) {
                $this->encolar($certificado);
            }
        } catch (\Throwable) {
            Log::warning('certificado.mail', ['outcome' => 'queue_unavailable']);
        }
    }

    /** Envía el correo. Lo llama el job; relee todo porque pasó tiempo desde que se encoló. */
    public function enviar(int $id): void
    {
        $certificado = Certificado::query()->with(['estudiante', 'curso'])->find($id);
        if ($certificado === null || $certificado->estado !== EstadoCertificado::Emitido || ! $this->habilitado()) {
            return;
        }
        $correo = $this->destinatario($certificado);
        if ($correo === null) {
            $certificado->forceFill(['correo_error' => 'sin_correo'])->save();

            return;
        }

        $url = UrlWeb::ruta('/verificar/'.$certificado->codigo);
        Mail::to($correo)->send(new CertificadoEmitidoMail(
            trim((string) $certificado->estudiante?->nombres),
            $certificado->curso->nombre ?? 'Curso institucional',
            $certificado->codigo,
            str_starts_with($url, 'http') ? $url : null,
        ));

        $certificado->forceFill(['correo_enviado_at' => now(), 'correo_error' => null])->save();
        AuditLog::record('certificado_correo_enviado', Certificado::class, $certificado->id, ['codigo' => $certificado->codigo], null);
    }

    public function marcarFallo(int $id): void
    {
        Certificado::query()->whereKey($id)->update(['correo_error' => 'fallo_envio']);
        AuditLog::record('certificado_correo_fallido', Certificado::class, $id, [], null);
    }

    private function encolar(Certificado $certificado): void
    {
        EnviarCertificadoCorreo::dispatch((int) $certificado->id)
            ->onQueue(Entrada::texto(config('certificado-mail.queue', 'default')) ?: 'default')
            ->afterCommit();
    }

    private function destinatario(Certificado $certificado): ?string
    {
        $correo = trim((string) $certificado->estudiante?->email);

        return filter_var($correo, FILTER_VALIDATE_EMAIL) !== false ? $correo : null;
    }
}
