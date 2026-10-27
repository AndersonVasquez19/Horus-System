@props(['estado'])

@php
    [$label, $classes] = match ($estado) {
        'activo' => ['Activo', 'border-green-200 bg-green-100 text-green-700'],
        'pagada' => ['Pagada', 'border-green-200 bg-green-100 text-green-700'],
        'inactivo' => ['Inactivo', 'border-yellow-200 bg-yellow-100 text-yellow-700'],
        'pendiente' => ['Pendiente', 'border-yellow-200 bg-yellow-100 text-yellow-700'],
        'archivado' => ['Archivado', 'border-red-200 bg-red-100 text-red-700'],
        'anulada' => ['Anulada', 'border-red-200 bg-red-100 text-red-700'],
        default => [ucfirst($estado), 'border-stone-200 bg-stone-100 text-stone-700'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold', $classes]) }}>
    {{ $label }}
</span>
