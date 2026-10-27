@extends('admin.layouts.app')

@section('title', 'Detalle de matrícula')
@section('breadcrumb', 'Matrículas / Detalle')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('admin.matriculas.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-stone-500 transition hover:text-ink">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver a matrículas
                </a>
                <h1 class="mt-3 text-2xl font-bold text-ink">Detalle de matrícula #{{ $matricula->id }}</h1>
                <p class="mt-1 text-sm text-stone-600">Registrada el {{ $matricula->fecha_matricula->format('d/m/Y') }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.matriculas.edit', $matricula) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-gold px-4 py-2.5 text-sm font-semibold text-gold-dark transition hover:bg-gold/10">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    Editar
                </a>
                @if ($matricula->estado !== 'anulada')
                    <button
                        type="button"
                        @click="$dispatch('open-confirm-modal', { title: 'Anular matrícula', message: 'Al anular esta matrícula se liberará un cupo del curso.', action: '{{ route('admin.matriculas.anular', $matricula) }}', method: 'PATCH', confirmText: 'Sí, anular' })"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-amber-700"
                    >
                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                        Anular
                    </button>
                @endif
            </div>
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-7">
            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-lg font-bold text-ink">Información de matrícula</h2>
                <x-admin.badge-estado :estado="$matricula->estado" />
            </div>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Estudiante</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $matricula->estudiante->nombres }} {{ $matricula->estudiante->apellidos }} · DNI {{ $matricula->estudiante->dni }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Curso</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $matricula->curso->nombre }} · {{ $matricula->curso->codigo }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Monto pagado</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Fecha de registro</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $matricula->created_at->format('d/m/Y H:i') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Observaciones</dt>
                    <dd class="mt-1 whitespace-pre-line leading-6 text-stone-700">{{ $matricula->observaciones ?: 'Sin observaciones.' }}</dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
