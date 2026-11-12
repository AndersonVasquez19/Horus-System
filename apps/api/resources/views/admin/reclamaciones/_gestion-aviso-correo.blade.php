{{--
    Aviso para las operaciones que remiten un correo al consumidor. Con el envío activo, el correo es REAL y
    el servidor exige `confirmar_envio` (GestionarReclamacionRequest::enviaCorreoReal). En la respuesta formal
    solo aplica al canal correo, por eso allí la casilla no es `required` en el navegador.
--}}
@php($soloCorreo = $soloCorreo ?? false)
@if ($correoActivo)
    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" data-aviso-correo-real>
        <p class="flex items-start gap-2 font-semibold">
            <i class="fa-solid fa-triangle-exclamation mt-0.5" aria-hidden="true"></i>
            <span>Se enviará un correo real a la dirección del consumidor{{ $soloCorreo ? ' si eliges el canal correo' : '' }}. No se puede deshacer.</span>
        </p>
        <label class="mt-3 flex items-start gap-2">
            <input type="checkbox" name="confirmar_envio" value="1" class="admin-checkbox mt-0.5"
                   @checked($operacionPrevia === $operacion && old('confirmar_envio'))
                   @if (! $soloCorreo) required @endif>
            <span>Confirmo que he revisado el texto y deseo enviarlo{{ $soloCorreo ? ' (obligatorio para el canal correo)' : '' }}.</span>
        </label>
    </div>
@else
    <p class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-3 text-xs text-[#475569]" data-aviso-correo-inactivo>
        El envío de correos del libro está desactivado: el correo quedará registrado como pendiente y no se remitirá.
    </p>
@endif
