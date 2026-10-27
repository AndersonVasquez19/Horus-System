<div
    x-data="{ open: false, title: '¿Estás seguro?', message: '', action: '', method: 'DELETE', confirmText: 'Sí, continuar' }"
    @open-confirm-modal.window="title = $event.detail.title || '¿Estás seguro?'; message = $event.detail.message || ''; action = $event.detail.action || ''; method = $event.detail.method || 'DELETE'; confirmText = $event.detail.confirmText || 'Sí, continuar'; open = true"
    x-cloak
    x-show="open"
    x-transition.opacity
    class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
    @keydown.escape.window="open = false"
    @click.self="open = false"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirm-modal-title"
>
    <div x-show="open" x-transition class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        </div>
        <h2 id="confirm-modal-title" class="text-lg font-bold text-ink" x-text="title"></h2>
        <p class="mt-2 text-sm leading-6 text-stone-600" x-text="message"></p>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-xl border border-stone-200 px-4 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                Cancelar
            </button>
            <form :action="action" method="POST">
                @csrf
                <input type="hidden" name="_method" :value="method">
                <button type="submit" x-text="confirmText" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700"></button>
            </form>
        </div>
    </div>
</div>
