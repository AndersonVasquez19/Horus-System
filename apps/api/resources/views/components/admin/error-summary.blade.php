@props([
    'title' => 'No se pudo completar la operación:',
    'errors' => $errors ?? null,
])

@if ($errors && $errors->any())
    <div {{ $attributes->class(['rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800']) }} role="alert">
        <p class="flex items-center gap-2 font-semibold">
            <i class="fa-solid fa-circle-exclamation text-red-600" aria-hidden="true"></i>
            <span>{{ $title }}</span>
        </p>
        <ul class="mt-2 list-disc space-y-0.5 pl-6 text-xs leading-5">
            @foreach ($errors->all() as $mensaje)
                <li>{{ $mensaje }}</li>
            @endforeach
        </ul>
        {{ $slot }}
    </div>
@endif
