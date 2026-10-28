@extends('admin.layouts.app')

@section('title', 'Detalle del estudiante')
@section('breadcrumb', 'Estudiantes / Detalle')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('admin.estudiantes.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-stone-500 transition hover:text-ink">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver a estudiantes
                </a>
                <h1 class="mt-3 text-2xl font-bold text-ink">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</h1>
                <p class="mt-1 text-sm text-stone-600">Ficha de estudiante · DNI {{ $estudiante->dni }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                @can('matriculas.crear')
                    @if ($estudiante->estado === 'activo')
                        <a href="{{ route('admin.matriculas.create', ['estudiante_id' => $estudiante->id]) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-gold-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-dark">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            Nueva matrícula
                        </a>
                    @endif
                @endcan
                <a href="{{ route('admin.estudiantes.edit', $estudiante) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-gold px-4 py-2.5 text-sm font-semibold text-gold-dark transition hover:bg-gold/10">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    Editar
                </a>
                <button
                    type="button"
                    @click="$dispatch('open-confirm-modal', { title: 'Desactivar estudiante', message: 'El registro se ocultará de la lista principal. Puedes restaurarlo más adelante.', action: '{{ route('admin.estudiantes.destroy', $estudiante) }}', method: 'DELETE', confirmText: 'Sí, desactivar' })"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700"
                >
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    Desactivar
                </button>
            </div>
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-7">
            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-lg font-bold text-ink">Información personal</h2>
                <x-admin.badge-estado :estado="$estudiante->estado" />
            </div>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">DNI</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $estudiante->dni }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Correo electrónico</dt>
                    <dd class="mt-1 break-words font-medium text-ink">{{ $estudiante->email ?: 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Teléfono</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $estudiante->telefono ?: 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Fecha de nacimiento</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $estudiante->fecha_nacimiento?->format('d/m/Y') ?? 'No registrada' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Dirección</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $estudiante->direccion ?: 'No registrada' }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="border-b border-stone-100 px-5 py-4">
                <h2 class="font-bold text-ink">Matrículas del estudiante</h2>
                <p class="mt-1 text-sm text-stone-500">{{ $estudiante->matriculas->count() }} registros activos</p>
            </div>

            @if ($estudiante->matriculas->isEmpty())
                <div class="p-5">
                    <x-admin.empty-state title="Sin matrículas" message="Este estudiante todavía no tiene matrículas registradas." icon="fa-clipboard-list" />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[600px] text-left text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Curso</th>
                                <th class="px-5 py-3 font-semibold">Fecha</th>
                                <th class="px-5 py-3 font-semibold">Estado</th>
                                <th class="px-5 py-3 font-semibold">Monto pagado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($estudiante->matriculas as $matricula)
                                <tr>
                                    <td class="px-5 py-4 font-medium text-ink">{{ $matricula->curso?->nombre ?? 'Curso eliminado' }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-stone-600">{{ $matricula->fecha_matricula->format('d/m/Y') }}</td>
                                    <td class="px-5 py-4"><x-admin.badge-estado :estado="$matricula->estado" /></td>
                                    <td class="px-5 py-4 text-stone-600">{{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
