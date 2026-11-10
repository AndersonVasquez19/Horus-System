@extends('admin.layouts.app')

@section('title', 'Reportes')
@section('breadcrumb', 'Reportes')

@section('content')
    <div class="space-y-6">
        <section>
            <p class="admin-eyebrow">Informes</p>
            <h1 class="admin-page-title">Reportes y exportación</h1>
            <p class="admin-page-subtitle">Filtra las matrículas y revisa el lote que incluirá el PDF.</p>
        </section>

        <section class="admin-card" aria-label="Filtros de reportes">
            @if ($errors->any())
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    <p class="font-semibold">Revisa los filtros:</p>
                    <ul class="mt-1 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="GET" action="{{ route('admin.reportes.index') }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="reporte-busqueda" class="admin-label">Búsqueda</label>
                    <input id="reporte-busqueda" name="busqueda" value="{{ $filtros['busqueda'] ?? '' }}" maxlength="200" placeholder="DNI, estudiante o curso" class="admin-input">
                </div>
                <div>
                    <label for="reporte-estado" class="admin-label">Estado</label>
                    <select id="reporte-estado" name="estado" class="admin-select">
                        <option value="">Todos los estados</option>
                        @foreach (['pendiente' => 'Pendiente', 'pagada' => 'Pagada', 'anulada' => 'Anulada'] as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(($filtros['estado'] ?? '') === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label for="reporte-curso" class="admin-label">Curso</label>
                    <select id="reporte-curso" name="curso_id" class="admin-select">
                        <option value="">Todos los cursos</option>
                        @foreach ($cursos as $curso)
                            <option value="{{ $curso->id }}" @selected((string) ($filtros['curso_id'] ?? '') === (string) $curso->id)>{{ $curso->codigo }} · {{ $curso->nombre }}{{ $curso->trashed() ? ' (eliminado)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="reporte-eliminados" class="admin-label">Registros</label>
                    <select id="reporte-eliminados" name="eliminados" class="admin-select">
                        <option value="0" @selected(empty($filtros['eliminados']))>Sin eliminadas</option>
                        <option value="1" @selected(!empty($filtros['eliminados']))>Solo eliminadas</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-3 sm:col-span-2 xl:col-span-4">
                    <button type="submit" class="admin-btn-primary">
                        <i class="fa-solid fa-filter text-xs" aria-hidden="true"></i>
                        Aplicar filtros
                    </button>
                    <a href="{{ route('admin.reportes.index') }}" class="admin-btn-outline">Limpiar</a>
                </div>
            </form>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat-card title="Estudiantes registrados" :value="$resumen['estudiantes']" icon="fa-user-graduate" variant="blue" />
            <x-admin.stat-card title="Cursos registrados" :value="$resumen['cursos']" icon="fa-book-open" variant="teal" />
            <x-admin.stat-card title="Matrículas coincidentes" :value="$resumen['matriculas']" icon="fa-clipboard-check" variant="sky" />
            <x-admin.stat-card title="Ingresos coincidentes" :value="\App\Support\ConfiguracionSistema::monto($resumen['ingresos'])" icon="fa-sack-dollar" variant="navy" />
        </section>
        <p class="text-sm text-[#64748B]">Estudiantes y cursos muestran totales generales sin eliminados. Matrículas e ingresos respetan los filtros; los ingresos suman el monto pagado de matrículas con estado pagada.</p>

        <section class="admin-card-flush">
            <div class="space-y-4 border-b border-[#E2E8F0] p-5 sm:p-6">
                <h2 class="text-lg font-bold text-[#172B3A]">Lote de matrículas para PDF</h2>
                @include('admin.reportes._alcance')
                <div class="flex flex-wrap gap-3">
                    @can('reportes.exportar.pdf')
                        <form action="{{ route('admin.reportes.exportar.pdf') }}" method="POST">
                            @csrf
                            @foreach ($filtros as $nombre => $valor)
                                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                            @endforeach
                            <input type="hidden" name="lote" value="{{ $matriculas->currentPage() }}">
                            <button type="submit" class="admin-btn-primary">
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
                            <button type="submit" class="admin-btn-outline">
                                <i class="fa-solid fa-file-excel text-[#19A7A1]" aria-hidden="true"></i> Exportar Excel · todas las coincidencias
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
            <div class="admin-table-wrap focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#176B9A]" tabindex="0" role="region" aria-label="Tabla de matrículas del lote, desplazable horizontalmente">
                <table class="admin-table min-w-[640px]">
                    <caption class="sr-only">Matrículas incluidas en el lote actual del PDF</caption>
                    <thead>
                        <tr>
                            @foreach (['ID', 'Estudiante', 'Curso', 'Fecha', 'Estado', 'Monto pagado'] as $columna)
                                <th scope="col">{{ $columna }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($matriculas as $matricula)
                            <tr>
                                <td class="font-semibold text-[#172B3A]">{{ $matricula->id }}</td>
                                <td class="font-medium text-[#172B3A]">{{ $matricula->estudiante?->nombres }} {{ $matricula->estudiante?->apellidos }}</td>
                                <td>{{ $matricula->curso?->nombre }}</td>
                                <td class="whitespace-nowrap">{{ $matricula->fecha_matricula?->format('d/m/Y') }}</td>
                                <td><x-admin.badge-estado :estado="$matricula->estado" /></td>
                                <td class="whitespace-nowrap font-semibold text-[#172B3A]">{{ $matricula->monto_pagado !== null ? \App\Support\ConfiguracionSistema::monto($matricula->monto_pagado) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-6 text-center text-[#64748B]">No hay matrículas que coincidan con los filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($matriculas->hasPages())
                <div class="border-t border-[#E2E8F0] p-4 sm:p-5">{{ $matriculas->links() }}</div>
            @endif
        </section>
    </div>
@endsection
