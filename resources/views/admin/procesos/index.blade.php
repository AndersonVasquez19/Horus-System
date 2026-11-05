@extends('admin.layouts.app')

@section('title', 'Procesos internos')
@section('breadcrumb', 'Procesos internos')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Operación interna</p>
                <h1 class="admin-page-title">Procesos internos</h1>
                <p class="admin-page-subtitle">Gestiona tareas, responsables, prioridades y fechas de vencimiento.</p>
            </div>

            @can('procesos.gestionar')
                <a href="{{ route('admin.procesos.create') }}" class="admin-btn-primary">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    Nuevo proceso
                </a>
            @endcan
        </section>

        <section class="admin-card">
            <form method="GET" action="{{ route('admin.procesos.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto] md:items-end">
                <div>
                    <label for="busqueda" class="admin-label">Buscar</label>
                    <input id="busqueda" name="busqueda" type="search" value="{{ $busqueda ?? '' }}" placeholder="Título, descripción o observación" class="admin-input" />
                </div>
                <div>
                    <label for="estado" class="admin-label">Estado</label>
                    <select id="estado" name="estado" class="admin-select">
                        <option value="">Todos</option>
                        <option value="pendiente" {{ ($estado ?? '') === 'pendiente' ? 'selected' : '' }}>Pendiente</option>
                        <option value="en_proceso" {{ ($estado ?? '') === 'en_proceso' ? 'selected' : '' }}>En proceso</option>
                        <option value="completado" {{ ($estado ?? '') === 'completado' ? 'selected' : '' }}>Completado</option>
                        <option value="cancelado" {{ ($estado ?? '') === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                    </select>
                </div>
                <button type="submit" class="admin-btn-secondary">
                    <i class="fa-solid fa-magnifying-glass text-xs text-[#19A7A1]" aria-hidden="true"></i>
                    Filtrar
                </button>
            </form>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            @forelse ($procesos as $proceso)
                <article class="admin-card flex flex-col justify-between transition hover:border-[#287FB5]/40">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="admin-eyebrow">{{ ucfirst(str_replace('_', ' ', $proceso->estado)) }}</p>
                                <h2 class="mt-1.5 text-lg font-bold text-[#172B3A]">{{ $proceso->titulo }}</h2>
                            </div>
                            <span class="shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ match($proceso->prioridad) { 'urgente' => 'border-red-200 bg-red-50 text-red-700', 'alta' => 'border-amber-200 bg-amber-50 text-amber-800', 'media' => 'border-sky-200 bg-sky-50 text-sky-800', default => 'border-[#E2E8F0] bg-[#F4F7FB] text-[#64748B]' } }}">
                                {{ $proceso->prioridad }}
                            </span>
                        </div>

                        <p class="mt-3 text-sm leading-6 text-[#64748B]">{{ $proceso->descripcion ?: 'Sin descripción adicional.' }}</p>

                        <dl class="mt-4 grid gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-3.5 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Responsable</dt>
                                <dd class="mt-0.5 font-medium text-[#172B3A]">{{ $proceso->responsable?->name ?? 'Sin asignar' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Fecha de vencimiento</dt>
                                <dd class="mt-0.5 font-medium text-[#172B3A]">{{ $proceso->fecha_vencimiento ? $proceso->fecha_vencimiento->format('d/m/Y') : 'Sin fecha' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Creado por</dt>
                                <dd class="mt-0.5 font-medium text-[#172B3A]">{{ $proceso->creador?->name ?? 'Sistema' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Observaciones</dt>
                                <dd class="mt-0.5 text-[#64748B]">{{ $proceso->observaciones ?: 'Sin observaciones' }}</dd>
                            </div>
                        </dl>
                    </div>

                    @can('procesos.gestionar')
                        <div class="mt-5 flex flex-wrap gap-2 border-t border-[#E2E8F0] pt-4">
                            <a href="{{ route('admin.procesos.edit', $proceso) }}" class="admin-btn-outline min-h-9 px-3.5 py-1.5 text-xs">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                Editar
                            </a>
                            <form action="{{ route('admin.procesos.destroy', $proceso) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar este proceso?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-btn-danger min-h-9 px-3.5 py-1.5 text-xs">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    Eliminar
                                </button>
                            </form>
                        </div>
                    @endcan
                </article>
            @empty
                <div class="xl:col-span-2 admin-card">
                    <x-admin.empty-state title="No hay procesos" message="No existen procesos internos registrados aún." icon="fa-diagram-project" />
                </div>
            @endforelse
        </section>

        @if ($procesos->hasPages())
            <div class="admin-card px-5 py-4">
                {{ $procesos->links() }}
            </div>
        @endif
    </div>
@endsection
