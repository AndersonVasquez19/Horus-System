@extends('admin.layouts.app')

@section('title', 'Usuarios')
@section('breadcrumb', 'Usuarios')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Acceso</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Usuarios del sistema</h1>
            </div>

            @can('usuarios.crear')
                <a href="{{ route('admin.usuarios.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-user-plus"></i>
                    Nuevo usuario
                </a>
            @endcan
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-4 shadow-sm sm:p-5">
            <form method="GET" action="{{ route('admin.usuarios.index') }}" class="flex flex-col gap-3 md:flex-row md:items-center">
                <div class="flex-1">
                    <label for="busqueda" class="mb-1 block text-sm font-medium text-stone-700">Buscar por nombre o correo</label>
                    <input id="busqueda" name="busqueda" type="search" value="{{ $busqueda }}" placeholder="Ej. admin@horusgroup.com" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink hover:bg-gold-dark">
                    <i class="fa-solid fa-magnifying-glass mr-2"></i>
                    Buscar
                </button>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Nombre</th>
                            <th class="px-5 py-3 font-semibold">Correo</th>
                            <th class="px-5 py-3 font-semibold">Roles</th>
                            <th class="px-5 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($usuarios as $usuario)
                            <tr class="hover:bg-stone-50">
                                <td class="px-5 py-3 font-medium text-ink">{{ $usuario->name }}</td>
                                <td class="px-5 py-3 text-stone-600">{{ $usuario->email }}</td>
                                <td class="px-5 py-3">
                                    @forelse ($usuario->getRoleNames() as $rol)
                                        <span class="mb-1 mr-1 inline-flex rounded-full bg-gold/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-gold-dark">{{ $rol }}</span>
                                    @empty
                                        <span class="text-stone-400">Sin rol</span>
                                    @endforelse
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-2">
                                        @can('usuarios.editar')
                                            <a href="{{ route('admin.usuarios.edit', $usuario) }}" class="inline-flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100">
                                                <i class="fa-solid fa-pen"></i>
                                                Editar
                                            </a>
                                        @endcan

                                        @can('usuarios.eliminar')
                                            @if (auth()->id() !== $usuario->id)
                                                <form action="{{ route('admin.usuarios.destroy', $usuario) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar este usuario?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                                        <i class="fa-solid fa-trash"></i>
                                                        Eliminar
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-8">
                                    <x-admin.empty-state title="No hay usuarios" message="Aún no se registran usuarios en el sistema." icon="fa-user" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($usuarios->hasPages())
                <div class="border-t border-stone-100 px-5 py-3">
                    {{ $usuarios->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
