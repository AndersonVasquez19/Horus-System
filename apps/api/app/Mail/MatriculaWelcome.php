<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MatriculaWelcome extends Mailable
{
    public function __construct(public string $studentName, public string $courseName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bienvenido a Horus Group: matrícula registrada');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.matricula-welcome', text: 'emails.matricula-welcome-text');
    }

    public function plainText(): string
    {
        return view('emails.matricula-welcome-text', [
            'studentName' => $this->studentName,
            'courseName' => $this->courseName,
        ])->render();
    }
}
