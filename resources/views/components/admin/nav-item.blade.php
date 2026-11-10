@props([
    'href',
    'title',
    'icon',
    'active' => false,
])

<a
    href="{{ $href }}"
    title="{{ $title }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
        'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => $active,
        'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! $active,
    ]) }}
>
    @if ($active)
        <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
    @endif
    <i @class([
        "fa-solid {$icon} w-5 shrink-0 text-center transition-colors",
        'text-[#19A7A1]' => $active,
        'text-slate-400 group-hover:text-[#19A7A1]' => ! $active,
    ]) aria-hidden="true"></i>
    <span class="truncate md:hidden xl:inline">{{ $slot->isNotEmpty() ? $slot : $title }}</span>
</a>
