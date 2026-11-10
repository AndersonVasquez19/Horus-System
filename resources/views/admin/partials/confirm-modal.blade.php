{{-- Confirmación con <dialog> nativo (foco atrapado, fondo inerte, Escape cierra). Se abre con
     $dispatch('open-confirm-modal', { title, message, action, method, confirmText }). --}}
<dialog
    x-data="confirmModal"
    data-confirm-modal
    @open-confirm-modal.window="abrir($event.detail)"
    @close="alCerrar()"
    @click="clicFondo($event)"
    aria-labelledby="confirm-modal-title"
    aria-describedby="confirm-modal-message"
    class="w-[min(28rem,calc(100vw-2rem))] rounded-2xl border border-[#E2E8F0] bg-white p-0 text-[#172B3A] shadow-2xl backdrop:bg-[#0A2540]/60 backdrop:backdrop-blur-[2px]"
>
    <div class="p-6">
        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-red-600 ring-1 ring-red-200/80">
            <i class="fa-solid fa-triangle-exclamation text-lg" aria-hidden="true"></i>
        </div>
        <h2 id="confirm-modal-title" class="text-lg font-bold text-[#172B3A]" x-text="title">¿Estás seguro?</h2>
        <p id="confirm-modal-message" class="mt-2 text-sm leading-6 text-[#64748B]" x-text="message"></p>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <button x-ref="cancelBtn" type="button" @click="cerrar()" class="admin-btn-outline">
                Cancelar
            </button>
            <form :action="action" method="POST">
                @csrf
                <input type="hidden" name="_method" :value="method">
                <button type="submit" x-text="confirmText" class="admin-btn bg-red-600 text-white hover:bg-red-700">Sí, continuar</button>
            </form>
        </div>
    </div>
</dialog>
