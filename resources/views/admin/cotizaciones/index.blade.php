@extends('admin.layouts.app')
@section('title', 'Solicitudes de cotización')
@section('breadcrumb', 'Solicitudes de cotización')
@section('content')
@vite('resources/js/admin-cotizaciones.mjs')
<div data-panel-cotizaciones data-endpoint="{{ route('admin.cotizaciones.index', [], false) }}" data-crear="{{ auth()->user()->can('cotizaciones.crear') ? '1' : '0' }}" data-editar="{{ auth()->user()->can('cotizaciones.editar') ? '1' : '0' }}" data-gestionar="{{ auth()->user()->can('cotizaciones.gestionar') ? '1' : '0' }}" class="min-w-0 space-y-6">
    <section class="admin-page-header">
        <div>
            <p class="admin-eyebrow">Comercial y atención</p>
            <h1 class="admin-page-title">Solicitudes de cotización</h1>
            <p class="admin-page-subtitle">Expedientes privados de consulta comercial. No constituyen presupuestos aprobados.</p>
        </div>
        @can('cotizaciones.crear')
            <button type="button" data-accion="nuevo" class="admin-btn-primary">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                Nueva solicitud
            </button>
        @endcan
    </section>
    <noscript><p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Activa JavaScript para filtrar, consultar y gestionar solicitudes. El listado permanece disponible.</p></noscript>
    <div data-mensaje role="status" aria-live="polite" class="break-words text-sm font-medium text-[#176B9A]"></div>
    <div data-errores role="alert" tabindex="-1" hidden class="break-words rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"></div>
    <p data-listado-pendiente hidden class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Hay cambios posteriores a este listado. Guarda tus borradores antes de actualizar sus filtros y recuentos.</p>
    <section class="min-w-0 admin-card-flush" aria-label="Listado de solicitudes">
        <form data-filtros action="{{ route('admin.cotizaciones.index') }}" class="grid gap-3 border-b border-[#E2E8F0] p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-3">
            <div><label for="f-estado" class="admin-label">Estado</label><select id="f-estado" name="estado" class="admin-select">
                <option value="">Todos</option><option value="recibida" @selected(($filtros['estado'] ?? '') === 'recibida')>Recibida</option><option value="en_revision" @selected(($filtros['estado'] ?? '') === 'en_revision')>En revisión</option>
            </select></div>
            @foreach (['responsable_id' => 'ID de responsable', 'servicio_uuid' => 'UUID del servicio', 'codigo' => 'Código de seguimiento'] as $campo => $etiqueta)
                <div><label for="f-{{ $campo }}" class="admin-label">{{ $etiqueta }}</label><input id="f-{{ $campo }}" name="{{ $campo }}" value="{{ $filtros[$campo] ?? '' }}" maxlength="40" class="admin-input min-w-0"></div>
            @endforeach
            @foreach (['recibida_desde' => 'Recibida desde (Lima)', 'recibida_hasta' => 'Recibida hasta (Lima)'] as $campo => $etiqueta)
                <div><label for="f-{{ $campo }}" class="admin-label">{{ $etiqueta }}</label><input type="date" id="f-{{ $campo }}" name="{{ $campo }}" value="{{ $filtros[$campo] ?? '' }}" class="admin-input min-w-0"></div>
            @endforeach
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-3">
                <button type="submit" class="admin-btn-secondary">
                    <i class="fa-solid fa-filter text-xs text-[#19A7A1]" aria-hidden="true"></i>
                    Filtrar
                </button>
                <a href="{{ route('admin.cotizaciones.index') }}" class="admin-btn-outline">Todos / Actualizar listado</a>
            </div>
        </form>
        <div class="admin-table-wrap" tabindex="0" role="region" aria-label="Tabla de solicitudes, desplazable horizontalmente">
            <table class="admin-table min-w-[700px]"><caption class="sr-only">Solicitudes sin datos personales de contacto</caption>
                <thead><tr>@foreach (['Código / recepción', 'Estado', 'Servicio histórico', 'Responsable', 'Acción'] as $columna)<th scope="col">{{ $columna }}</th>@endforeach</tr></thead>
                <tbody>
                @forelse ($solicitudes as $solicitud)
                    <tr><td class="max-w-xs break-words"><span class="block font-semibold text-[#172B3A]">{{ $solicitud->codigo }}</span><span class="mt-0.5 block text-xs text-[#64748B]">{{ $solicitud->recibida_at->timezone('America/Lima')->format('d/m/Y H:i') }} Lima</span></td>
                        <td class="font-medium text-[#172B3A]"><x-admin.badge-estado :estado="$solicitud->estado" /></td>
                        <td class="max-w-xs break-words">{{ $solicitud->servicio_nombre_historico ?? 'Sin asociación' }}</td>
                        <td>{{ $solicitud->responsable_id ? 'ID '.$solicitud->responsable_id : 'Sin asignar' }}</td>
                        <td><button type="button" data-abrir="{{ $solicitud->identificador }}" class="admin-btn-outline px-3.5 py-2 text-xs" aria-label="Consultar {{ $solicitud->codigo }}">Consultar</button></td></tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center text-[#64748B]">No hay solicitudes que coincidan.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-3 border-t border-[#E2E8F0] p-4 text-sm text-[#64748B] sm:p-5"><p>{{ $solicitudes->total() }} coincidencias; mostrando {{ $solicitudes->firstItem() ?? 0 }}–{{ $solicitudes->lastItem() ?? 0 }}. Orden de registro ascendente.</p>{{ $solicitudes->links() }}</div>
    </section>
    @include('admin.cotizaciones._editor')
</div>
@endsection
