@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Roles')
@section('breadcrumb', 'Roles')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Seguridad</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Roles y permisos</h1>
            </div>
        </div>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-bold text-ink">Crear nuevo rol</h2>
            <form action="{{ route('admin.roles.store') }}" method="POST" class="mt-5 space-y-5">
                @csrf
                <div>
                    <label for="name" class="mb-1.5 block text-sm font-medium text-stone-700">Nombre del rol</label>
                    <input id="name" name="name" type="text" value="{{ OldInput::value('name') }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" required />
                    @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-stone-700">Permisos</p>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($permisos as $permiso)
                            <label class="flex items-center gap-2 rounded-xl border border-stone-200 px-3 py-2 text-sm text-stone-700">
                                <input type="checkbox" name="permissions[]" value="{{ $permiso->name }}" class="h-4 w-4 rounded border-stone-300 text-gold focus:ring-gold" />
                                <span>{{ $permiso->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                        <i class="fa-solid fa-plus"></i>
                        Crear rol
                    </button>
                </div>
            </form>
        </section>

        <section class="space-y-4">
            @foreach ($roles as $rol)
                <div class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-ink">{{ $rol->name }}</h2>
                            <p class="text-sm text-stone-500">{{ $rol->permissions->count() }} permisos asignados</p>
                        </div>

                        @if ($rol->name !== 'admin')
                            <form action="{{ route('admin.roles.destroy', $rol) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar este rol?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                                    <i class="fa-solid fa-trash"></i>
                                    Eliminar
                                </button>
                            </form>
                        @endif
                    </div>

                    <form action="{{ route('admin.roles.update', $rol) }}" method="POST" class="mt-5 space-y-5">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="name-{{ $rol->id }}" class="mb-1.5 block text-sm font-medium text-stone-700">Nombre</label>
                            <input id="name-{{ $rol->id }}" name="name" type="text" value="{{ OldInput::value('name', $rol->name) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" required />
                        </div>

                        <div>
                            <p class="mb-2 text-sm font-medium text-stone-700">Permisos</p>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($permisos as $permiso)
                                    <label class="flex items-center gap-2 rounded-xl border border-stone-200 px-3 py-2 text-sm text-stone-700">
                                        <input type="checkbox" name="permissions[]" value="{{ $permiso->name }}" {{ $rol->permissions->contains('name', $permiso->name) ? 'checked' : '' }} class="h-4 w-4 rounded border-stone-300 text-gold focus:ring-gold" />
                                        <span>{{ $permiso->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gold px-5 py-2.5 text-sm font-semibold text-ink hover:bg-gold-dark">
                                <i class="fa-solid fa-floppy-disk"></i>
                                Guardar cambios
                            </button>
                        </div>
                    </form>
                </div>
            @endforeach
        </section>
    </div>
@endsection
