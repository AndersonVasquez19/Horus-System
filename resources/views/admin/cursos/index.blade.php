@extends('admin.layouts.app')

@section('title', 'Cursos')
@section('breadcrumb', 'Cursos')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">Gestión académica</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Cursos</h1>
                <p class="mt-1 text-sm text-stone-600">Administra la oferta de cursos, modalidades y cupos disponibles.</p>
            </div>
            @unless ($eliminados)
                <a href="{{ route('admin.cursos.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-gold-dark">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    Nuevo curso
                </a>
            @endunless
        </section>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="border-b border-stone-100 p-4 sm:p-5">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.cursos.index') }}" @class([
                        'rounded-full px-4 py-2 text-sm font-semibold transition',
                        'bg-ink text-white' => ! $eliminados,
                        'bg-stone-100 text-stone-600 hover:bg-stone-200' => $eliminados,
                    ])>Activos y vigentes</a>
                    <a href="{{ route('admin.cursos.index', ['eliminados' => 1]) }}" @class([
                        'rounded-full px-4 py-2 text-sm font-semibold transition',
                        'bg-ink text-white' => $eliminados,
                        'bg-stone-100 text-stone-600 hover:bg-stone-200' => ! $eliminados,
                    ])>Eliminados</a>
                </div>

                <form method="GET" action="{{ route('admin.cursos.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_180px_170px_auto_auto]">
                    @if ($eliminados)
                        <input type="hidden" name="eliminados" value="1">
                    @endif
                    <x-admin.search-input name="busqueda" :value="$busqueda" placeholder="Buscar por código o nombre" />
                    <label>
                        <span class="sr-only">Filtrar por categoría</span>
                        <select name="categoria" class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 text-sm outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
                            <option value="">Todas las categorías</option>
                            <option value="tecnologia" @selected($categoria === 'tecnologia')>Tecnología</option>
                            <option value="educacion" @selected($categoria === 'educacion')>Educación</option>
                            <option value="salud" @selected($categoria === 'salud')>Salud</option>
                            <option value="gestion" @selected($categoria === 'gestion')>Gestión</option>
                            <option value="otros" @selected($categoria === 'otros')>Otros</option>
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">Filtrar por estado</span>
                        <select name="estado" class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 text-sm outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
                            <option value="">Todos los estados</option>
                            <option value="activo" @selected($estado === 'activo')>Activo</option>
                            <option value="inactivo" @selected($estado === 'inactivo')>Inactivo</option>
                            <option value="archivado" @selected($estado === 'archivado')>Archivado</option>
                        </select>
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-stone-800">
                        <i class="fa-solid fa-filter" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    @if ($busqueda !== '' || in_array($categoria, ['tecnologia', 'educacion', 'salud', 'gestion', 'otros'], true) || in_array($estado, ['activo', 'inactivo', 'archivado'], true))
                        <a href="{{ route('admin.cursos.index', $eliminados ? ['eliminados' => 1] : []) }}" class="inline-flex items-center justify-center rounded-xl border border-stone-200 px-4 py-2.5 text-sm font-semibold text-stone-600 transition hover:bg-stone-50">
                            Limpiar
                        </a>
                    @endif
                </form>
            </div>

            @if ($cursos->isEmpty())
                <div class="p-5">
                    <x-admin.empty-state
                        :title="$eliminados ? 'No hay cursos eliminados' : 'No hay cursos registrados'"
                        :message="$eliminados ? 'Los cursos eliminados aparecerán aquí para que puedas restaurarlos.' : 'Registra el primer curso para comenzar a administrar la oferta académica.'"
                        :action-url="$eliminados ? null : route('admin.cursos.create')"
                        :action-text="$eliminados ? null : 'Crear el primero'"
                        icon="fa-book-open"
                    />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="sticky top-16 z-10 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Código / Curso</th>
                                <th class="px-5 py-3 font-semibold">Categoría</th>
                                <th class="px-5 py-3 font-semibold">Modalidad</th>
                                <th class="px-5 py-3 font-semibold">Precio</th>
                                <th class="px-5 py-3 font-semibold">Cupos</th>
                                <th class="px-5 py-3 font-semibold">Estado</th>
                                <th class="px-5 py-3 text-right font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($cursos as $curso)
                                <tr class="transition hover:bg-stone-50">
                                    <td class="px-5 py-4">
                                        <span class="block text-xs font-semibold text-gold-dark">{{ $curso->codigo }}</span>
                                        <span class="mt-1 block font-semibold text-ink">{{ $curso->nombre }}</span>
                                    </td>
                                    <td class="px-5 py-4 capitalize text-stone-600">{{ str_replace('_', ' ', $curso->categoria) }}</td>
                                    <td class="px-5 py-4 capitalize text-stone-600">{{ $curso->modalidad }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-stone-600">S/ {{ number_format((float) $curso->precio, 2, '.', ',') }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-stone-600">{{ $curso->cupos_disponibles }} / {{ $curso->cupos_maximos }}</td>
                                    <td class="px-5 py-4">
                                        @if ($curso->trashed())
                                            <span class="inline-flex rounded-full border border-red-200 bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">Eliminado</span>
                                        @else
                                            <x-admin.badge-estado :estado="$curso->estado" />
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($curso->trashed())
                                                <form method="POST" action="{{ route('admin.cursos.restaurar', $curso->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" title="Restaurar curso" aria-label="Restaurar curso" class="flex h-9 w-9 items-center justify-center rounded-lg text-green-700 transition hover:bg-green-50">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route('admin.cursos.show', $curso) }}" title="Ver curso" aria-label="Ver curso" class="flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 transition hover:bg-gold/10 hover:text-ink">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                </a>
                                                <a href="{{ route('admin.cursos.edit', $curso) }}" title="Editar curso" aria-label="Editar curso" class="flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 transition hover:bg-gold/10 hover:text-ink">
                                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                </a>
                                                <button
                                                    type="button"
                                                    title="Desactivar curso"
                                                    aria-label="Desactivar curso"
                                                    @click="$dispatch('open-confirm-modal', { title: 'Desactivar curso', message: 'El curso se ocultará de la lista principal. Puedes restaurarlo más adelante.', action: '{{ route('admin.cursos.destroy', $curso) }}', method: 'DELETE', confirmText: 'Sí, desactivar' })"
                                                    class="flex h-9 w-9 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50"
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

                <div class="border-t border-stone-100 px-5 py-4">
                    <x-admin.pagination :paginator="$cursos" />
                </div>
            @endif
        </section>
    </div>
@endsection
