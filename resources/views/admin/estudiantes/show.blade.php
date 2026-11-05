@extends('admin.layouts.app')

@section('title', 'Detalle del estudiante')
@section('breadcrumb', 'Estudiantes / Detalle')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <a href="{{ route('admin.estudiantes.index') }}" class="admin-back-link">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver a estudiantes
                </a>
                <h1 class="mt-3 text-2xl font-bold tracking-tight text-[#172B3A]">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</h1>
                <p class="admin-page-subtitle">Ficha de estudiante · DNI {{ $estudiante->dni }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                @can('matriculas.crear')
                    @if ($estudiante->estado === 'activo')
                        <a href="{{ route('admin.matriculas.create', ['estudiante_id' => $estudiante->id]) }}" class="admin-btn-primary">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            Nueva matrícula
                        </a>
                    @endif
                @endcan
                <a href="{{ route('admin.estudiantes.edit', $estudiante) }}" class="admin-btn-outline">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    Editar
                </a>
                <button
                    type="button"
                    @click="$dispatch('open-confirm-modal', { title: 'Desactivar estudiante', message: 'El registro se ocultará de la lista principal. Puedes restaurarlo más adelante.', action: '{{ route('admin.estudiantes.destroy', $estudiante) }}', method: 'DELETE', confirmText: 'Sí, desactivar' })"
                    class="admin-btn-danger"
                >
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    Desactivar
                </button>
            </div>
        </section>

        <section class="admin-card sm:p-7">
            <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
                <h2 class="text-lg font-bold text-[#172B3A]">Información personal</h2>
                <x-admin.badge-estado :estado="$estudiante->estado" />
            </div>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">DNI</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $estudiante->dni }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Correo electrónico</dt>
                    <dd class="mt-1 break-words font-medium text-[#172B3A]">{{ $estudiante->email ?: 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Teléfono</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $estudiante->telefono ?: 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Fecha de nacimiento</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $estudiante->fecha_nacimiento?->format('d/m/Y') ?? 'No registrada' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Dirección</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $estudiante->direccion ?: 'No registrada' }}</dd>
                </div>
            </dl>
        </section>

        <section class="admin-card-flush">
            <div class="border-b border-[#E2E8F0] px-5 py-4 sm:px-6">
                <h2 class="font-bold text-[#172B3A]">Matrículas del estudiante</h2>
                @php
                    $estadosMatricula = $estudiante->matriculas->countBy('estado');
                    $activas = ($estadosMatricula['pendiente'] ?? 0) + ($estadosMatricula['pagada'] ?? 0);
                @endphp
                <p class="mt-1 text-sm text-[#64748B]">{{ $estudiante->matriculas->count() }} registros · {{ $activas }} activas</p>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-[#64748B]" aria-label="Matrículas por estado">
                    <li>Pendientes: {{ $estadosMatricula['pendiente'] ?? 0 }}</li>
                    <li>Pagadas: {{ $estadosMatricula['pagada'] ?? 0 }}</li>
                    <li>Anuladas: {{ $estadosMatricula['anulada'] ?? 0 }}</li>
                </ul>
            </div>

            @if ($estudiante->matriculas->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-admin.empty-state title="Sin matrículas" message="Este estudiante todavía no tiene matrículas registradas." icon="fa-clipboard-list" />
                </div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[600px]">
                        <thead>
                            <tr>
                                <th scope="col">Curso</th>
                                <th scope="col">Fecha</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Monto pagado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($estudiante->matriculas as $matricula)
                                <tr>
                                    <td class="font-medium text-[#172B3A]">{{ $matricula->curso?->nombre ?? 'Curso eliminado' }}</td>
                                    <td class="whitespace-nowrap">{{ $matricula->fecha_matricula->format('d/m/Y') }}</td>
                                    <td><x-admin.badge-estado :estado="$matricula->estado" /></td>
                                    <td class="font-semibold text-[#172B3A]">{{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
