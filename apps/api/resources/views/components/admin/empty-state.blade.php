@props([
    'title',
    'message',
    'actionUrl' => null,
    'actionText' => null,
    'icon' => 'fa-folder-open',
])

<div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-[#E2E8F0] bg-[#F4F7FB]/70 px-6 py-10 text-center">
    <span class="mb-3.5 flex h-14 w-14 items-center justify-center rounded-2xl border border-[#176B9A]/20 bg-[#176B9A]/10 text-xl text-[#176B9A] shadow-sm">
        <i class="fa-solid {{ $icon }}" aria-hidden="true"></i>
    </span>
    <h2 class="text-base font-bold text-[#172B3A]">{{ $title }}</h2>
    <p class="mt-1.5 max-w-md text-sm leading-6 text-[#64748B]">{{ $message }}</p>
    @if ($actionUrl && $actionText)
        <a href="{{ $actionUrl }}" class="admin-btn-primary mt-5">
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            {{ $actionText }}
        </a>
    @endif
</div>
