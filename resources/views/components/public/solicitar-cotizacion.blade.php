@props(['servicio'])

<button
    type="button"
    x-data
    data-servicio="{{ $servicio }}"
    @click="$dispatch('horus-cotizacion', { servicio: $el.dataset.servicio, origen: $el })"
    class="carta-solicitar-cotizacion"
    aria-label="{{ 'Solicitar cotización de '.$servicio }}"
    aria-haspopup="dialog"
    aria-controls="horus-chat-panel"
>
    Solicitar cotización <i class="fas fa-arrow-right" aria-hidden="true"></i>
</button>
