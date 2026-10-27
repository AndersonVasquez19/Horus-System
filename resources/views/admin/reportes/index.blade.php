@extends('admin.layouts.app')

@section('title', 'Reportes')
@section('breadcrumb', 'Reportes')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Informes</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Reportes y exportación</h1>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat-card title="Estudiantes" :value="$resumen['estudiantes']" icon="fa-user-graduate" />
            <x-admin.stat-card title="Cursos" :value="$resumen['cursos']" icon="fa-book-open" />
            <x-admin.stat-card title="Matrículas" :value="$resumen['matriculas']" icon="fa-clipboard-check" />
            <x-admin.stat-card title="Ingresos" :value="'S/ '.number_format((float) $resumen['ingresos'], 2, '.', ',')" icon="fa-sack-dollar" />
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-ink">Exportar información</h2>
            <div class="mt-5 flex flex-wrap gap-3">
                <form action="{{ route('admin.reportes.exportar.excel') }}" method="POST">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                        <i class="fa-solid fa-file-excel"></i>
                        Exportar Excel
                    </button>
                </form>

                <form action="{{ route('admin.reportes.exportar.pdf') }}" method="POST">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-stone-200 bg-white px-5 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-100">
                        <i class="fa-solid fa-file-pdf"></i>
                        Exportar PDF
                    </button>
                </form>
            </div>
        </section>
    </div>
@endsection
