<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        // El host de una petición anónima nunca determina el destino de un token de recuperación.
        $canonical = config('app.url');
        if (! is_string($canonical)) {
            throw new LogicException('La URL canónica de la aplicación no es válida.');
        }
        $parts = parse_url($canonical);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new LogicException('La URL canónica de la aplicación no es válida.');
        }
        $urls = clone app('url');
        $urls->forceRootUrl($canonical);
        $urls->forceScheme($parts['scheme']);
        $url = $urls->route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject('Restablece tu contraseña | Horus Group SRL')
            ->view('auth.emails.reset-link', [
                'url' => $url,
                'user' => $notifiable,
                'appName' => config('app.name'),
            ]);
    }
}
