@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Roles')
@section('breadcrumb', 'Roles')

@section('content')
    <div class="space-y-6">
        <div class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Seguridad</p>
                <h1 class="admin-page-title">Roles y permisos</h1>
                <p class="admin-page-subtitle">Define perfiles de acceso y asigna permisos por módulo.</p>
            </div>
        </div>

        <section class="admin-card">
            <h2 class="text-lg font-bold text-[#172B3A]">Crear nuevo rol</h2>
            <form action="{{ route('admin.roles.store') }}" method="POST" class="mt-5 space-y-5">
                @csrf
                <div>
                    <label for="name" class="admin-label">Nombre del rol <span class="text-red-600">*</span></label>
                    <input id="name" name="name" type="text" value="{{ OldInput::value('name') }}" class="admin-input" required />
                    @error('name')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <p class="admin-label">Permisos</p>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($permisos as $permiso)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-sm text-[#172B3A] transition hover:border-[#287FB5]/40">
                                <input type="checkbox" name="permissions[]" value="{{ $permiso->name }}" class="admin-checkbox" />
                                <span>{{ $permiso->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                    <button type="submit" class="admin-btn-primary">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        Crear rol
                    </button>
                </div>
            </form>
        </section>

        <section class="space-y-4">
            @foreach ($roles as $rol)
                <div class="admin-card">
                    <div class="flex flex-col gap-4 border-b border-[#E2E8F0] pb-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-[#172B3A]">{{ $rol->name }}</h2>
                            <p class="text-sm text-[#64748B]">{{ $rol->permissions->count() }} permisos asignados</p>
                        </div>

                        @if ($rol->name !== 'admin')
                            <form action="{{ route('admin.roles.destroy', $rol) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar este rol?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-btn-danger min-h-9 px-3.5 py-1.5 text-xs">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    Eliminar
                                </button>
                            </form>
                        @endif
                    </div>

                    <form action="{{ route('admin.roles.update', $rol) }}" method="POST" class="mt-5 space-y-5">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="name-{{ $rol->id }}" class="admin-label">Nombre <span class="text-red-600">*</span></label>
                            <input id="name-{{ $rol->id }}" name="name" type="text" value="{{ OldInput::value('name', $rol->name) }}" class="admin-input" required />
                        </div>

                        <div>
                            <p class="admin-label">Permisos</p>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($permisos as $permiso)
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-sm text-[#172B3A] transition hover:border-[#287FB5]/40">
                                        <input type="checkbox" name="permissions[]" value="{{ $permiso->name }}" {{ $rol->permissions->contains('name', $permiso->name) ? 'checked' : '' }} class="admin-checkbox" />
                                        <span>{{ $permiso->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                            <button type="submit" class="admin-btn-primary">
                                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                                Guardar cambios
                            </button>
                        </div>
                    </form>
                </div>
            @endforeach
        </section>
    </div>
@endsection
