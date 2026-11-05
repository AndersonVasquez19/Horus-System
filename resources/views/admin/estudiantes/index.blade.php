@extends('admin.layouts.app')

@section('title', 'Estudiantes')
@section('breadcrumb', 'Estudiantes')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Gestión académica</p>
                <h1 class="admin-page-title">Estudiantes</h1>
                <p class="admin-page-subtitle">Consulta y administra los registros de estudiantes.</p>
            </div>
            @unless ($eliminados)
                <a href="{{ route('admin.estudiantes.create') }}" class="admin-btn-primary">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    Nuevo estudiante
                </a>
            @endunless
        </section>

        <section class="admin-card-flush">
            <div class="border-b border-[#E2E8F0] p-4 sm:p-5">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.estudiantes.index') }}" @class([
                        'admin-tab',
                        'admin-tab-active' => ! $eliminados,
                        'admin-tab-inactive' => $eliminados,
                    ])>Activos y vigentes</a>
                    <a href="{{ route('admin.estudiantes.index', ['eliminados' => 1]) }}" @class([
                        'admin-tab',
                        'admin-tab-active' => $eliminados,
                        'admin-tab-inactive' => ! $eliminados,
                    ])>Eliminados</a>
                </div>

                <form method="GET" action="{{ route('admin.estudiantes.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_200px_auto_auto]">
                    @if ($eliminados)
                        <input type="hidden" name="eliminados" value="1">
                    @endif
                    <x-admin.search-input name="busqueda" :value="$busqueda" placeholder="Buscar por DNI, nombre o apellido" />
                    <label class="block">
                        <span class="sr-only">Filtrar por estado</span>
                        <select name="estado" class="admin-select">
                            <option value="">Todos los estados</option>
                            <option value="activo" @selected($estado === 'activo')>Activo</option>
                            <option value="inactivo" @selected($estado === 'inactivo')>Inactivo</option>
                        </select>
                    </label>
                    <button type="submit" class="admin-btn-secondary">
                        <i class="fa-solid fa-filter text-xs text-[#19A7A1]" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    @if ($busqueda !== '' || in_array($estado, ['activo', 'inactivo'], true))
                        <a href="{{ route('admin.estudiantes.index', $eliminados ? ['eliminados' => 1] : []) }}" class="admin-btn-outline">
                            Limpiar
                        </a>
                    @endif
                </form>
            </div>

            @if ($estudiantes->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-admin.empty-state
                        :title="$eliminados ? 'No hay estudiantes eliminados' : 'No hay estudiantes registrados'"
                        :message="$eliminados ? 'Los estudiantes eliminados aparecerán aquí para que puedas restaurarlos.' : 'Registra el primer estudiante para comenzar a gestionar la información académica.'"
                        :action-url="$eliminados ? null : route('admin.estudiantes.create')"
                        :action-text="$eliminados ? null : 'Crear el primero'"
                        icon="fa-user-graduate"
                    />
                </div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[760px]">
                        <thead>
                            <tr>
                                <th scope="col">DNI</th>
                                <th scope="col">Estudiante</th>
                                <th scope="col">Contacto</th>
                                <th scope="col">Estado</th>
                                <th scope="col" class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($estudiantes as $estudiante)
                                <tr>
                                    <td class="whitespace-nowrap font-semibold text-[#172B3A]">{{ $estudiante->dni }}</td>
                                    <td>
                                        <span class="block font-semibold text-[#172B3A]">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</span>
                                        @if ($estudiante->trashed())
                                            <span class="mt-0.5 block text-xs text-[#64748B]">Eliminado {{ $estudiante->deleted_at->format('d/m/Y') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="block text-[#172B3A]">{{ $estudiante->email ?: 'Sin correo' }}</span>
                                        <span class="mt-0.5 block text-xs text-[#64748B]">{{ $estudiante->telefono ?: 'Sin teléfono' }}</span>
                                    </td>
                                    <td>
                                        @if ($estudiante->trashed())
                                            <span class="inline-flex rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Eliminado</span>
                                        @else
                                            <x-admin.badge-estado :estado="$estudiante->estado" />
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($estudiante->trashed())
                                                <form method="POST" action="{{ route('admin.estudiantes.restaurar', $estudiante->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" title="Restaurar estudiante" aria-label="Restaurar estudiante" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-emerald-700 transition hover:bg-emerald-50">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route('admin.estudiantes.show', $estudiante) }}" title="Ver estudiante" aria-label="Ver estudiante" class="admin-action-btn">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                </a>
                                                <a href="{{ route('admin.estudiantes.edit', $estudiante) }}" title="Editar estudiante" aria-label="Editar estudiante" class="admin-action-btn">
                                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                </a>
                                                <button
                                                    type="button"
                                                    title="Desactivar estudiante"
                                                    aria-label="Desactivar estudiante"
                                                    @click="$dispatch('open-confirm-modal', { title: 'Desactivar estudiante', message: 'El registro se ocultará de la lista principal. Puedes restaurarlo más adelante.', action: '{{ route('admin.estudiantes.destroy', $estudiante) }}', method: 'DELETE', confirmText: 'Sí, desactivar' })"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50"
                                                >
                                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($estudiantes->hasPages())
                    <div class="border-t border-[#E2E8F0] px-5 py-4">
                        <x-admin.pagination :paginator="$estudiantes" />
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection
