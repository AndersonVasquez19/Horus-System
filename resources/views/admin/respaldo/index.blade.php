@extends('admin.layouts.app')

@section('title', 'Respaldo')
@section('breadcrumb', 'Respaldo')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Operación</p>
                <h1 class="admin-page-title">Exportación de datos</h1>
                <p class="admin-page-subtitle">Descarga tablas operativas minimizadas en formato CSV comprimido.</p>
            </div>

            @can('respaldo.exportar')
                <a href="{{ route('admin.respaldo.exportar') }}" class="admin-btn-primary">
                    <i class="fa-solid fa-download" aria-hidden="true"></i>
                    Exportar datos (ZIP)
                </a>
            @endcan
        </section>

        <section class="admin-card">
            <h2 class="text-lg font-bold text-[#172B3A]">Qué es esta exportación</h2>
            <p class="mt-1.5 text-sm leading-6 text-[#64748B]">
                Genera un paquete comprimido con tablas clave del sistema en formato CSV. Se omiten contraseñas, tokens y claves, y el archivo se entrega al navegador sin conservar una copia en el servidor.
            </p>
            <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>
                <strong>No es una copia de seguridad.</strong> No contiene todas las tablas ni permite restaurar el sistema. Las copias completas de la base de datos las realiza el responsable del servidor con herramientas de PostgreSQL.
            </p>
        </section>

        <section class="admin-card">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-2">
                    <h2 class="text-lg font-bold text-[#172B3A]">Archivos heredados de versiones anteriores</h2>
                    <p class="text-sm leading-6 text-[#64748B]">Esta lista muestra únicamente lo que hay en la carpeta privada de exportaciones del servidor (por ejemplo, JSON de auditoría de versiones anteriores).</p>
                    <p class="text-sm leading-6 text-[#64748B]"><strong class="text-[#172B3A]">No incluye los ZIP de «respaldo» que generaban versiones anteriores</strong>, porque se guardaban en otra carpeta y pueden seguir en el servidor aunque esta lista esté vacía. Pueden contener datos sin minimizar: pide al responsable del servidor que los localice y los revise según la política de retención (véase <code class="rounded bg-[#F4F7FB] px-1.5 py-0.5 text-xs text-[#176B9A]">docs/seguridad-l1.1.md</code>).</p>
                </div>
                @if ($archivos->isNotEmpty())
                    <span class="w-fit shrink-0 rounded-full border border-[#19A7A1]/30 bg-[#19A7A1]/10 px-3 py-1.5 text-xs font-semibold text-[#0F766E]">{{ $archivos->count() }} archivos</span>
                @endif
            </div>
        </section>

        @if ($archivos->isNotEmpty())
            <section class="admin-card-flush">
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[540px]">
                        <thead>
                            <tr>
                                <th scope="col">Nombre</th>
                                <th scope="col">Peso</th>
                                <th scope="col">Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($archivos as $archivo)
                                <tr>
                                    <td class="font-medium text-[#172B3A]">{{ $archivo['nombre'] }}</td>
                                    <td>{{ number_format($archivo['size'] / 1024, 2) }} KB</td>
                                    <td>{{ \Carbon\Carbon::createFromTimestamp($archivo['fecha'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
