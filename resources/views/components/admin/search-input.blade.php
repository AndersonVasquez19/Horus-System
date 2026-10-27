@props([
    'name' => 'busqueda',
    'value' => null,
    'placeholder' => 'Buscar...',
])

<label class="relative block w-full">
    <span class="sr-only">{{ $placeholder }}</span>
    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-stone-400" aria-hidden="true"></i>
    <input
        type="search"
        name="{{ $name }}"
        value="{{ $value ?? request($name) }}"
        placeholder="{{ $placeholder }}"
        class="w-full rounded-xl border border-stone-200 bg-white py-2.5 pl-11 pr-4 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
    >
</label>
