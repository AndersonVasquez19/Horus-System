@extends('admin.layouts.app')
@section('title', 'Servicios tecnológicos')
@section('breadcrumb', 'Servicios tecnológicos')
@section('content')
@vite('resources/js/admin-servicios.mjs')
<div data-panel-servicios data-endpoint="{{ route('admin.servicios.index', [], false) }}" class="space-y-6">
    <section class="admin-page-header">
        <div>
            <p class="admin-eyebrow">Gestión de servicios</p>
            <h1 class="admin-page-title">Servicios tecnológicos</h1>
            <p class="admin-page-subtitle">Administra servicios y revisa sus fichas editoriales. El catálogo público continúa desactivado.</p>
        </div>
        @can('servicios.crear')
            <button type="button" data-accion="nuevo" class="admin-btn-primary">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                Nuevo servicio
            </button>
        @endcan
    </section>
    <noscript><p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Activa JavaScript para crear o editar servicios. El listado permanece disponible.</p></noscript>
    <div data-mensaje role="status" aria-live="polite" class="break-words text-sm font-medium text-[#176B9A]"></div>
    <p data-listado-pendiente hidden role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Hay cambios posteriores a este listado. Sus filtros, coincidencias y rango corresponden a la consulta anterior. Usa «Todos / Actualizar listado» cuando hayas guardado tus borradores.</p>
    <div data-errores role="alert" tabindex="-1" hidden class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"></div>
    <section class="admin-card-flush" aria-label="Listado de servicios">
        <form method="GET" action="{{ route('admin.servicios.index') }}" class="flex flex-wrap items-end gap-3 border-b border-[#E2E8F0] p-4 sm:p-5">
            <div class="min-w-[220px]">
                <label for="filtro-estado" class="admin-label">Estado administrativo</label>
                <select id="filtro-estado" name="estado" class="admin-select">
                    <option value="" disabled @selected($estado === '')>Selecciona un estado</option>
                    @foreach (['activo' => 'Activo', 'inactivo' => 'Inactivo', 'archivado' => 'Archivado'] as $valor => $texto)
                        <option value="{{ $valor }}" @selected($estado === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="admin-btn-secondary">
                <i class="fa-solid fa-filter text-xs text-[#19A7A1]" aria-hidden="true"></i>
                Filtrar
            </button>
            <a href="{{ route('admin.servicios.index') }}" class="admin-btn-outline">Todos / Actualizar listado</a>
        </form>
        @if ($servicios->isEmpty())
            <p class="p-6 text-sm text-[#64748B]">No hay servicios que coincidan con este listado.</p>
        @else
            <div class="admin-table-wrap" tabindex="0" role="region" aria-label="Tabla de servicios, desplazable horizontalmente">
                <table class="admin-table min-w-[780px]">
                    <caption class="sr-only">Servicios y estados administrativos y editoriales</caption>
                    <thead><tr>
                        <th scope="col">Código / nombre</th><th scope="col">Página pública</th><th scope="col">Estado administrativo</th>
                        <th scope="col">Estado editorial</th><th scope="col" class="text-right">Acciones</th>
                    </tr></thead>
                    <tbody>
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
                                <td class="max-w-xs break-words">
                                    <span class="block text-xs font-semibold text-[#176B9A]">{{ $servicio->codigo }}</span>
                                    <span data-nombre class="mt-0.5 block font-semibold text-[#172B3A]">{{ $servicio->nombre }}</span>
                                </td>
                                <td>
                                    @if ($servicio->pagina_publica)
                                        <span class="inline-flex items-center rounded-full border border-[#19A7A1]/40 bg-[#19A7A1]/10 px-2.5 py-0.5 text-xs font-semibold text-[#176B9A]">{{ $servicio->pagina_publica['etiqueta'] }}</span>
                                        <a href="{{ $servicio->pagina_publica['url'] }}" target="_blank" rel="noopener" class="mt-1 block break-all text-xs text-[#176B9A] underline">{{ $servicio->pagina_publica['url'] }}</a>
                                    @else
                                        <span class="text-xs text-[#64748B]">Sin página propia</span>
                                    @endif
                                </td>
                                <td data-estado class="font-medium text-[#172B3A]">{{ ucfirst($servicio->estado) }}</td>
                                <td data-editorial>{{ ucfirst(str_replace('_', ' ', $editorial)) }}</td>
                                <td class="text-right"><button type="button" data-servicio-id="{{ $servicio->id }}" class="admin-btn-outline px-3.5 py-2 text-xs" aria-label="Revisar servicio {{ $servicio->codigo }}">Revisar</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="space-y-3 border-t border-[#E2E8F0] p-4 text-sm text-[#64748B] sm:p-5">
            <p>{{ $servicios->total() }} coincidencias; mostrando {{ $servicios->firstItem() ?? 0 }}–{{ $servicios->lastItem() ?? 0 }}. Orden: identificador interno ascendente.</p>
            {{ $servicios->links() }}
        </div>
    </section>
    @include('admin.servicios._editor')
</div>
@endsection
