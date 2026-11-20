@extends('pdf.layout')

@section('title')
    Recibo de Pago #{{ $obligacion->matricula_id }} &middot; {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
@endsection

@section('content')
    @php
        $documentoIdentificador = 'Obligación · Matrícula #'.$obligacion->matricula_id;
        $est = $obligacion->matricula?->estudiante;
        $curso = $obligacion->matricula?->curso;
        $conv = $obligacion->matricula?->convocatoria;
        $transaccionesVisibles = collect($detalle['transacciones'] ?? [])->whereIn('estado', ['confirmada', 'revertida'])->values();
    @endphp

    @include('pdf.header', [
        'titulo' => 'Estado de Cuenta y Recibo de Pago',
        'subtitulo' => 'Comprobante interno emitido desde Horus Academic · Matrícula #'.$obligacion->matricula_id,
    ])

    @php
        $esCancelado = ($detalle['estado'] ?? '') === 'pagada' && ((float) ($detalle['saldo'] ?? 1)) <= 0.0;
        $cuotasPendientes = collect($detalle['cuotas'] ?? [])->filter(fn ($c) => ((float) ($c['saldo'] ?? 0)) > 0)->values();
    @endphp

    <div style="margin-bottom: 12px; padding: 8px 12px; border-radius: 6px; border: 1px solid {{ $esCancelado ? '#16a34a' : '#d97706' }}; background: {{ $esCancelado ? '#f0fdf4' : '#fffbeb' }}; color: {{ $esCancelado ? '#166534' : '#92400e' }}; font-weight: bold; font-size: 12px; text-align: center;">
        {{ $esCancelado ? 'CANCELADO AL 100 %' : 'ABONO PARCIAL · SALDO PENDIENTE: '.\App\Support\ConfiguracionSistema::monto($detalle['saldo']) }}
    </div>

    <div class="meta-card">
        <table class="meta-table">
            <tr>
                <td style="width: 34%;">
                    <span class="meta-label">Fecha de emisión</span>
                    <span class="meta-value">{{ $fecha }}</span>
                </td>
                <td style="width: 33%;">
                    <span class="meta-label">Plan</span>
                    <span class="meta-value">{{ ucfirst((string) $obligacion->plan) }}</span>
                </td>
                <td style="width: 33%;">
                    <span class="meta-label">Estado de cobro</span>
                    <span class="meta-value">@include('pdf.badge-estado', ['estado' => $detalle['estado']])</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table" style="margin-bottom: 14px;">
        <thead>
            <tr>
                <th class="text-left" colspan="2">Datos del Titular y Matrícula</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-semibold" style="width: 30%;">Estudiante</td>
                <td>{{ $est ? trim($est->nombres.' '.$est->apellidos) : 'No registrado' }}</td>
            </tr>
            <tr>
                <td class="font-semibold">DNI</td>
                <td>{{ $est?->dni ?? '—' }}</td>
            </tr>
            <tr>
                <td class="font-semibold">Curso / Edición</td>
                <td>
                    {{ $curso ? ($curso->codigo.' · '.$curso->nombre) : '—' }}
                    @if ($conv)
                        &middot; {{ $conv->codigo }}
                    @endif
                </td>
            </tr>
            <tr>
                <td class="font-semibold">Concepto</td>
                <td>{{ $obligacion->concepto }}</td>
            </tr>
        </tbody>
    </table>

    <table class="data-table" style="margin-bottom: 14px;">
        <thead>
            <tr>
                <th class="text-left" colspan="2">Resumen de Cuenta ({{ $obligacion->moneda }})</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-semibold" style="width: 30%;">Importe total</td>
                <td>{{ \App\Support\ConfiguracionSistema::monto($detalle['monto_total']) }}</td>
            </tr>
            <tr>
                <td class="font-semibold">Total pagado confirmado</td>
                <td>{{ \App\Support\ConfiguracionSistema::monto($detalle['pagado']) }}</td>
            </tr>
            <tr>
                <td class="font-semibold">Pendiente por confirmar</td>
                <td>{{ \App\Support\ConfiguracionSistema::monto($detalle['pendiente_confirmar']) }}</td>
            </tr>
            <tr>
                <td class="font-semibold">Saldo pendiente</td>
                <td>{{ \App\Support\ConfiguracionSistema::monto($detalle['saldo']) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th class="text-left">Fecha</th>
                <th class="text-left">Tipo</th>
                <th class="text-left">Método</th>
                <th class="text-left">Estado</th>
                <th class="text-right">Importe</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transaccionesVisibles as $tx)
                <tr>
                    <td>{{ $tx['fecha_pago'] }}</td>
                    <td>{{ ucfirst((string) $tx['tipo']) }}</td>
                    <td>{{ $tx['metodo'] }}</td>
                    <td>{{ ucfirst((string) $tx['estado']) }}</td>
                    <td class="text-right">
                        {{ $tx['tipo'] === 'reverso' ? '−' : '' }}{{ \App\Support\ConfiguracionSistema::monto($tx['monto']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: #64748b;">No hay pagos confirmados registrados hasta el momento.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($cuotasPendientes->isNotEmpty())
        <table class="data-table" style="margin-top: 14px;">
            <thead>
                <tr>
                    <th class="text-left" colspan="3">Cuotas pendientes</th>
                </tr>
                <tr>
                    <th class="text-left">Cuota</th>
                    <th class="text-left">Vencimiento</th>
                    <th class="text-right">Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cuotasPendientes as $cuota)
                    <tr>
                        <td>Cuota #{{ $cuota['numero'] }}</td>
                        <td>{{ $cuota['vence'] }}</td>
                        <td class="text-right">{{ \App\Support\ConfiguracionSistema::monto($cuota['saldo']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection

@section('footer')
    @include('pdf.footer')
@endsection
