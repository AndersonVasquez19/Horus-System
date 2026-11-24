{{-- Botón "a prueba de Outlook" (tabla + enlace). Variables: $url (obligatoria) y $texto. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto;">
    <tr>
        <td align="center" bgcolor="#d4af37" style="background-color:#d4af37;border-radius:10px;">
            <a href="{{ $url }}" style="display:inline-block;padding:14px 30px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;line-height:1.2;color:#0a2540;text-decoration:none;border-radius:10px;">{{ $texto }}</a>
        </td>
    </tr>
</table>
