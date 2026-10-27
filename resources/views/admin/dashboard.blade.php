@extends('admin.layouts.app')

@section('title', 'Panel de Control')
@section('breadcrumb', 'Panel de Control')

@section('content')
    <div class="space-y-6">
        <section>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">Horus Group SRL</p>
            <h1 class="mt-1 text-2xl font-bold text-ink">Panel de Control</h1>
            <p class="mt-1 text-sm text-stone-600">Bienvenido, {{ auth()->user()->name }}. Aquí tienes el resumen de la gestión académica.</p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6" aria-label="Indicadores principales">
            <x-admin.stat-card title="Estudiantes activos" :value="$estudiantesActivos" icon="fa-user-graduate" />
            <x-admin.stat-card title="Cursos activos" :value="$cursosActivos" icon="fa-book-open" />
            <x-admin.stat-card title="Matrículas del mes" :value="$matriculasMes" icon="fa-clipboard-check" />
            <x-admin.stat-card
                title="Ingresos del mes"
                :value="'S/ '.number_format((float) $ingresosMes, 2, '.', ',')"
                icon="fa-sack-dollar"
            />
            <x-admin.stat-card title="Usuarios" :value="$usuariosActivos" icon="fa-user-shield" />
            <x-admin.stat-card title="Roles" :value="$rolesActivos" icon="fa-user-tag" />
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Indicadores operativos">
            <x-admin.stat-card title="Procesos pendientes" :value="$procesosPendientes" icon="fa-diagram-project" />
            <x-admin.stat-card title="Aprobaciones pendientes" :value="$aprobacionesPendientes" icon="fa-check-to-slot" />
            <x-admin.stat-card title="Notificaciones pendientes" :value="$notificacionesPendientes" icon="fa-bell" />
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gold-dark">Panel ejecutivo</p>
                    <h2 class="mt-1 text-xl font-bold text-ink">Área asignada: {{ $miArea }}</h2>
                </div>
                <div class="rounded-xl bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-700">
                    Rol actual: {{ $miRol }}
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-lg font-bold text-ink">Matrículas de los últimos 6 meses</h2>
                    <p class="mt-1 text-sm text-stone-500">Cantidad registrada por mes</p>
                </div>
                <span class="text-xs font-medium text-stone-500">Incluye el mes actual</span>
            </div>

            <div class="mt-6 grid h-48 grid-cols-6 items-end gap-3 sm:gap-6" role="img" aria-label="Gráfico de barras de matrículas de los últimos seis meses">
                @foreach ($meses as $mes)
                    <div class="flex h-full flex-col items-center justify-end gap-2">
                        <span class="text-xs font-semibold text-stone-600">{{ $mes['total'] }}</span>
                        <div class="flex h-36 w-full items-end justify-center">
                            <span class="{{ $mes['clase_altura'] }} w-full max-w-16 rounded-t-lg bg-gold transition hover:bg-gold-dark" title="{{ $mes['total'] }} matrículas en {{ $mes['etiqueta'] }}"></span>
                        </div>
                        <span class="text-xs font-medium text-stone-500">{{ $mes['etiqueta'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-stone-100 px-5 py-4">
                    <h2 class="font-bold text-ink">Últimas matrículas</h2>
                    <a href="/admin/matriculas" class="text-sm font-semibold text-gold-dark hover:text-ink">Ver todas</a>
                </div>

                @if ($ultimasMatriculas->isEmpty())
                    <div class="p-5">
                        <x-admin.empty-state title="No hay matrículas" message="Las matrículas recientes aparecerán aquí." icon="fa-clipboard-list" />
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Estudiante</th>
                                    <th class="px-5 py-3 font-semibold">Curso</th>
                                    <th class="px-5 py-3 font-semibold">Estado</th>
                                    <th class="px-5 py-3 font-semibold">Fecha</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($ultimasMatriculas as $matricula)
                                    <tr class="hover:bg-stone-50">
                                        <td class="px-5 py-3 font-medium text-ink">
                                            {{ $matricula->estudiante->nombres }} {{ $matricula->estudiante->apellidos }}
                                        </td>
                                        <td class="px-5 py-3 text-stone-600">{{ $matricula->curso->nombre }}</td>
                                        <td class="px-5 py-3"><x-admin.badge-estado :estado="$matricula->estado" /></td>
                                        <td class="whitespace-nowrap px-5 py-3 text-stone-600">{{ $matricula->fecha_matricula->format('d/m/Y') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-stone-100 px-5 py-4">
                        <h2 class="font-bold text-ink">Últimos estudiantes</h2>
                        <a href="/admin/estudiantes" class="text-sm font-semibold text-gold-dark hover:text-ink">Ver todos</a>
                    </div>

                    @if ($ultimosEstudiantes->isEmpty())
                        <div class="p-5">
                            <x-admin.empty-state title="No hay estudiantes" message="Los estudiantes registrados aparecerán aquí." icon="fa-user-graduate" />
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[420px] text-left text-sm">
                                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                                    <tr>
                                        <th class="px-5 py-3 font-semibold">DNI</th>
                                        <th class="px-5 py-3 font-semibold">Estudiante</th>
                                        <th class="px-5 py-3 font-semibold">Estado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach ($ultimosEstudiantes as $estudiante)
                                        <tr class="hover:bg-stone-50">
                                            <td class="px-5 py-3 font-medium text-ink">{{ $estudiante->dni }}</td>
                                            <td class="px-5 py-3 text-stone-600">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</td>
                                            <td class="px-5 py-3"><x-admin.badge-estado :estado="$estudiante->estado" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-stone-100 px-5 py-4">
                        <h2 class="font-bold text-ink">Actividad reciente</h2>
                        <a href="/admin/configuracion" class="text-sm font-semibold text-gold-dark hover:text-ink">Configuración</a>
                    </div>

                    @if ($auditoriasRecientes->isEmpty())
                        <div class="p-5">
                            <x-admin.empty-state title="Sin actividad" message="Cuando realices cambios, aparecerán aquí los eventos más recientes." icon="fa-clock-rotate-left" />
                        </div>
                    @else
                        <ul class="divide-y divide-stone-100">
                            @foreach ($auditoriasRecientes as $audit)
                                <li class="px-5 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="font-semibold text-ink">{{ $audit->action }}</p>
                                            <p class="text-xs text-stone-500">{{ $audit->user?->name ?? 'Sistema' }} · {{ $audit->created_at?->format('d/m/Y H:i') }}</p>
                                        </div>
                                        <span class="rounded-full bg-gold/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-gold-dark">{{ $audit->model_type ?: 'General' }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </section>
    </div>
@endsection
