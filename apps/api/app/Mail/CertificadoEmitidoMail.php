<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso al estudiante de que su certificado fue emitido, con el enlace público de verificación (sin adjuntos). */
class CertificadoEmitidoMail extends Mailable
{
    public function __construct(
        public string $studentName,
        public string $courseName,
        public string $codigo,
        public ?string $urlVerificacion,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu certificado de Horus Group está listo');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.certificado-emitido', text: 'emails.certificado-emitido-text');
    }
}
