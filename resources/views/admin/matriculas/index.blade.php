@extends('admin.layouts.app')

@section('title', 'Matrículas')
@section('breadcrumb', 'Matrículas')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">Gestión académica</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Matrículas</h1>
                <p class="mt-1 text-sm text-stone-600">Controla las inscripciones y los cupos de los cursos.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.matriculas.csv', request()->query()) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-gold px-4 py-2.5 text-sm font-semibold text-gold-dark transition hover:bg-gold/10">
                    <i class="fa-solid fa-file-csv" aria-hidden="true"></i>
                    Exportar CSV
                </a>
                @unless ($eliminados)
                    <a href="{{ route('admin.matriculas.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-gold-dark">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        Nueva matrícula
                    </a>
                @endunless
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="border-b border-stone-100 p-4 sm:p-5">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.matriculas.index') }}" @class([
                        'rounded-full px-4 py-2 text-sm font-semibold transition',
                        'bg-ink text-white' => ! $eliminados,
                        'bg-stone-100 text-stone-600 hover:bg-stone-200' => $eliminados,
                    ])>Vigentes</a>
                    <a href="{{ route('admin.matriculas.index', ['eliminados' => 1]) }}" @class([
                        'rounded-full px-4 py-2 text-sm font-semibold transition',
                        'bg-ink text-white' => $eliminados,
                        'bg-stone-100 text-stone-600 hover:bg-stone-200' => ! $eliminados,
                    ])>Eliminadas</a>
                </div>

                <form method="GET" action="{{ route('admin.matriculas.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_190px_200px_auto_auto]">
                    @if ($eliminados)
                        <input type="hidden" name="eliminados" value="1">
                    @endif
                    <x-admin.search-input name="busqueda" :value="$busqueda" placeholder="Buscar por estudiante o curso" />
                    <label>
                        <span class="sr-only">Filtrar por estado</span>
                        <select name="estado" class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 text-sm outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
                            <option value="">Todos los estados</option>
                            <option value="pendiente" @selected($estado === 'pendiente')>Pendiente</option>
                            <option value="pagada" @selected($estado === 'pagada')>Pagada</option>
                            <option value="anulada" @selected($estado === 'anulada')>Anulada</option>
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">Filtrar por curso</span>
                        <select name="curso_id" class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 text-sm outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
                            <option value="">Todos los cursos</option>
                            @foreach ($cursos as $curso)
                                <option value="{{ $curso->id }}" @selected((string) $cursoId === (string) $curso->id)>
                                    {{ $curso->codigo }} · {{ $curso->nombre }}{{ $curso->trashed() ? ' (eliminado)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-stone-800">
                        <i class="fa-solid fa-filter" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    @if ($busqueda !== '' || in_array($estado, ['pendiente', 'pagada', 'anulada'], true) || $cursoId !== null)
                        <a href="{{ route('admin.matriculas.index', $eliminados ? ['eliminados' => 1] : []) }}" class="inline-flex items-center justify-center rounded-xl border border-stone-200 px-4 py-2.5 text-sm font-semibold text-stone-600 transition hover:bg-stone-50">
                            Limpiar
                        </a>
                    @endif
                </form>
            </div>

            @if ($matriculas->isEmpty())
                <div class="p-5">
                    <x-admin.empty-state
                        :title="$eliminados ? 'No hay matrículas eliminadas' : 'No hay matrículas registradas'"
                        :message="$eliminados ? 'Las matrículas eliminadas aparecerán aquí para que puedas restaurarlas.' : 'Las matrículas registradas aparecerán en esta lista.'"
                        :action-url="$eliminados ? null : route('admin.matriculas.create')"
                        :action-text="$eliminados ? null : 'Crear una matrícula'"
                        icon="fa-clipboard-list"
                    />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="sticky top-16 z-10 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Estudiante</th>
                                <th class="px-5 py-3 font-semibold">Curso</th>
                                <th class="px-5 py-3 font-semibold">Fecha</th>
                                <th class="px-5 py-3 font-semibold">Estado</th>
                                <th class="px-5 py-3 font-semibold">Monto</th>
                                <th class="px-5 py-3 text-right font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($matriculas as $matricula)
                                <tr class="transition hover:bg-stone-50">
                                    <td class="px-5 py-4">
                                        <span class="block font-semibold text-ink">{{ $matricula->estudiante?->nombres }} {{ $matricula->estudiante?->apellidos }}</span>
                                        <span class="mt-1 block text-xs text-stone-500">{{ $matricula->estudiante?->dni ?? 'Estudiante no disponible' }}</span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="block font-medium text-ink">{{ $matricula->curso?->nombre ?? 'Curso no disponible' }}</span>
                                        <span class="mt-1 block text-xs text-stone-500">{{ $matricula->curso?->codigo }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-stone-600">{{ $matricula->fecha_matricula->format('d/m/Y') }}</td>
                                    <td class="px-5 py-4">
                                        @if ($matricula->trashed())
                                            <span class="inline-flex rounded-full border border-red-200 bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">Eliminada</span>
                                        @else
                                            <x-admin.badge-estado :estado="$matricula->estado" />
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-stone-600">
                                        {{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : '—' }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($matricula->trashed())
                                                <form method="POST" action="{{ route('admin.matriculas.restaurar', $matricula->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" title="Restaurar matrícula" aria-label="Restaurar matrícula" class="flex h-9 w-9 items-center justify-center rounded-lg text-green-700 transition hover:bg-green-50">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route('admin.matriculas.show', $matricula) }}" title="Ver matrícula" aria-label="Ver matrícula" class="flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 transition hover:bg-gold/10 hover:text-ink">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                </a>
                                                <a href="{{ route('admin.matriculas.edit', $matricula) }}" title="Editar matrícula" aria-label="Editar matrícula" class="flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 transition hover:bg-gold/10 hover:text-ink">
                                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                </a>
                                                @if ($matricula->estado !== 'anulada')
                                                    <button
                                                        type="button"
                                                        title="Anular matrícula"
                                                        aria-label="Anular matrícula"
                                                        @click="$dispatch('open-confirm-modal', { title: 'Anular matrícula', message: 'Al anular esta matrícula se liberará un cupo del curso.', action: '{{ route('admin.matriculas.anular', $matricula) }}', method: 'PATCH', confirmText: 'Sí, anular' })"
                                                        class="flex h-9 w-9 items-center justify-center rounded-lg text-amber-700 transition hover:bg-amber-50"
                                                    >
                                                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                                    </button>
                                                @endif
                                                <button
                                                    type="button"
                                                    title="Eliminar matrícula"
                                                    aria-label="Eliminar matrícula"
                                                    @click="$dispatch('open-confirm-modal', { title: 'Eliminar matrícula', message: 'El registro se ocultará de la lista principal. Si estaba activa, su cupo será liberado.', action: '{{ route('admin.matriculas.destroy', $matricula) }}', method: 'DELETE', confirmText: 'Sí, eliminar' })"
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
                    <x-admin.pagination :paginator="$matriculas" />
                </div>
            @endif
        </section>
    </div>
@endsection
