@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Editar proceso')
@section('breadcrumb', 'Procesos internos')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Operación</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Editar proceso interno</h1>
            </div>
            <a href="{{ route('admin.procesos.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-stone-200 px-4 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-100">
                <i class="fa-solid fa-arrow-left"></i>
                Volver
            </a>
        </div>

        <form action="{{ route('admin.procesos.update', $proceso) }}" method="POST" class="rounded-2xl border border-gold/20 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')
            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="titulo" class="mb-1.5 block text-sm font-medium text-stone-700">Título</label>
                    <input id="titulo" name="titulo" type="text" value="{{ OldInput::value('titulo', $proceso->titulo) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" required />
                </div>

                <div class="md:col-span-2">
                    <label for="descripcion" class="mb-1.5 block text-sm font-medium text-stone-700">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="4" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20">{{ OldInput::value('descripcion', $proceso->descripcion) }}</textarea>
                </div>

                <div>
                    <label for="responsable_id" class="mb-1.5 block text-sm font-medium text-stone-700">Responsable</label>
                    <select id="responsable_id" name="responsable_id" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20">
                        <option value="">Sin asignar</option>
                        @foreach ($responsables as $responsable)
                            <option value="{{ $responsable->id }}" {{ old('responsable_id', $proceso->responsable_id) == $responsable->id ? 'selected' : '' }}>{{ $responsable->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="estado" class="mb-1.5 block text-sm font-medium text-stone-700">Estado</label>
                    <select id="estado" name="estado" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" required>
                        <option value="pendiente" {{ old('estado', $proceso->estado) === 'pendiente' ? 'selected' : '' }}>Pendiente</option>
                        <option value="en_proceso" {{ old('estado', $proceso->estado) === 'en_proceso' ? 'selected' : '' }}>En proceso</option>
                        <option value="completado" {{ old('estado', $proceso->estado) === 'completado' ? 'selected' : '' }}>Completado</option>
                        <option value="cancelado" {{ old('estado', $proceso->estado) === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                    </select>
                </div>

                <div>
                    <label for="prioridad" class="mb-1.5 block text-sm font-medium text-stone-700">Prioridad</label>
                    <select id="prioridad" name="prioridad" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" required>
                        <option value="baja" {{ old('prioridad', $proceso->prioridad) === 'baja' ? 'selected' : '' }}>Baja</option>
                        <option value="media" {{ old('prioridad', $proceso->prioridad) === 'media' ? 'selected' : '' }}>Media</option>
                        <option value="alta" {{ old('prioridad', $proceso->prioridad) === 'alta' ? 'selected' : '' }}>Alta</option>
                        <option value="urgente" {{ old('prioridad', $proceso->prioridad) === 'urgente' ? 'selected' : '' }}>Urgente</option>
                    </select>
                </div>

                <div>
                    <label for="fecha_vencimiento" class="mb-1.5 block text-sm font-medium text-stone-700">Fecha de vencimiento</label>
                    <input id="fecha_vencimiento" name="fecha_vencimiento" type="date" value="{{ OldInput::value('fecha_vencimiento', $proceso->fecha_vencimiento?->format('Y-m-d')) }}" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>

                <div class="md:col-span-2">
                    <label for="observaciones" class="mb-1.5 block text-sm font-medium text-stone-700">Observaciones</label>
                    <textarea id="observaciones" name="observaciones" rows="3" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20">{{ OldInput::value('observaciones', $proceso->observaciones) }}</textarea>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Actualizar proceso
                </button>
            </div>
        </form>
    </div>
@endsection
