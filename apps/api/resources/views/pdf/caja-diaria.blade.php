@extends('pdf.layout')

@section('title')
    Caja Diaria {{ $fechaCaja }} &middot; {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
@endsection

@section('content')
    @php
        $documentoIdentificador = 'Caja Diaria · '.$fechaCaja;
    @endphp

    @include('pdf.header', [
        'titulo' => 'Reporte de Caja Diaria',
        'subtitulo' => 'Pagos confirmados del '.$fechaCaja.' · Emitido '.$fechaEmision,
    ])

    <div class="meta-card">
        <table class="meta-table">
            <tr>
                <td style="width: 34%;">
                    <span class="meta-label">Fecha de caja</span>
                    <span class="meta-value">{{ $fechaCaja }}</span>
                </td>
                <td style="width: 33%;">
                    <span class="meta-label">Operaciones confirmadas</span>
                    <span class="meta-value">{{ $cantidad }}</span>
                </td>
                <td style="width: 33%;">
                    <span class="meta-label">Total recaudado</span>
                    <span class="meta-value">{{ \App\Support\ConfiguracionSistema::monto($total) }}</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table" style="margin-bottom: 14px;">
        <thead>
            <tr>
                <th class="text-left">Método de pago</th>
                <th class="text-right">Cantidad</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($porMetodo as $m)
                <tr>
                    <td class="font-semibold">{{ $m['metodo'] }}</td>
                    <td class="text-right">{{ $m['cantidad'] }}</td>
                    <td class="text-right">{{ \App\Support\ConfiguracionSistema::monto($m['total']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" style="text-align: center; color: #64748b;">Sin pagos confirmados en esta fecha.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (!empty($movimientos))
        <table class="data-table">
            <thead>
                <tr>
                    <th class="text-left">Matrícula</th>
                    <th class="text-left">Estudiante</th>
                    <th class="text-left">Método</th>
                    <th class="text-left">Referencia</th>
                    <th class="text-right">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movimientos as $mov)
                    <tr>
                        <td>#{{ $mov['matricula_id'] }}</td>
                        <td>{{ $mov['estudiante'] }}</td>
                        <td>{{ $mov['metodo'] }}</td>
                        <td>{{ $mov['referencia'] ?? '—' }}</td>
                        <td class="text-right">{{ \App\Support\ConfiguracionSistema::monto($mov['monto']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
