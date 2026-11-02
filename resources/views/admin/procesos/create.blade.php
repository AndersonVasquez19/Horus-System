@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Nuevo proceso')
@section('breadcrumb', 'Procesos internos / Crear')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Operación interna</p>
                <h1 class="admin-page-title">Nuevo proceso interno</h1>
            </div>
            <a href="{{ route('admin.procesos.index') }}" class="admin-btn-outline">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver
            </a>
        </div>

        <form action="{{ route('admin.procesos.store') }}" method="POST" class="admin-card sm:p-8">
            @csrf
            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="titulo" class="admin-label">Título <span class="text-red-600">*</span></label>
                    <input id="titulo" name="titulo" type="text" value="{{ OldInput::value('titulo') }}" class="admin-input" required />
                    @error('titulo')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="descripcion" class="admin-label">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="4" class="admin-textarea">{{ OldInput::value('descripcion') }}</textarea>
                </div>

                <div>
                    <label for="responsable_id" class="admin-label">Responsable</label>
                    <select id="responsable_id" name="responsable_id" class="admin-select">
                        <option value="">Sin asignar</option>
                        @foreach ($responsables as $responsable)
                            <option value="{{ $responsable->id }}" {{ old('responsable_id') == $responsable->id ? 'selected' : '' }}>{{ $responsable->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="estado" class="admin-label">Estado <span class="text-red-600">*</span></label>
                    <select id="estado" name="estado" class="admin-select" required>
                        <option value="pendiente" {{ old('estado') === 'pendiente' ? 'selected' : '' }}>Pendiente</option>
                        <option value="en_proceso" {{ old('estado') === 'en_proceso' ? 'selected' : '' }}>En proceso</option>
                        <option value="completado" {{ old('estado') === 'completado' ? 'selected' : '' }}>Completado</option>
                        <option value="cancelado" {{ old('estado') === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                    </select>
                </div>

                <div>
                    <label for="prioridad" class="admin-label">Prioridad <span class="text-red-600">*</span></label>
                    <select id="prioridad" name="prioridad" class="admin-select" required>
                        <option value="baja" {{ old('prioridad') === 'baja' ? 'selected' : '' }}>Baja</option>
                        <option value="media" {{ old('prioridad') === 'media' ? 'selected' : '' }}>Media</option>
                        <option value="alta" {{ old('prioridad') === 'alta' ? 'selected' : '' }}>Alta</option>
                        <option value="urgente" {{ old('prioridad') === 'urgente' ? 'selected' : '' }}>Urgente</option>
                    </select>
                </div>

                <div>
                    <label for="fecha_vencimiento" class="admin-label">Fecha de vencimiento</label>
                    <input id="fecha_vencimiento" name="fecha_vencimiento" type="date" value="{{ OldInput::value('fecha_vencimiento') }}" class="admin-input" />
                </div>

                <div class="md:col-span-2">
                    <label for="observaciones" class="admin-label">Observaciones</label>
                    <textarea id="observaciones" name="observaciones" rows="3" class="admin-textarea">{{ OldInput::value('observaciones') }}</textarea>
                </div>
            </div>

            <div class="mt-6 flex justify-end border-t border-[#E2E8F0] pt-5">
                <button type="submit" class="admin-btn-primary">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    Guardar proceso
                </button>
            </div>
        </form>
    </div>
@endsection
