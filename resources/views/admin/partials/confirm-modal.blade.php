<div
    x-data="{ open: false, title: '¿Estás seguro?', message: '', action: '', method: 'DELETE', confirmText: 'Sí, continuar' }"
    @open-confirm-modal.window="title = $event.detail.title || '¿Estás seguro?'; message = $event.detail.message || ''; action = $event.detail.action || ''; method = $event.detail.method || 'DELETE'; confirmText = $event.detail.confirmText || 'Sí, continuar'; open = true; $nextTick(() => $refs.cancelBtn?.focus())"
    x-cloak
    x-show="open"
    x-transition.opacity
    class="fixed inset-0 z-[60] flex items-center justify-center bg-[#0A2540]/60 p-4 backdrop-blur-[2px]"
    @keydown.escape.window="open = false"
    @click.self="open = false"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirm-modal-title"
>
    <div x-show="open" x-transition class="w-full max-w-md rounded-2xl border border-[#E2E8F0] bg-white p-6 shadow-2xl">
        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-red-600 ring-1 ring-red-200/80">
            <i class="fa-solid fa-triangle-exclamation text-lg" aria-hidden="true"></i>
        </div>
        <h2 id="confirm-modal-title" class="text-lg font-bold text-[#172B3A]" x-text="title"></h2>
        <p class="mt-2 text-sm leading-6 text-[#64748B]" x-text="message"></p>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <button x-ref="cancelBtn" type="button" @click="open = false" class="admin-btn-outline">
                Cancelar
            </button>
            <form :action="action" method="POST">
                @csrf
                <input type="hidden" name="_method" :value="method">
                <button type="submit" x-text="confirmText" class="admin-btn bg-red-600 text-white hover:bg-red-700"></button>
            </form>
        </div>
    </div>
</div>
