@extends('admin.layouts.app')

@section('title', 'Cursos')
@section('breadcrumb', 'Cursos')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Gestión académica</p>
                <h1 class="admin-page-title">Cursos</h1>
                <p class="admin-page-subtitle">Administra la oferta de cursos, modalidades y cupos disponibles.</p>
            </div>
            @unless ($eliminados)
                <a href="{{ route('admin.cursos.create') }}" class="admin-btn-primary">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    Nuevo curso
                </a>
            @endunless
        </section>

        <section class="admin-card-flush">
            <div class="border-b border-[#E2E8F0] p-4 sm:p-5">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.cursos.index') }}" @class([
                        'admin-tab',
                        'admin-tab-active' => ! $eliminados,
                        'admin-tab-inactive' => $eliminados,
                    ])>Activos y vigentes</a>
                    <a href="{{ route('admin.cursos.index', ['eliminados' => 1]) }}" @class([
                        'admin-tab',
                        'admin-tab-active' => $eliminados,
                        'admin-tab-inactive' => ! $eliminados,
                    ])>Eliminados</a>
                </div>

                <form method="GET" action="{{ route('admin.cursos.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_180px_170px_auto_auto]">
                    @if ($eliminados)
                        <input type="hidden" name="eliminados" value="1">
                    @endif
                    <x-admin.search-input name="busqueda" :value="$busqueda" placeholder="Buscar por código o nombre" />
                    <label class="block">
                        <span class="sr-only">Filtrar por categoría</span>
                        <select name="categoria" class="admin-select">
                            <option value="">Todas las categorías</option>
                            <option value="tecnologia" @selected($categoria === 'tecnologia')>Tecnología</option>
                            <option value="educacion" @selected($categoria === 'educacion')>Educación</option>
                            <option value="salud" @selected($categoria === 'salud')>Salud</option>
                            <option value="gestion" @selected($categoria === 'gestion')>Gestión</option>
                            <option value="otros" @selected($categoria === 'otros')>Otros</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="sr-only">Filtrar por estado</span>
                        <select name="estado" class="admin-select">
                            <option value="">Todos los estados</option>
                            <option value="activo" @selected($estado === 'activo')>Activo</option>
                            <option value="inactivo" @selected($estado === 'inactivo')>Inactivo</option>
                            <option value="archivado" @selected($estado === 'archivado')>Archivado</option>
                        </select>
                    </label>
                    <button type="submit" class="admin-btn-secondary">
                        <i class="fa-solid fa-filter text-xs text-[#19A7A1]" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    @if ($busqueda !== '' || in_array($categoria, ['tecnologia', 'educacion', 'salud', 'gestion', 'otros'], true) || in_array($estado, ['activo', 'inactivo', 'archivado'], true))
                        <a href="{{ route('admin.cursos.index', $eliminados ? ['eliminados' => 1] : []) }}" class="admin-btn-outline">
                            Limpiar
                        </a>
                    @endif
                </form>
            </div>

            @if ($cursos->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-admin.empty-state
                        :title="$eliminados ? 'No hay cursos eliminados' : 'No hay cursos registrados'"
                        :message="$eliminados ? 'Los cursos eliminados aparecerán aquí para que puedas restaurarlos.' : 'Registra el primer curso para comenzar a administrar la oferta académica.'"
                        :action-url="$eliminados ? null : route('admin.cursos.create')"
                        :action-text="$eliminados ? null : 'Crear el primero'"
                        icon="fa-book-open"
                    />
                </div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[900px]">
                        <thead>
                            <tr>
                                <th scope="col">Código / Curso</th>
                                <th scope="col">Categoría</th>
                                <th scope="col">Modalidad</th>
                                <th scope="col">Precio</th>
                                <th scope="col">Cupos</th>
                                <th scope="col">Estado</th>
                                <th scope="col" class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cursos as $curso)
                                <tr>
                                    <td>
                                        <span class="block text-xs font-semibold text-[#176B9A]">{{ $curso->codigo }}</span>
                                        <span class="mt-0.5 block font-semibold text-[#172B3A]">{{ $curso->nombre }}</span>
                                    </td>
                                    <td class="capitalize">{{ str_replace('_', ' ', $curso->categoria) }}</td>
                                    <td class="capitalize">{{ $curso->modalidad }}</td>
                                    <td class="whitespace-nowrap font-semibold text-[#172B3A]">{{ \App\Support\ConfiguracionSistema::monto($curso->precio) }}</td>
                                    <td class="whitespace-nowrap">{{ $curso->cupos_disponibles }} / {{ $curso->cupos_maximos }}</td>
                                    <td>
                                        @if ($curso->trashed())
                                            <span class="inline-flex rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Eliminado</span>
                                        @else
                                            <x-admin.badge-estado :estado="$curso->estado" />
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($curso->trashed())
                                                <form method="POST" action="{{ route('admin.cursos.restaurar', $curso->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" title="Restaurar curso" aria-label="Restaurar curso" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-emerald-700 transition hover:bg-emerald-50">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route('admin.cursos.show', $curso) }}" title="Ver curso" aria-label="Ver curso" class="admin-action-btn">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                </a>
                                                <a href="{{ route('admin.cursos.edit', $curso) }}" title="Editar curso" aria-label="Editar curso" class="admin-action-btn">
                                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                </a>
                                                <button
                                                    type="button"
                                                    title="Desactivar curso"
                                                    aria-label="Desactivar curso"
                                                    @click="$dispatch('open-confirm-modal', { title: 'Desactivar curso', message: 'El curso se ocultará de la lista principal. Puedes restaurarlo más adelante.', action: '{{ route('admin.cursos.destroy', $curso) }}', method: 'DELETE', confirmText: 'Sí, desactivar' })"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50"
                                                >
                                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                                </button>
                                            @endif
                                            @can('cursos.publicar')
                                                <a href="{{ route('admin.cursos.publicacion.edit', $curso) }}" title="Publicación del curso" aria-label="Publicación del curso {{ $curso->codigo }}" class="admin-action-btn">
                                                    <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
                                                </a>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($cursos->hasPages())
                    <div class="border-t border-[#E2E8F0] px-5 py-4">
                        <x-admin.pagination :paginator="$cursos" />
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection
