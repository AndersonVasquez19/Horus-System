@extends('admin.layouts.app')

@section('title', 'Detalle del curso')
@section('breadcrumb', 'Cursos / Detalle')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <a href="{{ route('admin.cursos.index') }}" class="admin-back-link">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver a cursos
                </a>
                <p class="mt-3 admin-eyebrow">{{ $curso->codigo }}</p>
                <h1 class="admin-page-title">{{ $curso->nombre }}</h1>
            </div>
            <div class="flex flex-wrap gap-3">
                @can('cursos.publicar')
                    <a href="{{ route('admin.cursos.publicacion.edit', $curso) }}" class="admin-btn-outline">
                        <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
                        Publicación web
                    </a>
                @endcan
                @can('matriculas.crear')
                    @if ($curso->estado === 'activo' && $curso->cupos_disponibles > 0)
                        <a href="{{ route('admin.matriculas.create', ['curso_id' => $curso->id]) }}" class="admin-btn-primary">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            Nueva matrícula
                        </a>
                    @endif
                @endcan
                <a href="{{ route('admin.cursos.edit', $curso) }}" class="admin-btn-outline">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    Editar
                </a>
                <button
                    type="button"
                    @click="$dispatch('open-confirm-modal', { title: 'Desactivar curso', message: 'El curso se ocultará de la lista principal. Puedes restaurarlo más adelante.', action: '{{ route('admin.cursos.destroy', $curso) }}', method: 'DELETE', confirmText: 'Sí, desactivar' })"
                    class="admin-btn-danger"
                >
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    Desactivar
                </button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat-card title="Precio" :value="'S/ '.number_format((float) $curso->precio, 2, '.', ',')" icon="fa-tag" variant="blue" />
            <x-admin.stat-card title="Duración" :value="$curso->duracion_horas.' horas'" icon="fa-clock" variant="teal" />
            <x-admin.stat-card title="Cupos disponibles" :value="$curso->cupos_disponibles.' / '.$curso->cupos_maximos" icon="fa-users" variant="sky" />
            <x-admin.stat-card title="Estudiantes matriculados" :value="$curso->estudiantes->count()" icon="fa-user-graduate" variant="navy" />
        </section>

        <section class="admin-card sm:p-7">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3 border-b border-[#E2E8F0] pb-4">
                <h2 class="text-lg font-bold text-[#172B3A]">Información del curso</h2>
                <x-admin.badge-estado :estado="$curso->estado" />
            </div>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Categoría</dt>
                    <dd class="mt-1 capitalize font-medium text-[#172B3A]">{{ str_replace('_', ' ', $curso->categoria) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Modalidad</dt>
                    <dd class="mt-1 capitalize font-medium text-[#172B3A]">{{ $curso->modalidad }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Horario</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $curso->horario }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Descripción</dt>
                    <dd class="mt-1 whitespace-pre-line leading-6 text-[#64748B]">{{ $curso->descripcion }}</dd>
                </div>
            </dl>
        </section>

        <section class="admin-card-flush">
            <div class="border-b border-[#E2E8F0] px-5 py-4 sm:px-6">
                <h2 class="font-bold text-[#172B3A]">Estudiantes matriculados</h2>
                <p class="mt-1 text-sm text-[#64748B]">{{ $curso->estudiantes->count() }} matrículas activas</p>
            </div>

            @if ($curso->estudiantes->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-admin.empty-state title="Sin estudiantes matriculados" message="Los estudiantes inscritos en este curso aparecerán aquí." icon="fa-user-graduate" />
                </div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[600px]">
                        <thead>
                            <tr>
                                <th scope="col">DNI</th>
                                <th scope="col">Estudiante</th>
                                <th scope="col">Fecha de matrícula</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($curso->estudiantes as $estudiante)
                                <tr>
                                    <td class="font-medium text-[#172B3A]">{{ $estudiante->dni }}</td>
                                    <td class="text-[#172B3A]">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</td>
                                    <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($estudiante->pivot->fecha_matricula)->format('d/m/Y') }}</td>
                                    <td><x-admin.badge-estado :estado="$estudiante->pivot->estado" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
