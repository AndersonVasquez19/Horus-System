@props(['estado'])

@php
    [$label, $classes, $dot] = match ($estado) {
        'activo' => ['Activo', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
        'pagada' => ['Pagada', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
        'aprobado' => ['Aprobado', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
        'completado' => ['Completado', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
        'publicada' => ['Publicada', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
        'atendida' => ['Atendida', 'border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
        'inactivo' => ['Inactivo', 'border-amber-200 bg-amber-50 text-amber-800', 'bg-amber-500'],
        'pendiente' => ['Pendiente', 'border-amber-200 bg-amber-50 text-amber-800', 'bg-amber-500'],
        'en_proceso' => ['En proceso', 'border-amber-200 bg-amber-50 text-amber-800', 'bg-amber-500'],
        'en_revision' => ['En revisión', 'border-amber-200 bg-amber-50 text-amber-800', 'bg-amber-500'],
        'recibida' => ['Recibida', 'border-sky-200 bg-sky-50 text-sky-800', 'bg-sky-500'],
        'programada' => ['Programada', 'border-sky-200 bg-sky-50 text-sky-800', 'bg-sky-500'],
        'borrador' => ['Borrador', 'border-[#E2E8F0] bg-[#F4F7FB] text-[#64748B]', 'bg-[#64748B]'],
        'archivado' => ['Archivado', 'border-red-200 bg-red-50 text-red-700', 'bg-red-500'],
        'anulada' => ['Anulada', 'border-red-200 bg-red-50 text-red-700', 'bg-red-500'],
        'rechazado' => ['Rechazado', 'border-red-200 bg-red-50 text-red-700', 'bg-red-500'],
        'cancelado' => ['Cancelado', 'border-red-200 bg-red-50 text-red-700', 'bg-red-500'],
        default => [ucfirst((string) $estado), 'border-[#E2E8F0] bg-[#F4F7FB] text-[#64748B]', 'bg-[#64748B]'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold', $classes]) }}>
    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
    {{ $label }}
</span>
