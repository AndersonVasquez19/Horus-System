@extends('pdf.layout')

@section('title')
    Constancia de Matrícula #{{ $matricula->id }} &middot; {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
@endsection

@section('content')
    @php
        $documentoIdentificador = 'Constancia de Matrícula #'.$matricula->id;
        $est = $matricula->estudiante;
        $curso = $matricula->curso;
        $conv = $matricula->convocatoria;
    @endphp

    @include('pdf.header', [
        'titulo' => 'Constancia de Matrícula',
        'subtitulo' => 'Documento informativo emitido desde el panel académico · Registro #'.$matricula->id,
    ])

    <div class="meta-card">
        <table class="meta-table">
            <tr>
                <td style="width: 33%;">
                    <span class="meta-label">Fecha de emisión</span>
                    <span class="meta-value">{{ $fecha }}</span>
                </td>
                <td style="width: 33%;">
                    <span class="meta-label">Fecha de matrícula</span>
                    <span class="meta-value">{{ $matricula->fecha_matricula?->format('d/m/Y') ?? '—' }}</span>
                </td>
                <td style="width: 34%;">
                    <span class="meta-label">Estado de matrícula</span>
                    <span class="meta-value">@include('pdf.badge-estado', ['estado' => $matricula->estado])</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table" style="margin-bottom: 14px;">
        <thead>
            <tr>
                <th class="text-left" colspan="2">Datos del Estudiante</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-semibold" style="width: 30%;">Nombre completo</td>
                <td>{{ $est ? trim($est->nombres.' '.$est->apellidos) : 'No registrado' }}</td>
            </tr>
            <tr>
                <td class="font-semibold">DNI</td>
                <td>{{ $est?->dni ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <table class="data-table" style="margin-bottom: 14px;">
        <thead>
            <tr>
                <th class="text-left" colspan="2">Programa Académico</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-semibold" style="width: 30%;">Curso</td>
                <td>{{ $curso ? ($curso->codigo.' · '.$curso->nombre) : '—' }}</td>
            </tr>
            <tr>
                <td class="font-semibold">Modalidad / Horario</td>
                <td>
                    {{ $curso ? ucfirst((string) $curso->modalidad) : '—' }}
                    @if ($curso?->horario)
                        &middot; {{ $curso->horario }}
                    @endif
                </td>
            </tr>
            <tr>
                <td class="font-semibold">Edición</td>
                <td>
                    @if ($conv)
                        {{ $conv->codigo }}@if($conv->nombre) &middot; {{ $conv->nombre }}@endif
                        @if ($conv->fecha_inicio && $conv->fecha_fin)
                            ({{ $conv->fecha_inicio->format('d/m/Y') }} – {{ $conv->fecha_fin->format('d/m/Y') }})
                        @endif
                    @else
                        Sin edición asignada
                    @endif
                </td>
            </tr>
            @if (!empty($horarios))
                <tr>
                    <td class="font-semibold">Horarios de clase</td>
                    <td>{{ implode(' · ', $horarios) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if (!empty($qrDataUri))
        <div class="meta-card" style="margin-bottom: 14px; text-align: right;">
            <table class="meta-table">
                <tr>
                    <td style="vertical-align: middle; text-align: left;">
                        <span class="meta-label">Validación rápida</span>
                        <span class="meta-value" style="font-size: 11px;">Constancia #{{ $matricula->id }} · {{ strtoupper((string) $matricula->estado) }}</span>
                    </td>
                    <td style="width: 82px; text-align: right;">
                        <img src="{{ $qrDataUri }}" alt="QR Constancia" style="width: 72px; height: 72px;" />
                    </td>
                </tr>
            </table>
        </div>
    @endif

    @if ($mostrarMontos ?? false)
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-left" colspan="2">Resumen Económico (Libro de Pagos)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-semibold" style="width: 30%;">Monto pagado confirmado</td>
                <td>{{ \App\Support\ConfiguracionSistema::monto($matricula->monto_pagado ?? 0) }}</td>
            </tr>
            @if ($saldos !== null)
                <tr>
                    <td class="font-semibold">Importe total de la obligación</td>
                    <td>{{ \App\Support\ConfiguracionSistema::monto(\App\Services\GestionPagos::formato($saldos['total'])) }}</td>
                </tr>
                <tr>
                    <td class="font-semibold">Saldo pendiente</td>
                    <td>{{ \App\Support\ConfiguracionSistema::monto(\App\Services\GestionPagos::formato($saldos['saldo'])) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
    @endif
@endsection

@section('footer')
    @include('pdf.footer')
@endsection
