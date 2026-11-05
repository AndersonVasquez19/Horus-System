@extends('admin.layouts.app')

@section('title', 'Matrículas')
@section('breadcrumb', 'Matrículas')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Gestión académica</p>
                <h1 class="admin-page-title">Matrículas</h1>
                <p class="admin-page-subtitle">Controla las inscripciones y los cupos de los cursos.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                @can('matriculas.exportar')
                    <a href="{{ route('admin.matriculas.csv', request()->query()) }}" class="admin-btn-outline">
                        <i class="fa-solid fa-file-csv text-[#19A7A1]" aria-hidden="true"></i>
                        Exportar CSV
                    </a>
                @endcan
                @unless ($eliminados)
                    <a href="{{ route('admin.matriculas.create') }}" class="admin-btn-primary">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        Nueva matrícula
                    </a>
                @endunless
            </div>
        </section>

        <section class="admin-card-flush">
            <div class="border-b border-[#E2E8F0] p-4 sm:p-5">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.matriculas.index') }}" @class([
                        'admin-tab',
                        'admin-tab-active' => ! $eliminados,
                        'admin-tab-inactive' => $eliminados,
                    ])>Vigentes</a>
                    <a href="{{ route('admin.matriculas.index', ['eliminados' => 1]) }}" @class([
                        'admin-tab',
                        'admin-tab-active' => $eliminados,
                        'admin-tab-inactive' => ! $eliminados,
                    ])>Eliminadas</a>
                </div>

                <form method="GET" action="{{ route('admin.matriculas.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_190px_210px_auto_auto]">
                    @if ($eliminados)
                        <input type="hidden" name="eliminados" value="1">
                    @endif
                    <x-admin.search-input name="busqueda" :value="$busqueda" placeholder="Buscar por estudiante o curso" />
                    <label class="block">
                        <span class="sr-only">Filtrar por estado</span>
                        <select name="estado" class="admin-select">
                            <option value="">Todos los estados</option>
                            <option value="pendiente" @selected($estado === 'pendiente')>Pendiente</option>
                            <option value="pagada" @selected($estado === 'pagada')>Pagada</option>
                            <option value="anulada" @selected($estado === 'anulada')>Anulada</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="sr-only">Filtrar por curso</span>
                        <select name="curso_id" class="admin-select">
                            <option value="">Todos los cursos</option>
                            @foreach ($cursos as $curso)
                                <option value="{{ $curso->id }}" @selected((string) $cursoId === (string) $curso->id)>
                                    {{ $curso->codigo }} · {{ $curso->nombre }}{{ $curso->trashed() ? ' (eliminado)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="admin-btn-secondary">
                        <i class="fa-solid fa-filter text-xs text-[#19A7A1]" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    @if ($busqueda !== '' || in_array($estado, ['pendiente', 'pagada', 'anulada'], true) || $cursoId !== null)
                        <a href="{{ route('admin.matriculas.index', $eliminados ? ['eliminados' => 1] : []) }}" class="admin-btn-outline">
                            Limpiar
                        </a>
                    @endif
                </form>
            </div>

            @if ($matriculas->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-admin.empty-state
                        :title="$eliminados ? 'No hay matrículas eliminadas' : 'No hay matrículas registradas'"
                        :message="$eliminados ? 'Las matrículas eliminadas aparecerán aquí para que puedas restaurarlas.' : 'Las matrículas registradas aparecerán en esta lista.'"
                        :action-url="$eliminados ? null : route('admin.matriculas.create')"
                        :action-text="$eliminados ? null : 'Crear una matrícula'"
                        icon="fa-clipboard-list"
                    />
                </div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[900px]">
                        <thead>
                            <tr>
                                <th scope="col">Estudiante</th>
                                <th scope="col">Curso</th>
                                <th scope="col">Fecha</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Monto</th>
                                <th scope="col" class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($matriculas as $matricula)
                                <tr>
                                    <td>
                                        <span class="block font-semibold text-[#172B3A]">{{ $matricula->estudiante?->nombres }} {{ $matricula->estudiante?->apellidos }}</span>
                                        <span class="mt-0.5 block text-xs text-[#64748B]">{{ $matricula->estudiante?->dni ?? 'Estudiante no disponible' }}</span>
                                    </td>
                                    <td>
                                        <span class="block font-medium text-[#172B3A]">{{ $matricula->curso?->nombre ?? 'Curso no disponible' }}</span>
                                        <span class="mt-0.5 block text-xs font-medium text-[#176B9A]">{{ $matricula->curso?->codigo }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">{{ $matricula->fecha_matricula->format('d/m/Y') }}</td>
                                    <td>
                                        @if ($matricula->trashed())
                                            <span class="inline-flex rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Eliminada</span>
                                        @else
                                            <x-admin.badge-estado :estado="$matricula->estado" />
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap font-semibold text-[#172B3A]">
                                        {{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : '—' }}
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($matricula->trashed())
                                                <form method="POST" action="{{ route('admin.matriculas.restaurar', $matricula->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" title="Restaurar matrícula" aria-label="Restaurar matrícula" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-emerald-700 transition hover:bg-emerald-50">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route('admin.matriculas.show', $matricula) }}" title="Ver matrícula" aria-label="Ver matrícula" class="admin-action-btn">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                </a>
                                                <a href="{{ route('admin.matriculas.edit', $matricula) }}" title="Editar matrícula" aria-label="Editar matrícula" class="admin-action-btn">
                                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                </a>
                                                @if ($matricula->estado !== 'anulada')
                                                    <button
                                                        type="button"
                                                        title="Anular matrícula"
                                                        aria-label="Anular matrícula"
                                                        @click="$dispatch('open-confirm-modal', { title: 'Anular matrícula', message: 'Al anular esta matrícula se liberará un cupo del curso.', action: '{{ route('admin.matriculas.anular', $matricula) }}', method: 'PATCH', confirmText: 'Sí, anular' })"
                                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-amber-700 transition hover:bg-amber-50"
                                                    >
                                                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                                    </button>
                                                @endif
                                                @can('matriculas.eliminar')
                                                    <button
                                                        type="button"
                                                        title="Eliminar matrícula"
                                                        aria-label="Eliminar matrícula"
                                                        @click="$dispatch('open-confirm-modal', { title: 'Eliminar matrícula', message: 'El registro se ocultará de la lista principal. Si estaba activa, su cupo será liberado.', action: '{{ route('admin.matriculas.destroy', $matricula) }}', method: 'DELETE', confirmText: 'Sí, eliminar' })"
                                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50"
                                                    >
                                                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                                    </button>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($matriculas->hasPages())
                    <div class="border-t border-[#E2E8F0] px-5 py-4">
                        <x-admin.pagination :paginator="$matriculas" />
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection
