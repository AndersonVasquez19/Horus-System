@extends('pdf.layout')

@section('title')
    {{ $titulo }} &middot; {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
@endsection

@section('content')
    @php
        $documentoIdentificador = $titulo;
    @endphp

    @include('pdf.header', [
        'titulo' => $titulo,
        'subtitulo' => $subtitulo,
    ])

    <div class="meta-card">
        <table class="meta-table">
            <tr>
                <td style="width: 34%;">
                    <span class="meta-label">Fecha de emisión</span>
                    <span class="meta-value">Generado el {{ $fecha }}</span>
                </td>
                <td style="width: 33%;">
                    <span class="meta-label">Total de registros</span>
                    <span class="meta-value">{{ $total }}</span>
                </td>
                <td style="width: 33%;">
                    <span class="meta-label">Filtro de curso</span>
                    <span class="meta-value">{{ isset($cursoFiltro) ? $cursoFiltro->codigo.' · '.$cursoFiltro->nombre : 'Todos' }}</span>
                </td>
            </tr>
            @if (!empty($metaResumen))
                <tr>
                    @foreach ($metaResumen as $etiqueta => $valor)
                        <td>
                            <span class="meta-label">{{ $etiqueta }}</span>
                            <span class="meta-value">{{ $valor }}</span>
                        </td>
                    @endforeach
                </tr>
            @endif
        </table>
        @if (!empty($notaTruncado))
            <div class="meta-notice">{{ $notaTruncado }}</div>
        @endif
    </div>

    <table class="data-table">
        <thead>
            <tr>
                @foreach ($cabeceras as $cabecera)
                    <th class="text-left">{{ $cabecera }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    @foreach ($fila as $celda)
                        <td>{{ $celda ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($cabeceras) }}" style="text-align: center; padding: 14px;">Sin registros para los filtros aplicados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection

@section('footer')
    @include('pdf.footer')
@endsection
