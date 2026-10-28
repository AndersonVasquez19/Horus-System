<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de matrículas</title>
    <style>
        body { font-family: Arial, sans-serif; color: #1c1917; }
        h1 { font-size: 22px; margin-bottom: 8px; }
        p { color: #57534e; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #e7e5e4; padding: 8px; font-size: 12px; text-align: left; }
        th { background: #f5f5f4; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        td { overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <h1>Reporte de matrículas</h1>
    <p>Generado el {{ $fecha }}</p>
    @include('admin.reportes._alcance')
    <p>Orden: fecha de matrícula descendente, luego ID descendente.</p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Estudiante</th>
                <th>Curso</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($matriculas as $matricula)
                <tr>
                    <td>{{ $matricula->id }}</td>
                    <td>{{ $matricula->estudiante?->nombres }} {{ $matricula->estudiante?->apellidos }}</td>
                    <td>{{ $matricula->curso?->nombre }}</td>
                    <td>{{ $matricula->fecha_matricula?->format('d/m/Y') }}</td>
                    <td>{{ $matricula->estado }}</td>
                    <td>{{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No hay matrículas que coincidan con los filtros.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
