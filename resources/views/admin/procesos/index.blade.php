@extends('admin.layouts.app')

@section('title', 'Procesos internos')
@section('breadcrumb', 'Procesos internos')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Operación</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Procesos internos</h1>
            </div>

            @can('procesos.gestionar')
                <a href="{{ route('admin.procesos.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-plus"></i>
                    Nuevo proceso
                </a>
            @endcan
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-4 shadow-sm sm:p-5">
            <form method="GET" action="{{ route('admin.procesos.index') }}" class="grid gap-3 md:grid-cols-[1fr_220px_140px] md:items-end">
                <div>
                    <label for="busqueda" class="mb-1 block text-sm font-medium text-stone-700">Buscar</label>
                    <input id="busqueda" name="busqueda" type="search" value="{{ $busqueda ?? '' }}" placeholder="Título, descripción o observación" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
                <div>
                    <label for="estado" class="mb-1 block text-sm font-medium text-stone-700">Estado</label>
                    <select id="estado" name="estado" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20">
                        <option value="">Todos</option>
                        <option value="pendiente" {{ ($estado ?? '') === 'pendiente' ? 'selected' : '' }}>Pendiente</option>
                        <option value="en_proceso" {{ ($estado ?? '') === 'en_proceso' ? 'selected' : '' }}>En proceso</option>
                        <option value="completado" {{ ($estado ?? '') === 'completado' ? 'selected' : '' }}>Completado</option>
                        <option value="cancelado" {{ ($estado ?? '') === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                    </select>
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink hover:bg-gold-dark">
                    <i class="fa-solid fa-magnifying-glass mr-2"></i>
                    Filtrar
                </button>
            </form>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            @forelse ($procesos as $proceso)
                <article class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gold-dark">{{ ucfirst(str_replace('_', ' ', $proceso->estado)) }}</p>
                            <h2 class="mt-2 text-xl font-bold text-ink">{{ $proceso->titulo }}</h2>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ match($proceso->prioridad) { 'urgente' => 'bg-red-100 text-red-700', 'alta' => 'bg-amber-100 text-amber-700', 'media' => 'bg-sky-100 text-sky-700', default => 'bg-stone-100 text-stone-700' } }}">
                            {{ $proceso->prioridad }}
                        </span>
                    </div>

                    <p class="mt-3 text-sm leading-6 text-stone-600">{{ $proceso->descripcion ?: 'Sin descripción adicional.' }}</p>

                    <dl class="mt-4 grid gap-3 text-sm text-stone-600 sm:grid-cols-2">
                        <div>
                            <dt class="font-semibold text-stone-500">Responsable</dt>
                            <dd>{{ $proceso->responsable?->name ?? 'Sin asignar' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-500">Fecha de vencimiento</dt>
                            <dd>{{ $proceso->fecha_vencimiento ? $proceso->fecha_vencimiento->format('d/m/Y') : 'Sin fecha' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-500">Creado por</dt>
                            <dd>{{ $proceso->creador?->name ?? 'Sistema' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-500">Observaciones</dt>
                            <dd>{{ $proceso->observaciones ?: 'Sin observaciones' }}</dd>
                        </div>
                    </dl>

                    @can('procesos.gestionar')
                        <div class="mt-5 flex flex-wrap gap-2">
                            <a href="{{ route('admin.procesos.edit', $proceso) }}" class="inline-flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100">
                                <i class="fa-solid fa-pen"></i>
                                Editar
                            </a>
                            <form action="{{ route('admin.procesos.destroy', $proceso) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar este proceso?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                    <i class="fa-solid fa-trash"></i>
                                    Eliminar
                                </button>
                            </form>
                        </div>
                    @endcan
                </article>
            @empty
                <div class="xl:col-span-2">
                    <x-admin.empty-state title="No hay procesos" message="No existen procesos internos registrados aún." icon="fa-diagram-project" />
                </div>
            @endforelse
        </section>

        @if ($procesos->hasPages())
            <div class="rounded-2xl border border-gold/20 bg-white px-5 py-3 shadow-sm">
                {{ $procesos->links() }}
            </div>
        @endif
    </div>
@endsection
