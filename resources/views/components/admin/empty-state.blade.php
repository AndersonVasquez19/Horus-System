@props([
    'title',
    'message',
    'actionUrl' => null,
    'actionText' => null,
    'icon' => 'fa-folder-open',
])

<div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center">
    <span class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gold/10 text-2xl text-gold-dark">
        <i class="fa-solid {{ $icon }}" aria-hidden="true"></i>
    </span>
    <h2 class="text-lg font-semibold text-ink">{{ $title }}</h2>
    <p class="mt-2 max-w-md text-sm leading-6 text-stone-500">{{ $message }}</p>
    @if ($actionUrl && $actionText)
        <a href="{{ $actionUrl }}" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-gold-dark">
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            {{ $actionText }}
        </a>
    @endif
</div>
