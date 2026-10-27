@extends('admin.layouts.app')

@section('title', 'Respaldo')
@section('breadcrumb', 'Respaldo')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Operación</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Exportación de datos</h1>
            </div>

            @can('respaldo.exportar')
                <a href="{{ route('admin.respaldo.exportar') }}" class="inline-flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-download"></i>
                    Exportar datos (ZIP)
                </a>
            @endcan
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-bold text-ink">Qué es esta exportación</h2>
            <p class="mt-1 text-sm text-stone-600">
                Genera un paquete comprimido con tablas clave del sistema en formato CSV. Se omiten contraseñas, tokens y claves, y el archivo se entrega al navegador sin conservar una copia en el servidor.
            </p>
            <p class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>
                <strong>No es una copia de seguridad.</strong> No contiene todas las tablas ni permite restaurar el sistema. Las copias completas de la base de datos las realiza el responsable del servidor con herramientas de PostgreSQL.
            </p>
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-ink">Archivos heredados de versiones anteriores</h2>
                    <p class="text-sm text-stone-600">Esta lista muestra únicamente lo que hay en la carpeta privada de exportaciones del servidor (por ejemplo, JSON de auditoría de versiones anteriores).</p>
                    <p class="mt-2 text-sm text-stone-600"><strong>No incluye los ZIP de «respaldo» que generaban versiones anteriores</strong>, porque se guardaban en otra carpeta y pueden seguir en el servidor aunque esta lista esté vacía. Pueden contener datos sin minimizar: pide al responsable del servidor que los localice y los revise según la política de retención (véase <code>docs/seguridad-l1.1.md</code>).</p>
                </div>
                @if ($archivos->isNotEmpty())
                    <span class="rounded-full bg-gold/10 px-3 py-1.5 text-xs font-semibold text-gold-dark">{{ $archivos->count() }} archivos</span>
                @endif
            </div>
        </section>

        @if ($archivos->isNotEmpty())
            <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Nombre</th>
                                <th class="px-5 py-3 font-semibold">Peso</th>
                                <th class="px-5 py-3 font-semibold">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($archivos as $archivo)
                                <tr class="hover:bg-stone-50">
                                    <td class="px-5 py-3 font-medium text-ink">{{ $archivo['nombre'] }}</td>
                                    <td class="px-5 py-3 text-stone-600">{{ number_format($archivo['size'] / 1024, 2) }} KB</td>
                                    <td class="px-5 py-3 text-stone-600">{{ \Carbon\Carbon::createFromTimestamp($archivo['fecha'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
