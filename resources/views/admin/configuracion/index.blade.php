@extends('admin.layouts.app')

@section('title', 'Configuración')
@section('breadcrumb', 'Configuración')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Sistema</p>
            <h1 class="mt-1 text-2xl font-bold text-ink">Configuración general</h1>
        </div>

        <form action="{{ route('admin.configuracion.update') }}" method="POST" class="rounded-2xl border border-gold/20 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="settings_nombre_empresa" class="mb-1.5 block text-sm font-medium text-stone-700">Nombre de la empresa</label>
                    <input id="settings_nombre_empresa" name="settings[nombre_empresa]" type="text" value="{{ old('settings.nombre_empresa', $settings['nombre_empresa']) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>

                <div>
                    <label for="settings_telefono" class="mb-1.5 block text-sm font-medium text-stone-700">Teléfono</label>
                    <input id="settings_telefono" name="settings[telefono]" type="text" value="{{ old('settings.telefono', $settings['telefono']) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>

                <div>
                    <label for="settings_correo_contacto" class="mb-1.5 block text-sm font-medium text-stone-700">Correo de contacto</label>
                    <input id="settings_correo_contacto" name="settings[correo_contacto]" type="email" value="{{ old('settings.correo_contacto', $settings['correo_contacto']) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>

                <div>
                    <label for="settings_moneda" class="mb-1.5 block text-sm font-medium text-stone-700">Moneda</label>
                    <input id="settings_moneda" name="settings[moneda]" type="text" value="{{ old('settings.moneda', $settings['moneda']) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>

                <div class="md:col-span-2">
                    <label for="settings_direccion" class="mb-1.5 block text-sm font-medium text-stone-700">Dirección</label>
                    <input id="settings_direccion" name="settings[direccion]" type="text" value="{{ old('settings.direccion', $settings['direccion']) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>

                <div class="md:col-span-2">
                    <label for="settings_api_resend" class="mb-1.5 block text-sm font-medium text-stone-700">API de Resend</label>
                    <input id="settings_api_resend" name="settings[api_resend]" type="text" value="{{ old('settings.api_resend', $settings['api_resend']) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Guardar configuración
                </button>
            </div>
        </form>
    </div>
@endsection
