{{--
    Selector con búsqueda en el servidor (estudiante o curso). Variables:
    $nombre (campo del formulario), $url (opciones), $contexto (crear|editar|filtro), $inicial (opción o null),
    $placeholder, $requerido (bool) y $etiquetaAccesible (solo cuando el campo no tiene un <label> visible).
    El valor viaja en el <input type="hidden"> con el nombre original; el texto visible nunca se envía.
--}}
@php
    $idCampo = $idCampo ?? $nombre;
    $inicial = $inicial ?? null;
@endphp
<div
    x-data="selectorAsincrono"
    @focusout="salir($event)"
    data-id="{{ $idCampo }}"
    data-url="{{ $url }}"
    data-contexto="{{ $contexto }}"
    data-valor="{{ $inicial['id'] ?? '' }}"
    data-texto="{{ $inicial['texto'] ?? '' }}"
    class="relative"
>
    <input type="hidden" name="{{ $nombre }}" value="{{ $inicial['id'] ?? '' }}" :value="valor">
    <input
        id="{{ $idCampo }}"
        type="text"
        role="combobox"
        autocomplete="off"
        spellcheck="false"
        aria-autocomplete="list"
        aria-haspopup="listbox"
        aria-expanded="false"
        :aria-expanded="abierto.toString()"
        aria-controls="{{ $idCampo }}-lista"
        :aria-activedescendant="idActivo"
        @if (! empty($etiquetaAccesible)) aria-label="{{ $etiquetaAccesible }}" @endif
        @if (! empty($requerido)) required @endif
        value="{{ $inicial['texto'] ?? '' }}"
        placeholder="{{ $placeholder }}"
        class="admin-input"
        x-model="texto"
        @focus="abrir()"
        @click="abrir()"
        @input="escribir()"
        @keydown="teclado($event)"
    >
    <div
        x-show="abierto"
        x-cloak
        class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-xl border border-[#E2E8F0] bg-white py-1 shadow-lg"
    >
        <ul id="{{ $idCampo }}-lista" role="listbox" x-ref="lista" class="m-0 list-none p-0">
            <template x-for="(opcion, indice) in opciones" :key="opcion.id">
                <li
                    role="option"
                    :id="idOpcion(indice)"
                    :aria-selected="(String(opcion.id) === valor).toString()"
                    @mousedown.prevent="elegir(opcion)"
                    @mousemove="activo = indice"
                    class="cursor-pointer px-3 py-2 text-sm text-[#172B3A]"
                    :class="indice === activo ? 'bg-[#F4F7FB]' : ''"
                    x-text="opcion.texto"
                ></li>
            </template>
        </ul>
        <p x-show="cargando" class="px-3 py-2 text-sm text-[#64748B]">Buscando…</p>
        <p x-show="error" x-text="error" class="px-3 py-2 text-sm text-red-700"></p>
        <p x-show="sinResultados" class="px-3 py-2 text-sm text-[#64748B]">Sin resultados. Prueba con otro texto.</p>
    </div>
    <p class="sr-only" role="status" aria-live="polite" x-text="anuncio"></p>
</div>
