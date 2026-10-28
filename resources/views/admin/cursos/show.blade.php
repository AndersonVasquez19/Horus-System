@extends('admin.layouts.app')

@section('title', 'Detalle del curso')
@section('breadcrumb', 'Cursos / Detalle')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('admin.cursos.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-stone-500 transition hover:text-ink">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver a cursos
                </a>
                <p class="mt-3 text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">{{ $curso->codigo }}</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">{{ $curso->nombre }}</h1>
            </div>
            <div class="flex flex-wrap gap-3">
                @can('matriculas.crear')
                    @if ($curso->estado === 'activo' && $curso->cupos_disponibles > 0)
                        <a href="{{ route('admin.matriculas.create', ['curso_id' => $curso->id]) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-gold-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-dark">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            Nueva matrícula
                        </a>
                    @endif
                @endcan
                <a href="{{ route('admin.cursos.edit', $curso) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-gold px-4 py-2.5 text-sm font-semibold text-gold-dark transition hover:bg-gold/10">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    Editar
                </a>
                <button
                    type="button"
                    @click="$dispatch('open-confirm-modal', { title: 'Desactivar curso', message: 'El curso se ocultará de la lista principal. Puedes restaurarlo más adelante.', action: '{{ route('admin.cursos.destroy', $curso) }}', method: 'DELETE', confirmText: 'Sí, desactivar' })"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700"
                >
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    Desactivar
                </button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat-card title="Precio" :value="'S/ '.number_format((float) $curso->precio, 2, '.', ',')" icon="fa-tag" />
            <x-admin.stat-card title="Duración" :value="$curso->duracion_horas.' horas'" icon="fa-clock" />
            <x-admin.stat-card title="Cupos disponibles" :value="$curso->cupos_disponibles.' / '.$curso->cupos_maximos" icon="fa-users" />
            <x-admin.stat-card title="Estudiantes matriculados" :value="$curso->estudiantes->count()" icon="fa-user-graduate" />
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-7">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold text-ink">Información del curso</h2>
                <x-admin.badge-estado :estado="$curso->estado" />
            </div>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Categoría</dt>
                    <dd class="mt-1 capitalize font-medium text-ink">{{ str_replace('_', ' ', $curso->categoria) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Modalidad</dt>
                    <dd class="mt-1 capitalize font-medium text-ink">{{ $curso->modalidad }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Horario</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $curso->horario }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Descripción</dt>
                    <dd class="mt-1 whitespace-pre-line leading-6 text-stone-700">{{ $curso->descripcion }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="border-b border-stone-100 px-5 py-4">
                <h2 class="font-bold text-ink">Estudiantes matriculados</h2>
                <p class="mt-1 text-sm text-stone-500">{{ $curso->estudiantes->count() }} matrículas activas</p>
            </div>

            @if ($curso->estudiantes->isEmpty())
                <div class="p-5">
                    <x-admin.empty-state title="Sin estudiantes matriculados" message="Los estudiantes inscritos en este curso aparecerán aquí." icon="fa-user-graduate" />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[600px] text-left text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">DNI</th>
                                <th class="px-5 py-3 font-semibold">Estudiante</th>
                                <th class="px-5 py-3 font-semibold">Fecha de matrícula</th>
                                <th class="px-5 py-3 font-semibold">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($curso->estudiantes as $estudiante)
                                <tr>
                                    <td class="px-5 py-4 font-medium text-ink">{{ $estudiante->dni }}</td>
                                    <td class="px-5 py-4 text-stone-600">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-stone-600">{{ \Carbon\Carbon::parse($estudiante->pivot->fecha_matricula)->format('d/m/Y') }}</td>
                                    <td class="px-5 py-4"><x-admin.badge-estado :estado="$estudiante->pivot->estado" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
