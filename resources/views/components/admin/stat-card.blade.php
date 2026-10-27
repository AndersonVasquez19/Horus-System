@props([
    'title',
    'value',
    'icon',
    'description' => null,
])

<article class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-stone-500">{{ $title }}</p>
            <p class="mt-2 text-2xl font-bold tracking-tight text-ink">{{ $value }}</p>
            @if ($description)
                <p class="mt-1 text-xs text-stone-500">{{ $description }}</p>
            @endif
        </div>
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gold/15 text-gold-dark">
            <i class="fa-solid {{ $icon }}" aria-hidden="true"></i>
        </span>
    </div>
</article>
