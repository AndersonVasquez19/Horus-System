<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Entrada;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

/**
 * Enlace de recuperación hacia apps/web (/restablecer-contrasena), usado por la API del panel.
 *
 * - El destino sale solo de HORUS_WEB_URL (configuración), nunca del host de la petición anónima.
 * - Token y correo viajan en el fragmento (#): el navegador no los envía a ningún servidor, ni en la petición ni
 *   en el Referer, y no aparecen en registros de acceso. La página de apps/web los lee y los envía por POST.
 * - Reutiliza la plantilla de correo existente.
 */
final class RestablecerContrasenaWebNotification extends ResetPassword
{
    /**
     * @param  CanResetPassword  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Restablece tu contraseña | Horus Group SRL')
            ->view('auth.emails.reset-link', [
                'url' => self::enlace(Entrada::texto(config('horus-web.url')), $this->token, $notifiable->getEmailForPasswordReset()),
                'user' => $notifiable,
                'appName' => config('app.name'),
            ]);
    }

    public static function enlace(string $base, string $token, string $email): string
    {
        $partes = parse_url($base);
        if (! is_array($partes) || ! in_array($partes['scheme'] ?? null, ['http', 'https'], true)
            || empty($partes['host']) || isset($partes['user']) || isset($partes['pass'])
            || isset($partes['query']) || isset($partes['fragment'])) {
            throw new LogicException('HORUS_WEB_URL no es una URL válida para enlaces de recuperación.');
        }

        return rtrim($base, '/').'/restablecer-contrasena#'.http_build_query(['token' => $token, 'email' => $email], '', '&', PHP_QUERY_RFC3986);
    }
}
