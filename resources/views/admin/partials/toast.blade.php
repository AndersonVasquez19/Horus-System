@if (session('success') || session('error'))
    @php($isSuccess = session()->has('success'))
    <div
        x-data="{ visible: true }"
        x-show="visible"
        x-transition.opacity
        x-init="setTimeout(() => visible = false, 4000)"
        role="{{ $isSuccess ? 'status' : 'alert' }}"
        class="fixed right-4 top-20 z-50 flex max-w-sm items-start gap-3 rounded-xl border-l-4 bg-white p-4 shadow-xl sm:right-6"
        @class([
            'border-green-500' => $isSuccess,
            'border-red-500' => ! $isSuccess,
        ])
    >
        <i @class([
            'fa-solid mt-0.5',
            'fa-circle-check text-green-600' => $isSuccess,
            'fa-circle-exclamation text-red-600' => ! $isSuccess,
        ]) aria-hidden="true"></i>
        <p class="text-sm font-medium text-stone-800">{{ session('success') ?? session('error') }}</p>
        <button type="button" @click="visible = false" class="ml-auto text-stone-400 hover:text-stone-700" aria-label="Cerrar aviso">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>
@endif
