@extends('admin.layouts.app')

@section('title', 'Libro de Reclamaciones')
@section('breadcrumb', 'Libro de Reclamaciones')

@section('content')
<div class="space-y-6">
    <section class="admin-page-header">
        <div>
            <p class="admin-eyebrow">Atención al ciudadano y cumplimiento legal</p>
            <h1 class="admin-page-title">Libro de Reclamaciones</h1>
            <p class="admin-page-subtitle">Bandeja de recepción y consulta de quejas y reclamos ingresados por consumidores.</p>
        </div>
    </section>

    {{-- Indicadores de cantidad --}}
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Resumen de reclamaciones">
        <x-admin.stat-card
            title="Total registros"
            :value="$metricas['total']"
            icon="fa-book-bookmark"
            variant="navy"
            description="Hojas registradas en total"
        />
        <x-admin.stat-card
            title="Quejas"
            :value="$metricas['quejas']"
            icon="fa-triangle-exclamation"
            variant="sky"
            description="Disconformidades con atención"
        />
        <x-admin.stat-card
            title="Reclamos"
            :value="$metricas['reclamos']"
            icon="fa-circle-exclamation"
            variant="teal"
            description="Bienes o servicios contratados"
        />
        <x-admin.stat-card
            title="Abiertos"
            :value="$metricas['abiertos']"
            icon="fa-clock"
            variant="blue"
            description="Pendientes de atención legal"
        />
    </section>

    {{-- Bandeja de registros con filtros --}}
    <section class="admin-card-flush" aria-label="Bandeja de reclamaciones">
        <form method="GET" action="{{ route('admin.reclamaciones.index') }}" class="grid gap-3 border-b border-[#E2E8F0] p-4 sm:p-5 md:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-2">
                <x-admin.search-input
                    name="busqueda"
                    :value="$filtros['busqueda'] ?? ''"
                    placeholder="Buscar por número o nombre..."
                />
            </div>
            <div>
                <label class="block">
                    <span class="sr-only">Tipo de registro</span>
                    <select name="tipo" class="admin-select">
                        <option value="">Todos los tipos</option>
                        <option value="queja" @selected(($filtros['tipo'] ?? '') === 'queja')>Queja</option>
                        <option value="reclamo" @selected(($filtros['tipo'] ?? '') === 'reclamo')>Reclamo</option>
                    </select>
                </label>
            </div>
            <div>
                <label class="block">
                    <span class="sr-only">Estado</span>
                    <select name="estado" class="admin-select">
                        <option value="">Todos los estados</option>
                        <option value="abierto" @selected(($filtros['estado'] ?? '') === 'abierto')>Abierto</option>
                        <option value="respondido" @selected(($filtros['estado'] ?? '') === 'respondido')>Respondido</option>
                        <option value="concluido_por_acuerdo" @selected(($filtros['estado'] ?? '') === 'concluido_por_acuerdo')>Concluido por acuerdo</option>
                    </select>
                </label>
            </div>
            <div>
                <label class="block">
                    <span class="sr-only">Fecha desde</span>
                    <input
                        type="date"
                        name="desde"
                        value="{{ $filtros['desde'] ?? '' }}"
                        placeholder="Desde"
                        class="admin-input"
                        title="Fecha de presentación desde"
                    >
                </label>
            </div>
            <div>
                <label class="block">
                    <span class="sr-only">Fecha hasta</span>
                    <input
                        type="date"
                        name="hasta"
                        value="{{ $filtros['hasta'] ?? '' }}"
                        placeholder="Hasta"
                        class="admin-input"
                        title="Fecha de presentación hasta"
                    >
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 md:col-span-2 lg:col-span-6">
                <button type="submit" class="admin-btn-secondary">
                    <i class="fa-solid fa-filter text-xs text-[#19A7A1]" aria-hidden="true"></i>
                    Filtrar
                </button>
                @if (!empty($filtros['busqueda']) || !empty($filtros['tipo']) || !empty($filtros['estado']) || !empty($filtros['desde']) || !empty($filtros['hasta']))
                    <a href="{{ route('admin.reclamaciones.index') }}" class="admin-btn-outline">
                        Limpiar filtros
                    </a>
                @endif
            </div>
        </form>

        <div class="admin-table-wrap" tabindex="0" role="region" aria-label="Listado de reclamaciones, desplazable horizontalmente">
            <table class="admin-table min-w-[850px]">
                <caption class="sr-only">Bandeja de quejas y reclamos registrados</caption>
                <thead>
                    <tr>
                        <th scope="col">Número de registro</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Consumidor</th>
                        <th scope="col">Documento</th>
                        <th scope="col">Fecha presentación</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($reclamaciones as $reclamacion)
                    @php
                        $doc = (string) $reclamacion->numero_documento;
                        $tipoDoc = strtoupper((string) $reclamacion->tipo_documento);
                        $visible = mb_substr($doc, -4);
                        $enmascarado = str_repeat('•', max(3, mb_strlen($doc) - 4)) . $visible;
                    @endphp
                    <tr>
                        <td class="font-semibold text-[#172B3A]">
                            <span class="block text-sm font-bold text-[#0A2540]">{{ $reclamacion->numero }}</span>
                            @if ($reclamacion->canal === 'respaldo')
                                <span class="block text-[11px] font-normal text-[#64748B]">Físico #{{ $reclamacion->numero_hoja_respaldo }}</span>
                            @else
                                <span class="block text-[11px] font-normal text-[#64748B]">Canal Web</span>
                            @endif
                        </td>
                        <td>
                            @if ($reclamacion->tipo === 'queja')
                                <span class="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-0.5 text-xs font-bold text-sky-800">
                                    QUEJA
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full border border-teal-200 bg-teal-50 px-2.5 py-0.5 text-xs font-bold text-[#0F766E]">
                                    RECLAMO
                                </span>
                            @endif
                        </td>
                        <td class="max-w-xs break-words">
                            <span class="block font-medium text-[#172B3A]">{{ $reclamacion->nombres }} {{ $reclamacion->apellidos }}</span>
                            @if ($reclamacion->es_menor)
                                <span class="inline-block text-[11px] font-medium text-[#287FB5]">Menor de edad</span>
                            @endif
                        </td>
                        <td class="font-medium text-[#172B3A]">
                            <span class="text-xs font-semibold text-[#64748B]">{{ $tipoDoc }}</span>
                            <span class="text-sm tracking-wider">{{ $enmascarado }}</span>
                        </td>
                        <td>
                            <span class="block text-sm font-medium text-[#172B3A]">{{ $reclamacion->recibida_at->timezone('America/Lima')->format('d/m/Y') }}</span>
                            <span class="block text-xs text-[#64748B]">{{ $reclamacion->recibida_at->timezone('America/Lima')->format('H:i') }} Lima</span>
                        </td>
                        <td>
                            <x-admin.badge-estado :estado="$reclamacion->estado" />
                            @if ($reclamacion->fecha_limite)
                                <span class="mt-1 block text-[11px] text-[#64748B]">Límite: {{ $reclamacion->fecha_limite->format('d/m/Y') }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <a href="{{ route('admin.reclamaciones.show', $reclamacion) }}" class="admin-btn-outline px-3 py-1.5 text-xs" aria-label="Ver detalle de {{ $reclamacion->numero }}">
                                <i class="fa-solid fa-eye text-xs text-[#19A7A1]" aria-hidden="true"></i>
                                Ver detalle
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-8">
                            <x-admin.empty-state
                                title="No hay registros"
                                message="No se encontraron quejas o reclamos en el Libro de Reclamaciones con los criterios indicados."
                                icon="fa-book-bookmark"
                            />
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($reclamaciones->hasPages() || $reclamaciones->total() > 0)
            <div class="flex flex-col items-center justify-between gap-3 border-t border-[#E2E8F0] p-4 text-sm text-[#64748B] sm:flex-row sm:p-5">
                <p>
                    Mostrando <span class="font-medium text-[#172B3A]">{{ $reclamaciones->firstItem() ?? 0 }}</span> a <span class="font-medium text-[#172B3A]">{{ $reclamaciones->lastItem() ?? 0 }}</span> de <span class="font-medium text-[#172B3A]">{{ $reclamaciones->total() }}</span> registros
                </p>
                @if ($reclamaciones->hasPages())
                    <div>
                        {{ $reclamaciones->links() }}
                    </div>
                @endif
            </div>
        @endif
    </section>
</div>
@endsection
