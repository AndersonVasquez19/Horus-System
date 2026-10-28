@extends('admin.layouts.app')

@section('title', 'Reportes')
@section('breadcrumb', 'Reportes')

@section('content')
    <div class="space-y-6">
        <section>
            <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Informes</p>
            <h1 class="mt-1 text-2xl font-bold text-ink">Reportes y exportación</h1>
            <p class="mt-2 text-sm text-stone-600">Filtra las matrículas y revisa el lote que incluirá el PDF.</p>
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-6" aria-label="Filtros de reportes">
            @if ($errors->any())
                <div class="mb-4 rounded-xl bg-red-50 p-4 text-sm text-red-800" role="alert">
                    <p class="font-semibold">Revisa los filtros:</p>
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="GET" action="{{ route('admin.reportes.index') }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="reporte-busqueda" class="mb-2 block text-sm font-semibold">Búsqueda</label>
                    <input id="reporte-busqueda" name="busqueda" value="{{ $filtros['busqueda'] ?? '' }}" maxlength="200" placeholder="DNI, estudiante o curso" class="min-h-11 w-full rounded-xl border border-stone-200 px-3 py-2 focus:border-gold focus:ring-gold">
                </div>
                <div>
                    <label for="reporte-estado" class="mb-2 block text-sm font-semibold">Estado</label>
                    <select id="reporte-estado" name="estado" class="min-h-11 w-full rounded-xl border border-stone-200 px-3 py-2 focus:border-gold focus:ring-gold">
                        <option value="">Todos los estados</option>
                        @foreach (['pendiente' => 'Pendiente', 'pagada' => 'Pagada', 'anulada' => 'Anulada'] as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(($filtros['estado'] ?? '') === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label for="reporte-curso" class="mb-2 block text-sm font-semibold">Curso</label>
                    <select id="reporte-curso" name="curso_id" class="min-h-11 w-full rounded-xl border border-stone-200 px-3 py-2 focus:border-gold focus:ring-gold">
                        <option value="">Todos los cursos</option>
                        @foreach ($cursos as $curso)
                            <option value="{{ $curso->id }}" @selected((string) ($filtros['curso_id'] ?? '') === (string) $curso->id)>{{ $curso->codigo }} · {{ $curso->nombre }}{{ $curso->trashed() ? ' (eliminado)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="reporte-eliminados" class="mb-2 block text-sm font-semibold">Registros</label>
                    <select id="reporte-eliminados" name="eliminados" class="min-h-11 w-full rounded-xl border border-stone-200 px-3 py-2 focus:border-gold focus:ring-gold">
                        <option value="0" @selected(empty($filtros['eliminados']))>Sin eliminadas</option>
                        <option value="1" @selected(!empty($filtros['eliminados']))>Solo eliminadas</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-3 sm:col-span-2 xl:col-span-4">
                    <button type="submit" class="min-h-11 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-dark">Aplicar filtros</button>
                    <a href="{{ route('admin.reportes.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-stone-200 px-4 py-2.5 text-sm font-semibold">Limpiar</a>
                </div>
            </form>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat-card title="Estudiantes registrados" :value="$resumen['estudiantes']" icon="fa-user-graduate" />
            <x-admin.stat-card title="Cursos registrados" :value="$resumen['cursos']" icon="fa-book-open" />
            <x-admin.stat-card title="Matrículas coincidentes" :value="$resumen['matriculas']" icon="fa-clipboard-check" />
            <x-admin.stat-card title="Ingresos coincidentes" :value="'S/ '.number_format((float) $resumen['ingresos'], 2, '.', ',')" icon="fa-sack-dollar" />
        </section>
        <p class="text-sm text-stone-600">Estudiantes y cursos muestran totales generales sin eliminados. Matrículas e ingresos respetan los filtros; los ingresos suman el monto pagado de matrículas con estado pagada.</p>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="space-y-4 p-5 sm:p-6">
                <h2 class="text-lg font-bold text-ink">Lote de matrículas para PDF</h2>
                @include('admin.reportes._alcance')
                <div class="flex flex-wrap gap-3">
                    @can('reportes.exportar.pdf')
                        <form action="{{ route('admin.reportes.exportar.pdf') }}" method="POST">
                            @csrf
                            @foreach ($filtros as $nombre => $valor)
                                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                            @endforeach
                            <input type="hidden" name="lote" value="{{ $matriculas->currentPage() }}">
                            <button type="submit" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-dark">
                                <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Exportar PDF · lote {{ $matriculas->currentPage() }}
                            </button>
                        </form>
                    @endcan
                    @can('reportes.exportar.excel')
                        <form action="{{ route('admin.reportes.exportar.excel') }}" method="POST">
                            @csrf
                            @foreach ($filtros as $nombre => $valor)
                                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                            @endforeach
                            <button type="submit" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-stone-200 px-5 py-2.5 text-sm font-semibold text-stone-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-dark">
                                <i class="fa-solid fa-file-excel" aria-hidden="true"></i> Exportar Excel · todas las coincidencias
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
            <div class="overflow-x-auto focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-dark" tabindex="0" role="region" aria-label="Tabla de matrículas del lote, desplazable horizontalmente">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <caption class="sr-only">Matrículas incluidas en el lote actual del PDF</caption>
                    <thead class="bg-stone-50 text-stone-600">
                        <tr>
                            @foreach (['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto pagado'] as $columna)
                                <th scope="col" class="px-4 py-3">{{ $columna }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($matriculas as $matricula)
                            <tr>
                                <td class="px-4 py-3">{{ $matricula->id }}</td>
                                <td class="px-4 py-3">{{ $matricula->estudiante?->nombres }} {{ $matricula->estudiante?->apellidos }}</td>
                                <td class="px-4 py-3">{{ $matricula->curso?->nombre }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $matricula->fecha_matricula?->format('d/m/Y') }}</td>
                                <td class="px-4 py-3"><x-admin.badge-estado :estado="$matricula->estado" /></td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $matricula->monto_pagado !== null ? 'S/ '.number_format((float) $matricula->monto_pagado, 2, '.', ',') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-6 text-center text-stone-500">No hay matrículas que coincidan con los filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($matriculas->hasPages())
                <div class="border-t border-stone-100 p-5">{{ $matriculas->links() }}</div>
            @endif
        </section>
    </div>
@endsection
