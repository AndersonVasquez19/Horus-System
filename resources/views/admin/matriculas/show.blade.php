@extends('admin.layouts.app')

@section('title', 'Detalle de matrícula')
@section('breadcrumb', 'Matrículas / Detalle')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <section class="admin-page-header">
            <div>
                <a href="{{ route('admin.matriculas.index') }}" class="admin-back-link">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver a matrículas
                </a>
                <h1 class="mt-3 text-2xl font-bold tracking-tight text-[#172B3A]">Detalle de matrícula #{{ $matricula->id }}</h1>
                <p class="admin-page-subtitle">Registrada el {{ $matricula->fecha_matricula->format('d/m/Y') }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.matriculas.edit', $matricula) }}" class="admin-btn-outline">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    Editar
                </a>
                @if ($matricula->estado !== 'anulada')
                    <button
                        type="button"
                        @click="$dispatch('open-confirm-modal', { title: 'Anular matrícula', message: 'Al anular esta matrícula se liberará un cupo del curso.', action: '{{ route('admin.matriculas.anular', $matricula) }}', method: 'PATCH', confirmText: 'Sí, anular' })"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800 transition hover:bg-amber-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                    >
                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                        Anular
                    </button>
                @endif
            </div>
        </section>

        <section class="admin-card sm:p-7">
            <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
                <h2 class="text-lg font-bold text-[#172B3A]">Información de matrícula</h2>
                <x-admin.badge-estado :estado="$matricula->estado" />
            </div>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Estudiante</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $matricula->estudiante->nombres }} {{ $matricula->estudiante->apellidos }} · DNI {{ $matricula->estudiante->dni }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Curso</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $matricula->curso->nombre }} · {{ $matricula->curso->codigo }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Monto pagado</dt>
                    <dd class="mt-1 font-semibold text-[#172B3A]">{{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Fecha de registro</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $matricula->created_at->format('d/m/Y H:i') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Observaciones</dt>
                    <dd class="mt-1 whitespace-pre-line leading-6 text-[#64748B]">{{ $matricula->observaciones ?: 'Sin observaciones.' }}</dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
