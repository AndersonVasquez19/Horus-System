@extends('pdf.layout')

@section('title')
    Historial Académico · {{ $estudiante->dni }} &middot; {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
@endsection

@section('content')
    @php
        $documentoIdentificador = 'Historial · DNI '.$estudiante->dni;
    @endphp

    @include('pdf.header', [
        'titulo' => 'Historial Académico del Estudiante',
        'subtitulo' => trim($estudiante->nombres.' '.$estudiante->apellidos).' · DNI '.$estudiante->dni.' · Emitido '.$fecha,
    ])

    <table class="data-table">
        <thead>
            <tr>
                <th class="text-left">Matrícula</th>
                <th class="text-left">Curso</th>
                <th class="text-left">Edición</th>
                <th class="text-left">Fecha</th>
                <th class="text-left">Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($matriculas as $m)
                <tr>
                    <td>#{{ $m['id'] }}</td>
                    <td class="font-semibold">{{ $m['curso'] }}</td>
                    <td>{{ $m['edicion'] ?? '—' }}</td>
                    <td>{{ $m['fecha'] }}</td>
                    <td>{{ ucfirst((string) $m['estado']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: #64748b;">El estudiante no registra matrículas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
