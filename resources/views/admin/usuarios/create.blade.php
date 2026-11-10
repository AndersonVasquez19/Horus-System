@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Nuevo usuario')
@section('breadcrumb', 'Usuarios / Crear')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Registro</p>
                <h2 id="admin-form-title" class="admin-page-title">Nuevo usuario</h2>
            </div>
            <a href="{{ route('admin.usuarios.index') }}" class="admin-btn-outline">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver
            </a>
        </div>

        <form action="{{ route('admin.usuarios.store') }}" method="POST" class="admin-card sm:p-8">
            @csrf

            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="name" class="admin-label">Nombre completo <span class="text-red-600">*</span></label>
                    <input id="name" name="name" type="text" value="{{ OldInput::value('name') }}" class="admin-input" required />
                    @error('name')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="admin-label">Correo electrónico <span class="text-red-600">*</span></label>
                    <input id="email" name="email" type="email" value="{{ OldInput::value('email') }}" class="admin-input" required />
                    @error('email')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="admin-label">Contraseña <span class="text-red-600">*</span></label>
                    <input id="password" name="password" type="password" class="admin-input" required />
                    @error('password')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="password_confirmation" class="admin-label">Confirmar contraseña <span class="text-red-600">*</span></label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="admin-input" required />
                </div>

                @can('usuarios.asignar-roles')
                <div class="md:col-span-2">
                    <p class="admin-label">Roles</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($roles as $rol)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-sm font-medium text-[#172B3A] transition hover:border-[#287FB5]/40">
                                <input type="checkbox" name="roles[]" value="{{ $rol->name }}" class="admin-checkbox" />
                                <span>{{ $rol->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @endcan
            </div>

            <div class="mt-6 flex justify-end border-t border-[#E2E8F0] pt-5">
                <button type="submit" class="admin-btn-primary">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    Guardar usuario
                </button>
            </div>
        </form>
    </div>
@endsection
