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
            <button type="button" data-admin-modal-open="crear-rol" class="admin-btn-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Crear nuevo rol</button>
        </div>

        <x-admin.form-modal id="crear-rol" title="Crear nuevo rol" :auto-open="$errors->any() && old('_modal') === 'crear-rol'">
            @if ($errors->any() && old('_modal') === 'crear-rol')
                <ul role="alert" class="admin-error mb-4 list-inside list-disc">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            <form action="{{ route('admin.roles.store') }}" method="POST" class="mt-5 space-y-5">
                @csrf
                <input type="hidden" name="_modal" value="crear-rol">
                <div>
                    <label for="name" class="admin-label">Nombre del rol <span class="text-red-600">*</span></label>
                    <input id="name" name="name" type="text" value="{{ old('_modal') === 'crear-rol' ? OldInput::value('name') : '' }}" class="admin-input" required />
                    @if (old('_modal') === 'crear-rol')
                        @error('name')<p class="admin-error">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div>
                    <p class="admin-label">Permisos</p>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($permisos as $permiso)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-sm text-[#172B3A] transition hover:border-[#287FB5]/40">
                                <input type="checkbox" name="permissions[]" value="{{ $permiso->name }}" @checked(old('_modal') === 'crear-rol' && in_array($permiso->name, (array) old('permissions', []), true)) class="admin-checkbox" />
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
        </x-admin.form-modal>

        <section class="space-y-4">
            @foreach ($roles as $rol)
                <div class="admin-card">
                    <div class="flex flex-col gap-4 border-b border-[#E2E8F0] pb-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-[#172B3A]">{{ $rol->name }}</h2>
                            <p class="text-sm text-[#64748B]">{{ $rol->permissions->count() }} permisos asignados</p>
                        </div>
                        <button type="button" data-admin-modal-open="editar-rol-{{ $rol->id }}" class="admin-btn-outline">Editar rol y permisos</button>

                        @if ($rol->name !== 'admin')
                            <form action="{{ route('admin.roles.destroy', $rol) }}" method="POST" data-confirm="¿Deseas eliminar este rol?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-btn-danger min-h-9 px-3.5 py-1.5 text-xs">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    Eliminar
                                </button>
                            </form>
                        @endif
                    </div>

                    <x-admin.form-modal id="editar-rol-{{ $rol->id }}" :title="'Editar rol: '.$rol->name" :auto-open="$errors->any() && old('_modal') === 'editar-rol-'.$rol->id">
                        @if ($errors->any() && old('_modal') === 'editar-rol-'.$rol->id)
                            <ul role="alert" class="admin-error mb-4 list-inside list-disc">
                                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                            </ul>
                        @endif
                        <form action="{{ route('admin.roles.update', $rol) }}" method="POST" class="mt-5 space-y-5">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="_modal" value="editar-rol-{{ $rol->id }}">

                            <div>
                                <label for="name-{{ $rol->id }}" class="admin-label">Nombre <span class="text-red-600">*</span></label>
                                <input id="name-{{ $rol->id }}" name="name" type="text" value="{{ old('_modal') === 'editar-rol-'.$rol->id ? OldInput::value('name', $rol->name) : $rol->name }}" class="admin-input" required />
                            </div>

                            <div>
                                <p class="admin-label">Permisos</p>
                                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($permisos as $permiso)
                                        <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-sm text-[#172B3A] transition hover:border-[#287FB5]/40">
                                            <input type="checkbox" name="permissions[]" value="{{ $permiso->name }}" @checked(old('_modal') === 'editar-rol-'.$rol->id ? in_array($permiso->name, (array) old('permissions', []), true) : $rol->permissions->contains('name', $permiso->name)) class="admin-checkbox" />
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
                    </x-admin.form-modal>
                </div>
            @endforeach
        </section>
    </div>
@endsection
