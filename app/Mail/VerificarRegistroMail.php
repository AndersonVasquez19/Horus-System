<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

class VerificarRegistroMail extends Mailable
{
    public function __construct(#[SensitiveParameter] private readonly string $codigo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirma tu correo | Horus Group SRL');
    }

    public function content(): Content
    {
        // Contenido independiente: no utiliza ni modifica las vistas de correo existentes.
        return new Content(htmlString: '<p>Usa este código para confirmar tu correo y establecer tu contraseña.</p><pre>'
            .e($this->codigo).'</pre><p>Caduca en 30 minutos. Si no solicitaste este proceso, ignora este correo.</p>');
    }
}
