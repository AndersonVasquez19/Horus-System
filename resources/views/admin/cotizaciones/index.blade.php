@extends('admin.layouts.app')
@section('title', 'Solicitudes de cotización')
@section('breadcrumb', 'Solicitudes de cotización')
@section('content')
@vite('resources/js/admin-cotizaciones.mjs')
<div data-panel-cotizaciones data-endpoint="{{ route('admin.cotizaciones.index', [], false) }}" data-crear="{{ auth()->user()->can('cotizaciones.crear') ? '1' : '0' }}" data-editar="{{ auth()->user()->can('cotizaciones.editar') ? '1' : '0' }}" data-gestionar="{{ auth()->user()->can('cotizaciones.gestionar') ? '1' : '0' }}" class="min-w-0 space-y-6">
    <section class="flex flex-wrap items-end justify-between gap-4">
        <div><h1 class="text-2xl font-bold">Solicitudes de cotización</h1>
            <p class="mt-2 text-sm text-stone-600">Expedientes privados de consulta comercial. No constituyen presupuestos aprobados.</p></div>
        @can('cotizaciones.crear')
            <button type="button" data-accion="nuevo" class="min-h-11 rounded-xl bg-gold px-5 py-3 font-semibold">Nueva solicitud</button>
        @endcan
    </section>
    <noscript><p role="alert">Activa JavaScript para filtrar, consultar y gestionar solicitudes. El listado permanece disponible.</p></noscript>
    <div data-mensaje role="status" aria-live="polite" class="break-words text-sm"></div>
    <div data-errores role="alert" tabindex="-1" hidden class="break-words rounded-xl border border-red-300 bg-red-50 p-4 text-red-800"></div>
    <p data-listado-pendiente hidden class="rounded-xl bg-amber-50 p-4 text-sm">Hay cambios posteriores a este listado. Guarda tus borradores antes de actualizar sus filtros y recuentos.</p>
    <section class="min-w-0 overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm" aria-label="Listado de solicitudes">
        <form data-filtros action="{{ route('admin.cotizaciones.index') }}" class="grid gap-3 border-b border-stone-100 p-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><label for="f-estado" class="block text-sm font-semibold">Estado</label><select id="f-estado" name="estado" class="mt-1 min-h-11 w-full rounded-xl border border-stone-300 p-2">
                <option value="">Todos</option><option value="recibida" @selected(($filtros['estado'] ?? '') === 'recibida')>Recibida</option><option value="en_revision" @selected(($filtros['estado'] ?? '') === 'en_revision')>En revisión</option>
            </select></div>
            @foreach (['responsable_id' => 'ID de responsable', 'servicio_uuid' => 'UUID del servicio', 'codigo' => 'Código de seguimiento'] as $campo => $etiqueta)
                <div><label for="f-{{ $campo }}" class="block text-sm font-semibold">{{ $etiqueta }}</label><input id="f-{{ $campo }}" name="{{ $campo }}" value="{{ $filtros[$campo] ?? '' }}" maxlength="40" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-stone-300 p-2"></div>
            @endforeach
            @foreach (['recibida_desde' => 'Recibida desde (Lima)', 'recibida_hasta' => 'Recibida hasta (Lima)'] as $campo => $etiqueta)
                <div><label for="f-{{ $campo }}" class="block text-sm font-semibold">{{ $etiqueta }}</label><input type="date" id="f-{{ $campo }}" name="{{ $campo }}" value="{{ $filtros[$campo] ?? '' }}" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-stone-300 p-2"></div>
            @endforeach
            <button type="submit" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2 font-semibold">Filtrar</button>
            <a href="{{ route('admin.cotizaciones.index') }}" class="inline-flex min-h-11 items-center underline">Todos / Actualizar listado</a>
        </form>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tabla de solicitudes, desplazable horizontalmente">
            <table class="w-full min-w-[700px] text-left text-sm"><caption class="sr-only">Solicitudes sin datos personales de contacto</caption>
                <thead class="bg-stone-50"><tr>@foreach (['Código / recepción', 'Estado', 'Servicio histórico', 'Responsable', 'Acción'] as $columna)<th scope="col" class="px-5 py-3">{{ $columna }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-stone-100">
                @forelse ($solicitudes as $solicitud)
                    <tr><td class="max-w-xs break-words px-5 py-3"><span class="block">{{ $solicitud->codigo }}</span><span class="text-xs text-stone-500">{{ $solicitud->recibida_at->timezone('America/Lima')->format('d/m/Y H:i') }} Lima</span></td>
                        <td class="px-5 py-3">{{ $solicitud->estado === 'recibida' ? 'Recibida' : 'En revisión' }}</td>
                        <td class="max-w-xs break-words px-5 py-3">{{ $solicitud->servicio_nombre_historico ?? 'Sin asociación' }}</td>
                        <td class="px-5 py-3">{{ $solicitud->responsable_id ? 'ID '.$solicitud->responsable_id : 'Sin asignar' }}</td>
                        <td class="px-5 py-3"><button type="button" data-abrir="{{ $solicitud->identificador }}" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2" aria-label="Consultar {{ $solicitud->codigo }}">Consultar</button></td></tr>
                @empty
                    <tr><td colspan="5" class="p-6">No hay solicitudes que coincidan.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-3 border-t border-stone-100 p-5 text-sm"><p>{{ $solicitudes->total() }} coincidencias; mostrando {{ $solicitudes->firstItem() ?? 0 }}–{{ $solicitudes->lastItem() ?? 0 }}. Orden de registro ascendente.</p>{{ $solicitudes->links() }}</div>
    </section>
    @include('admin.cotizaciones._editor')
</div>
@endsection
