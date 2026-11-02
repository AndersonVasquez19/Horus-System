@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Editar usuario')
@section('breadcrumb', 'Usuarios / Editar')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Actualización</p>
                <h1 class="admin-page-title">Editar usuario</h1>
            </div>
            <a href="{{ route('admin.usuarios.index') }}" class="admin-btn-outline">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver
            </a>
        </div>

        <form action="{{ route('admin.usuarios.update', $usuario) }}" method="POST" class="admin-card sm:p-8">
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

                <div>
                    <label for="email" class="admin-label">Correo electrónico <span class="text-red-600">*</span></label>
                    <input id="email" name="email" type="email" value="{{ OldInput::value('email', $usuario->email) }}" class="admin-input" required />
                    @error('email')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="admin-label">Nueva contraseña</label>
                    <input id="password" name="password" type="password" class="admin-input" />
                    @error('password')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="password_confirmation" class="admin-label">Confirmar contraseña</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="admin-input" />
                </div>

                @can('usuarios.asignar-roles')
                <div class="md:col-span-2">
                    <p class="admin-label">Roles</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($roles as $rol)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-sm font-medium text-[#172B3A] transition hover:border-[#287FB5]/40">
                                <input type="checkbox" name="roles[]" value="{{ $rol->name }}" {{ $usuario->hasRole($rol->name) ? 'checked' : '' }} class="admin-checkbox" />
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
                    Actualizar usuario
                </button>
            </div>
        </form>
    </div>
@endsection
