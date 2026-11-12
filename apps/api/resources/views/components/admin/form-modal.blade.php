@props(['id', 'title', 'state' => null, 'onClose' => '', 'autoOpen' => false])

<dialog
    id="{{ $id }}"
    data-admin-modal
    @if ($autoOpen) data-auto-open @endif
    @if ($state)
        x-effect="window.HorusModal.sync($el, {{ $state }})"
        @admin-modal-cerrar="{{ $onClose }}"
        @close="if (!$el.open) { {{ $onClose }} }"
    @endif
    aria-labelledby="{{ $id }}-title"
    class="admin-form-modal"
>
    <div class="admin-modal-heading">
        <h2 id="{{ $id }}-title" class="text-lg font-bold text-[#172B3A]">{{ $title }}</h2>
        <button type="button" data-admin-modal-close class="admin-icon-btn" aria-label="Cerrar formulario">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>
    <div class="admin-modal-body">{{ $slot }}</div>
</dialog>
