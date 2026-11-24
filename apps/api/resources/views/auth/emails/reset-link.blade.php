@extends('emails.layouts.base')

@section('asunto', 'Restablecer contraseña')
@section('preheader', 'Crea una nueva contraseña para tu cuenta de administración. El enlace vence en 60 minutos.')
@section('pie', 'Seguridad de acceso administrativo.')

@section('contenido')
    <h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#0a2540;">Restablece tu contraseña</h1>
    <p style="margin:0 0 14px;">Hola {{ $user->name ?? 'usuario' }}, recibimos una solicitud para cambiar la contraseña de tu cuenta de administración.</p>
    <p style="margin:0;">Haz clic en el siguiente botón para crear una nueva contraseña. Este enlace expirará en 60 minutos.</p>

    @include('emails.partials.boton', ['url' => $url, 'texto' => 'Restablecer contraseña'])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr>
            <td bgcolor="#f8fafc" style="background-color:#f8fafc;border-left:4px solid #d4af37;border-radius:8px;padding:14px 18px;font-size:14px;line-height:1.6;color:#526575;">
                Si no solicitaste este cambio, puedes ignorar este correo y tu contraseña seguirá vigente.
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:13px;line-height:1.6;color:#526575;">Si el botón no funciona, copia y pega esta URL en tu navegador:<br>
        <span style="word-break:break-all;color:#0a2540;">{{ $url }}</span></p>
@endsection
