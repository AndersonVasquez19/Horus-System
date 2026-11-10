@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Configuración')
@section('breadcrumb', 'Configuración')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="admin-eyebrow">Sistema</p>
            <h1 class="admin-page-title">Configuración general</h1>
            <p class="admin-page-subtitle">Parámetros institucionales básicos de {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}. El nombre y la moneda se usan en el panel y en los reportes PDF.</p>
        </div>

        <form action="{{ route('admin.configuracion.update') }}" method="POST" class="admin-card sm:p-8">
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                    <p class="font-semibold">No se guardó la configuración. Revisa estos campos:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="settings_nombre_empresa" class="admin-label">Nombre de la empresa</label>
                    <input id="settings_nombre_empresa" name="settings[nombre_empresa]" type="text" value="{{ OldInput::value('settings.nombre_empresa', $settings['nombre_empresa']) }}" class="admin-input" />
                    @error('settings.nombre_empresa')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="settings_telefono" class="admin-label">Teléfono</label>
                    <input id="settings_telefono" name="settings[telefono]" type="text" value="{{ OldInput::value('settings.telefono', $settings['telefono']) }}" class="admin-input" />
                    @error('settings.telefono')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="settings_correo_contacto" class="admin-label">Correo de contacto</label>
                    <input id="settings_correo_contacto" name="settings[correo_contacto]" type="email" value="{{ OldInput::value('settings.correo_contacto', $settings['correo_contacto']) }}" class="admin-input" />
                    @error('settings.correo_contacto')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="settings_moneda" class="admin-label">Moneda</label>
                    <input id="settings_moneda" name="settings[moneda]" type="text" value="{{ OldInput::value('settings.moneda', $settings['moneda']) }}" class="admin-input" />
                    @error('settings.moneda')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="settings_direccion" class="admin-label">Dirección</label>
                    <input id="settings_direccion" name="settings[direccion]" type="text" value="{{ OldInput::value('settings.direccion', $settings['direccion']) }}" class="admin-input" />
                    @error('settings.direccion')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <p class="mt-5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-4 py-3 text-sm text-[#64748B]">
                <i class="fa-solid fa-lock mr-1.5 text-[#19A7A1]" aria-hidden="true"></i>
                Las claves de servicios externos (correo, IA) no se editan aquí: se configuran en el servidor mediante variables de entorno.
            </p>

            <div class="mt-6 flex justify-end border-t border-[#E2E8F0] pt-5">
                <button type="submit" class="admin-btn-primary">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    Guardar configuración
                </button>
            </div>
        </form>
    </div>
@endsection
