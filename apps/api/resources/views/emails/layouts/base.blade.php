{{--
    Base de los correos transaccionales de Horus Group (bienvenida de matrícula, certificado, recuperar contraseña).
    Tablas y estilos en línea a propósito: es lo único que renderizan igual Gmail, Outlook y los clientes móviles.
    Secciones: `asunto` (título del documento), `preheader` (vista previa en la bandeja) y `contenido` (cuerpo).
    El logo solo se pinta si hay una URL pública https (App\Support\MarcaCorreo); si no, queda el nombre en texto.
--}}
@php($logo = \App\Support\MarcaCorreo::logoUrl())
<!doctype html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>@yield('asunto', 'Horus Group')</title>
</head>
<body style="margin:0;padding:0;background-color:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#243746;-webkit-text-size-adjust:100%;">
@hasSection('preheader')
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;">@yield('preheader')&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2f7" style="background-color:#eef2f7;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
                {{-- Cabecera --}}
                <tr>
                    <td bgcolor="#0a2540" style="background-color:#0a2540;border-radius:16px 16px 0 0;padding:26px 32px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                @if($logo)
                                    <td valign="middle" style="padding-right:16px;">
                                        <img src="{{ $logo }}" width="72" height="52" alt="Horus Group" style="display:block;border:0;outline:none;text-decoration:none;width:72px;height:52px;">
                                    </td>
                                @endif
                                <td valign="middle">
                                    <div style="font-size:22px;line-height:1.2;font-weight:bold;letter-spacing:0.16em;color:#ffffff;">HORUS GROUP</div>
                                    <div style="margin-top:4px;font-size:12px;line-height:1.4;letter-spacing:0.04em;color:#d9b84a;">Tecnolog&iacute;a, educaci&oacute;n, futuro</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr><td height="4" bgcolor="#d4af37" style="height:4px;line-height:4px;font-size:0;background-color:#d4af37;">&nbsp;</td></tr>

                {{-- Cuerpo --}}
                <tr>
                    <td bgcolor="#ffffff" style="background-color:#ffffff;padding:36px 36px 28px;font-size:16px;line-height:1.65;color:#243746;overflow-wrap:anywhere;word-break:break-word;">
                        @yield('contenido')
                    </td>
                </tr>

                {{-- Pie --}}
                <tr>
                    <td bgcolor="#f8fafc" style="background-color:#f8fafc;border-top:1px solid #e2e8f0;border-radius:0 0 16px 16px;padding:22px 36px;font-size:13px;line-height:1.7;color:#526575;">
                        <div style="font-weight:bold;color:#0a2540;">Horus Group SRL</div>
                        <div>Cajamarca, Per&uacute;</div>
                        <div style="margin-top:8px;">
                            Tel&eacute;fono o WhatsApp: <a href="{{ \App\Support\MarcaCorreo::WHATSAPP }}" style="color:#0a2540;text-decoration:underline;">{{ \App\Support\MarcaCorreo::TELEFONO }}</a><br>
                            Correo: <a href="mailto:{{ \App\Support\MarcaCorreo::CORREO }}" style="color:#0a2540;text-decoration:underline;">{{ \App\Support\MarcaCorreo::CORREO }}</a>
                        </div>
                        @hasSection('pie')
                            <div style="margin-top:10px;">@yield('pie')</div>
                        @endif
                    </td>
                </tr>
            </table>
            <div style="max-width:600px;padding:14px 8px 0;font-size:11px;line-height:1.5;color:#8a99a8;text-align:center;">
                &copy; {{ date('Y') }} Horus Group SRL. Este es un mensaje autom&aacute;tico: no respondas a esta direcci&oacute;n.
            </div>
        </td>
    </tr>
</table>
</body>
</html>
