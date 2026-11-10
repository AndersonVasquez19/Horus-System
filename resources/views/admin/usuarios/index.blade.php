@extends('admin.layouts.app')

@section('title', 'Usuarios')
@section('breadcrumb', 'Usuarios')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Acceso</p>
                <h1 class="admin-page-title">Usuarios del sistema</h1>
                <p class="admin-page-subtitle">Administra las cuentas de acceso y la asignación de roles del personal.</p>
            </div>

            @can('usuarios.crear')
                <a href="{{ route('admin.usuarios.create') }}" class="admin-btn-primary">
                    <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                    Nuevo usuario
                </a>
            @endcan
        </section>

        <section class="admin-card">
            <form method="GET" action="{{ route('admin.usuarios.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="busqueda" class="admin-label">Buscar por nombre o correo</label>
                    <input id="busqueda" name="busqueda" type="search" value="{{ $busqueda }}" placeholder="Ej. admin@horusgroup.com" class="admin-input" />
                </div>
                <button type="submit" class="admin-btn-secondary">
                    <i class="fa-solid fa-magnifying-glass text-xs text-[#19A7A1]" aria-hidden="true"></i>
                    Buscar
                </button>
            </form>
        </section>

        <section class="admin-card-flush">
            <div class="admin-table-wrap">
                <table class="admin-table min-w-[640px]">
                    <thead>
                        <tr>
                            <th scope="col">Nombre</th>
                            <th scope="col">Correo</th>
                            <th scope="col">Roles</th>
                            <th scope="col" class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($usuarios as $usuario)
                            <tr>
                                <td class="font-semibold text-[#172B3A]">{{ $usuario->name }}</td>
                                <td>{{ $usuario->email }}</td>
                                <td>
                                    @forelse ($usuario->getRoleNames() as $rol)
                                        <span class="mb-1 mr-1 inline-flex rounded-full border border-[#19A7A1]/30 bg-[#19A7A1]/10 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-[#0F766E]">{{ $rol }}</span>
                                    @empty
                                        <span class="text-xs text-[#64748B]">Sin rol</span>
                                    @endforelse
                                </td>
                                <td>
                                    <div class="flex justify-end gap-2">
                                        @can('usuarios.editar')
                                            <a href="{{ route('admin.usuarios.edit', $usuario) }}" class="admin-btn-outline min-h-9 px-3.5 py-1.5 text-xs">
                                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                Editar
                                            </a>
                                        @endcan

                                        @can('usuarios.eliminar')
                                            @if (auth()->id() !== $usuario->id)
                                                <form action="{{ route('admin.usuarios.destroy', $usuario) }}" method="POST" data-confirm="¿Deseas eliminar este usuario?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="admin-btn-danger min-h-9 px-3.5 py-1.5 text-xs">
                                                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
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
                                <td colspan="4" class="p-6">
                                    <x-admin.empty-state title="No hay usuarios" message="Aún no se registran usuarios en el sistema." icon="fa-user" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($usuarios->hasPages())
                <div class="border-t border-[#E2E8F0] px-5 py-4">
                    {{ $usuarios->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
