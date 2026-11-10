@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Mi perfil')
@section('breadcrumb', 'Mi perfil')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Mi cuenta</p>
                <h1 class="admin-page-title">Mi perfil</h1>
            </div>
        </div>

        <form action="{{ route('admin.perfil.update') }}" method="POST" class="admin-card sm:p-8" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="name" class="admin-label">Nombre completo <span class="text-red-600">*</span></label>
                    <input id="name" name="name" type="text" value="{{ OldInput::value('name', $usuario->name) }}" class="admin-input" required />
                    @error('name')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="email" class="admin-label">Correo electrónico <span class="text-red-600">*</span></label>
                    <input id="email" name="email" type="email" value="{{ OldInput::value('email', $usuario->email) }}" class="admin-input" required />
                    @error('email')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2 border-t border-[#E2E8F0] pt-5">
                    <p class="admin-label">Cambiar la contraseña</p>
                    <p class="text-sm text-[#5B6B7A]">Déjalo en blanco si no quieres cambiarla. Al cambiarla se cierran tus otras sesiones.</p>
                </div>

                <div>
                    <label for="password" class="admin-label">Nueva contraseña</label>
                    <input id="password" name="password" type="password" class="admin-input" autocomplete="new-password" />
                    @error('password')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="admin-label">Confirmar nueva contraseña</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="admin-input" autocomplete="new-password" />
                </div>

                <div class="md:col-span-2 border-t border-[#E2E8F0] pt-5">
                    <label for="current_password" class="admin-label">Contraseña actual</label>
                    <input id="current_password" name="current_password" type="password" class="admin-input" autocomplete="current-password" />
                    <p class="mt-1 text-sm text-[#5B6B7A]">Obligatoria solo si cambias el correo o la contraseña.</p>
                    @error('current_password')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6 flex justify-end border-t border-[#E2E8F0] pt-5">
                <button type="submit" class="admin-btn-primary">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    Guardar cambios
                </button>
            </div>
        </form>
    </div>
@endsection
