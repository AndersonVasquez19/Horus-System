@extends('admin.layouts.app')
@section('title', 'Servicios tecnológicos')
@section('breadcrumb', 'Servicios tecnológicos')
@section('content')
@vite('resources/js/admin-servicios.mjs')
<div data-panel-servicios data-endpoint="{{ route('admin.servicios.index', [], false) }}" class="space-y-6">
    <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">Gestión de servicios</p>
            <h1 class="mt-1 text-2xl font-bold text-ink">Servicios tecnológicos</h1>
            <p class="mt-1 text-sm text-stone-600">Administra servicios y revisa sus fichas editoriales. El catálogo público continúa desactivado.</p>
        </div>
        @can('servicios.crear')
            <button type="button" data-accion="nuevo" class="min-h-11 rounded-xl bg-gold px-5 py-3 font-semibold text-ink">Nuevo servicio</button>
        @endcan
    </section>
    <noscript><p role="alert">Activa JavaScript para crear o editar servicios. El listado permanece disponible.</p></noscript>
    <div data-mensaje role="status" aria-live="polite" class="break-words text-sm text-stone-700"></div>
    <p data-listado-pendiente hidden role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm">Hay cambios posteriores a este listado. Sus filtros, coincidencias y rango corresponden a la consulta anterior. Usa «Todos / Actualizar listado» cuando hayas guardado tus borradores.</p>
    <div data-errores role="alert" tabindex="-1" hidden class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-800"></div>
    <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm" aria-label="Listado de servicios">
        <form method="GET" action="{{ route('admin.servicios.index') }}" class="flex flex-wrap items-end gap-3 border-b border-stone-100 p-5">
            <div><label for="filtro-estado" class="mb-2 block text-sm font-semibold">Estado administrativo</label>
                <select id="filtro-estado" name="estado" class="min-h-11 rounded-xl border border-stone-300 p-2">
                    <option value="" disabled @selected($estado === '')>Selecciona un estado</option>
                    @foreach (['activo' => 'Activo', 'inactivo' => 'Inactivo', 'archivado' => 'Archivado'] as $valor => $texto)
                        <option value="{{ $valor }}" @selected($estado === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2 font-semibold">Filtrar</button>
            <a href="{{ route('admin.servicios.index') }}" class="inline-flex min-h-11 items-center px-2 underline">Todos / Actualizar listado</a>
        </form>
        @if ($servicios->isEmpty())
            <p class="p-6 text-stone-600">No hay servicios que coincidan con este listado.</p>
        @else
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tabla de servicios, desplazable horizontalmente">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <caption class="sr-only">Servicios y estados administrativos y editoriales</caption>
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500"><tr>
                        <th scope="col" class="px-5 py-3">Código / nombre</th><th scope="col" class="px-5 py-3">Estado administrativo</th>
                        <th scope="col" class="px-5 py-3">Estado editorial</th><th scope="col" class="px-5 py-3">Acciones</th>
                    </tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($servicios as $servicio)
                            @php
                                $ficha = $servicio->publicacion;
                                $editorial = $ficha?->estado ?? 'sin_ficha';
                                if ($editorial === 'publicada') {
                                    $hoy = now('America/Lima')->toDateString();
                                    $editorial = $ficha->vigente_hasta->toDateString() < $hoy ? 'caducada' : ($ficha->vigente_desde->toDateString() > $hoy ? 'programada' : 'publicada');
                                }
                            @endphp
                            <tr data-fila-servicio="{{ $servicio->id }}">
                                <td class="max-w-xs break-words px-5 py-3"><span class="block text-xs text-stone-500">{{ $servicio->codigo }}</span><span data-nombre>{{ $servicio->nombre }}</span></td>
                                <td data-estado class="px-5 py-3">{{ ucfirst($servicio->estado) }}</td>
                                <td data-editorial class="px-5 py-3">{{ ucfirst(str_replace('_', ' ', $editorial)) }}</td>
                                <td class="px-5 py-3"><button type="button" data-servicio-id="{{ $servicio->id }}" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2 font-semibold" aria-label="Revisar servicio {{ $servicio->codigo }}">Revisar</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="space-y-3 border-t border-stone-100 p-5 text-sm">
            <p>{{ $servicios->total() }} coincidencias; mostrando {{ $servicios->firstItem() ?? 0 }}–{{ $servicios->lastItem() ?? 0 }}. Orden: identificador interno ascendente.</p>
            {{ $servicios->links() }}
        </div>
    </section>
    @include('admin.servicios._editor')
</div>
@endsection
