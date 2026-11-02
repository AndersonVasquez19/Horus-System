@props([
    'name' => 'busqueda',
    'value' => null,
    'placeholder' => 'Buscar...',
])

<label class="relative block w-full">
    <span class="sr-only">{{ $placeholder }}</span>
    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-[#64748B]" aria-hidden="true"></i>
    <input
        type="search"
        name="{{ $name }}"
        value="{{ $value ?? request($name) }}"
        placeholder="{{ $placeholder }}"
        class="admin-input pl-10"
    >
</label>
