@extends('emails.layouts.base')

@section('asunto', 'Tu certificado')
@section('preheader', 'Tu certificado del curso '.$courseName.' está listo. Código '.$codigo.'.')

@section('contenido')
    <h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#0a2540;">Tu certificado está listo</h1>
    <p style="margin:0 0 14px;">Hola, {{ $studentName }}.</p>
    <p style="margin:0 0 20px;">Horus Group emitió tu certificado del curso <strong>{{ $courseName }}</strong>.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
        <tr>
            <td align="center" bgcolor="#f8fafc" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;">
                <div style="font-size:12px;line-height:1.4;letter-spacing:0.08em;text-transform:uppercase;color:#526575;">Código del certificado</div>
                <div style="margin-top:4px;font-family:'Courier New',Courier,monospace;font-size:22px;line-height:1.3;font-weight:bold;letter-spacing:0.06em;color:#0a2540;">{{ $codigo }}</div>
            </td>
        </tr>
    </table>

    @if ($urlVerificacion !== null)
        <p style="margin:0 0 4px;">Cualquier persona puede comprobar que es auténtico en este enlace:</p>
        @include('emails.partials.boton', ['url' => $urlVerificacion, 'texto' => 'Verificar certificado'])
        <p style="margin:0 0 20px;font-size:13px;color:#526575;">Si el botón no funciona, copia esta dirección en tu navegador:<br>
            <a href="{{ $urlVerificacion }}" style="color:#0a2540;word-break:break-all;">{{ $urlVerificacion }}</a></p>
    @endif

    <p style="margin:0 0 14px;">Para recibir el documento impreso o en PDF, comunícate con nuestro equipo.</p>
    <p style="margin:0;font-size:13px;color:#526575;">Si no reconoces este certificado, comunícate con nuestro equipo por los canales indicados.</p>
@endsection
