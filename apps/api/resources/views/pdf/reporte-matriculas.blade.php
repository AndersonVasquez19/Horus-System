@extends('pdf.layout')

@section('title')
    Reporte de Matrículas &middot; {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
@endsection

@section('content')
    @php
        $documentoIdentificador = 'Reporte de Matrículas';
    @endphp

    @include('pdf.header', [
        'titulo' => 'Reporte de Matrículas',
        'subtitulo' => 'Consolidado institucional y control académico de registros',
    ])

    {{-- Bloque de información y metadatos del documento --}}
    <div class="meta-card">
        <table class="meta-table">
            <tr>
                <td style="width: 25%;">
                    <span class="meta-label">Fecha de emisión</span>
                    <span class="meta-value">Generado el {{ $fecha }}</span>
                </td>
                <td style="width: 25%;">
                    <span class="meta-label">Total en sistema</span>
                    <span class="meta-value">Total de coincidencias: {{ $matriculas->total() }}</span>
                </td>
                <td style="width: 28%;">
                    <span class="meta-label">Alcance del lote</span>
                    <span class="meta-value">Registros incluidos: {{ $matriculas->count() }} &middot; Rango {{ $matriculas->firstItem() ?? 0 }}–{{ $matriculas->lastItem() ?? 0 }}</span>
                </td>
                <td style="width: 22%;">
                    <span class="meta-label">Lote consultado</span>
                    <span class="meta-value">Lote {{ $matriculas->currentPage() }} de {{ $matriculas->lastPage() }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Búsqueda aplicada</span>
                    <span class="meta-value">Búsqueda: {{ $filtros['busqueda'] ?? 'Sin búsqueda' }}</span>
                </td>
                <td>
                    <span class="meta-label">Filtro de estado</span>
                    <span class="meta-value">Estado: {{ isset($filtros['estado']) ? ucfirst($filtros['estado']) : 'Todos' }}</span>
                </td>
                <td>
                    <span class="meta-label">Filtro de curso</span>
                    <span class="meta-value">Curso: {{ isset($cursoFiltro) ? $cursoFiltro->codigo.' · '.$cursoFiltro->nombre : 'Todos' }}</span>
                </td>
                <td>
                    <span class="meta-label">Capacidad de lote</span>
                    <span class="meta-value">Máximo {{ $limitePdf }} registros por PDF</span>
                </td>
            </tr>
            @if (!empty($filtros['eliminados']))
            <tr>
                <td colspan="4" style="padding-top: 4px;">
                    <span class="meta-label">Condición especial</span>
                    <span class="meta-value" style="color: #B91C1C;">Solo matrículas eliminadas</span>
                </td>
            </tr>
            @endif
        </table>
        @if ($matriculas->lastPage() > 1)
            <div class="meta-notice">
                Este PDF incluye un solo lote. Descarga los demás lotes desde el panel de reportes para obtener todas las coincidencias.
            </div>
        @endif
    </div>

    <p class="order-note">Orden: fecha de matrícula descendente, luego ID descendente.</p>

    {{-- Tabla de registros estructurada y estilizada --}}
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 6%;">ID</th>
                <th class="text-left" style="width: 28%;">Estudiante</th>
                <th class="text-left" style="width: 31%;">Curso</th>
                <th class="text-center" style="width: 12%;">Fecha</th>
                <th class="text-center" style="width: 11%;">Estado</th>
                <th class="text-right" style="width: 12%;">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($matriculas as $matricula)
                <tr>
                    <td class="text-center font-semibold">{{ $matricula->id }}</td>
                    <td class="text-left">
                        <span class="font-bold text-dark">{{ $matricula->estudiante?->nombres }} {{ $matricula->estudiante?->apellidos }}</span>
                    </td>
                    <td class="text-left">{{ $matricula->curso?->nombre }}</td>
                    <td class="text-center">{{ $matricula->fecha_matricula?->format('d/m/Y') }}</td>
                    <td class="text-center">
                        @include('pdf.badge-estado', ['estado' => $matricula->estado])
                    </td>
                    <td class="text-right font-semibold">
                        {{ $matricula->monto_pagado !== null ? \App\Support\ConfiguracionSistema::monto($matricula->monto_pagado) : '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="data-table-empty">No hay matrículas que coincidan con los filtros.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
