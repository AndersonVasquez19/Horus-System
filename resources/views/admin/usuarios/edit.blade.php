@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Editar usuario')
@section('breadcrumb', 'Usuarios')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Actualización</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Editar usuario</h1>
            </div>
            <a href="{{ route('admin.usuarios.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-stone-200 px-4 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-100">
                <i class="fa-solid fa-arrow-left"></i>
                Volver
            </a>
        </div>

        <form action="{{ route('admin.usuarios.update', $usuario) }}" method="POST" class="rounded-2xl border border-gold/20 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="name" class="mb-1.5 block text-sm font-medium text-stone-700">Nombre completo</label>
                    <input id="name" name="name" type="text" value="{{ OldInput::value('name', $usuario->name) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" required />
                    @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-stone-700">Correo electrónico</label>
                    <input id="email" name="email" type="email" value="{{ OldInput::value('email', $usuario->email) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" required />
                    @error('email')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-stone-700">Nueva contraseña</label>
                    <input id="password" name="password" type="password" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                    @error('password')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-stone-700">Confirmar contraseña</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>

                @can('usuarios.asignar-roles')
                <div class="md:col-span-2">
                    <p class="mb-2 text-sm font-medium text-stone-700">Roles</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($roles as $rol)
                            <label class="flex items-center gap-2 rounded-xl border border-stone-200 px-3 py-2 text-sm text-stone-700">
                                <input type="checkbox" name="roles[]" value="{{ $rol->name }}" {{ $usuario->hasRole($rol->name) ? 'checked' : '' }} class="h-4 w-4 rounded border-stone-300 text-gold focus:ring-gold" />
                                <span>{{ $rol->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @endcan
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Actualizar usuario
                </button>
            </div>
        </form>
    </div>
@endsection
