@extends('emails.layouts.base')

@section('asunto', 'Matrícula registrada')
@section('preheader', 'Hemos registrado tu matrícula en el curso '.$courseName.'.')

@section('contenido')
    <h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#0a2540;">¡Te damos la bienvenida!</h1>
    <p style="margin:0 0 14px;">Hola, {{ $studentName }}.</p>
    <p style="margin:0 0 14px;">Hemos registrado tu matrícula en el curso <strong>{{ $courseName }}</strong>.</p>
    <p style="margin:0 0 22px;">Este mensaje confirma el registro de tu matrícula. No constituye una confirmación de pago.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
        <tr>
            <td bgcolor="#f8fafc" style="background-color:#f8fafc;border-left:4px solid #d4af37;border-radius:8px;padding:16px 20px;">
                <h2 style="margin:0 0 8px;font-size:18px;line-height:1.3;color:#0a2540;">Próximos pasos</h2>
                <p style="margin:0;">Comunícate con nuestro equipo para confirmar los requisitos, la modalidad, el horario y las indicaciones de acceso que correspondan a tu curso.</p>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:13px;color:#526575;">Si no reconoces esta matrícula, comunícate con nuestro equipo por los canales indicados.</p>
@endsection
