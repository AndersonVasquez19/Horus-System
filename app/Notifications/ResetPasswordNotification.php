<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], false));

        return (new MailMessage)
            ->subject('Restablece tu contraseña | Horus Group SRL')
            ->view('auth.emails.reset-link', [
                'url' => $url,
                'user' => $notifiable,
                'appName' => config('app.name'),
            ]);
    }
}
