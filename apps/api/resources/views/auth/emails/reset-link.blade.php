<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f4; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#ffffff; border:1px solid #e7e5e4; border-radius:16px; overflow:hidden;">
                    <tr style="background:#111827;">
                        <td style="padding:24px 32px; text-align:left;">
                            <div style="display:inline-block; background:#d4af37; color:#111827; font-weight:700; border-radius:12px; padding:12px 18px; font-size:22px; letter-spacing:0.08em;">H</div>
                            <div style="display:inline-block; margin-left:14px; color:#ffffff; font-size:18px; font-weight:700; vertical-align:middle;">Horus Group SRL</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px; font-size:26px; color:#111827;">Restablece tu contraseña</h1>
                            <p style="margin:0 0 24px; font-size:16px; line-height:1.6; color:#374151;">
                                Hola {{ $user->name ?? 'usuario' }}, recibimos una solicitud para cambiar la contraseña de tu cuenta de administración.
                            </p>
                            <p style="margin:0 0 24px; font-size:16px; line-height:1.6; color:#374151;">
                                Haz clic en el siguiente botón para crear una nueva contraseña. Este enlace expirará en 60 minutos.
                            </p>
                            <p style="margin:0 0 24px; text-align:center;">
                                <a href="{{ $url }}" style="display:inline-block; background:#d4af37; color:#111827; text-decoration:none; padding:14px 28px; border-radius:10px; font-weight:700;">Restablecer contraseña</a>
                            </p>
                            <p style="margin:0 0 8px; font-size:14px; line-height:1.6; color:#4b5563;">
                                Si no solicitaste este cambio, puedes ignorar este correo y tu contraseña seguirá vigente.
                            </p>
                            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5563;">
                                Si el botón no funciona, copia y pega esta URL en tu navegador:<br>
                                <span style="word-break:break-all;">{{ $url }}</span>
                            </p>
                        </td>
                    </tr>
                    <tr style="background:#fafaf9;">
                        <td style="padding:20px 32px; text-align:center; font-size:12px; color:#6b7280;">
                            © {{ date('Y') }} Horus Group SRL · Seguridad de acceso administrativo
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
