@extends('admin.layouts.app')

@section('title', 'Respaldo')
@section('breadcrumb', 'Respaldo')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Operación</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Copias de seguridad</h1>
            </div>

            @can('respaldo.exportar')
                <a href="{{ route('admin.respaldo.exportar') }}" class="inline-flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-download"></i>
                    Generar respaldo
                </a>
            @endcan
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-ink">Resumen</h2>
                    <p class="text-sm text-stone-600">Los respaldos exportan tablas clave del sistema en un paquete comprimido.</p>
                </div>
                <span class="rounded-full bg-gold/10 px-3 py-1.5 text-xs font-semibold text-gold-dark">{{ $archivos->count() }} archivos</span>
            </div>
        </section>

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
                        @forelse ($archivos as $archivo)
                            <tr class="hover:bg-stone-50">
                                <td class="px-5 py-3 font-medium text-ink">{{ $archivo['nombre'] }}</td>
                                <td class="px-5 py-3 text-stone-600">{{ number_format($archivo['size'] / 1024, 2) }} KB</td>
                                <td class="px-5 py-3 text-stone-600">{{ \Carbon\Carbon::createFromTimestamp($archivo['fecha'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-8">
                                    <x-admin.empty-state title="Sin respaldos" message="Todavía no se ha generado una copia de seguridad del sistema." icon="fa-database" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
