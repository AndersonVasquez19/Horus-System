@extends('pdf.layout')

@section('title')
    Lista de Aula {{ $convocatoria->codigo }} &middot; {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
@endsection

@section('content')
    @php
        $documentoIdentificador = 'Lista de Aula · '.$convocatoria->codigo;
    @endphp

    @include('pdf.header', [
        'titulo' => 'Lista Imprimible de Aula',
        'subtitulo' => ($convocatoria->curso?->nombre ?? 'Curso').' · Edición '.$convocatoria->codigo.' · Emitido '.$fecha,
    ])

    <table class="data-table">
        <thead>
            <tr>
                <th class="text-left" style="width: 6%;">N°</th>
                <th class="text-left" style="width: 14%;">DNI</th>
                <th class="text-left" style="width: 38%;">Apellidos y Nombres</th>
                <th class="text-left" style="width: 18%;">Teléfono</th>
                <th class="text-left" style="width: 24%;">Firma / Asistencia</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($alumnos as $i => $a)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $a['dni'] }}</td>
                    <td class="font-semibold">{{ $a['nombre'] }}</td>
                    <td>{{ $a['telefono'] ?? '—' }}</td>
                    <td></td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: #64748b;">No hay estudiantes matriculados vigentes en esta edición.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
