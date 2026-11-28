<?php

declare(strict_types=1);

namespace App\Mail;

use App\Jobs\EnviarReclamacionCorreo;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Correo del Libro de Reclamaciones. Envía EXACTAMENTE el asunto y cuerpo recibidos (para el consumidor, los
 * guardados en `reclamacion_envios`: la constancia de lo remitido): parte de texto plano idéntica al cuerpo y parte
 * HTML escapada con el mismo texto. No usa vistas ni plantillas (no se crean ni modifican vistas Blade).
 * No se encola por sí mismo: lo envían el job {@see EnviarReclamacionCorreo} y el aviso a la empresa.
 *
 * `responderA` existe porque el dominio remitente no recibe correo: sin él, las respuestas se perderían.
 */
class ReclamacionMail extends Mailable
{
    public function __construct(
        private readonly string $asunto,
        private readonly string $texto,
        private readonly ?string $responderA = null,
    ) {
        $this->withSymfonyMessage(fn (Email $mensaje) => $mensaje->text($this->texto));
    }

    public function envelope(): Envelope
    {
        return new Envelope(replyTo: $this->responderA === null ? [] : [new Address($this->responderA)], subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="white-space: pre-wrap">'.e($this->texto).'</div>');
    }
}
