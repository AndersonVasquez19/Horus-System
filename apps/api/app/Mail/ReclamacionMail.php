<?php

declare(strict_types=1);

namespace App\Mail;

use App\Jobs\EnviarReclamacionCorreo;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Correo del Libro de Reclamaciones. Envía EXACTAMENTE el asunto y cuerpo guardados en `reclamacion_envios`
 * (la constancia de lo remitido): parte de texto plano idéntica al cuerpo y parte HTML escapada con el mismo
 * texto. No usa vistas ni plantillas (no se crean ni modifican vistas Blade).
 * No se encola por sí mismo: lo envía el job {@see EnviarReclamacionCorreo}.
 */
class ReclamacionMail extends Mailable
{
    public function __construct(private readonly string $asunto, private readonly string $texto)
    {
        $this->withSymfonyMessage(fn (Email $mensaje) => $mensaje->text($this->texto));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="white-space: pre-wrap">'.e($this->texto).'</div>');
    }
}
