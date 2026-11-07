@extends('admin.layouts.app')

@section('title', 'Panel de Control')
@section('breadcrumb', 'Panel de Control')

@section('content')
    {{-- Clases dinámicas generadas por DashboardController para las barras: h-1 h-12 h-20 h-28 h-36 --}}
    @php
        $coleccionMeses = collect($meses);
        $totalSemestre = (int) $coleccionMeses->sum('total');
        $promedioSemestre = $coleccionMeses->isNotEmpty() ? round($totalSemestre / $coleccionMeses->count(), 1) : 0;
        $mesPico = $coleccionMeses->sortByDesc('total')->first();
    @endphp

    <div class="space-y-6 lg:space-y-8">
        {{-- Encabezado principal, identidad Horus Group, contexto de área/rol y acciones --}}
        <section class="admin-card relative overflow-hidden" aria-label="Panel ejecutivo y acciones rápidas">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="inline-flex items-center gap-2 rounded-lg bg-[#0A2540] px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-white shadow-sm">
                            <span class="h-2 w-2 rounded-full bg-[#19A7A1]" aria-hidden="true"></span>
                            Horus Group SRL
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-[#176B9A]/20 bg-[#176B9A]/10 px-2.5 py-1 text-xs font-semibold uppercase tracking-[0.12em] text-[#176B9A]">
                            Panel ejecutivo
                        </span>
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-[#0A2540] sm:text-3xl">Panel de Control</h1>
                    <p class="text-sm leading-6 text-[#64748B]">
                        Bienvenido, <span class="font-semibold text-[#172B3A]">{{ auth()->user()->name }}</span>. Aquí tienes el resumen de la gestión académica.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2.5 lg:justify-end">
                    <div class="inline-flex items-center gap-2.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-xs font-semibold text-[#172B3A]">
                        <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-[#176B9A]/15 text-[#176B9A]">
                            <i class="fa-solid fa-sitemap text-[11px]" aria-hidden="true"></i>
                        </span>
                        <span>Área asignada: {{ $miArea }}</span>
                    </div>
                    <div class="inline-flex items-center gap-2.5 rounded-xl border border-[#19A7A1]/30 bg-[#19A7A1]/10 px-3.5 py-2.5 text-xs font-semibold text-[#0A2540]">
                        <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-[#0A2540] text-[#19A7A1]">
                            <i class="fa-solid fa-id-badge text-[11px]" aria-hidden="true"></i>
                        </span>
                        <span>Rol actual: {{ $miRol }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid gap-4 border-t border-[#E2E8F0] pt-5 lg:grid-cols-12 lg:items-end">
                <form action="{{ route('admin.busqueda.index') }}" method="GET" role="search" class="lg:col-span-6">
                    <label for="dashboard-quick-search" class="admin-label">Consulta rápida del sistema</label>
                    <div class="flex flex-col gap-2.5 sm:flex-row">
                        <div class="relative flex-1">
                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-[#64748B]" aria-hidden="true"></i>
                            <input
                                id="dashboard-quick-search"
                                type="search"
                                name="q"
                                value="{{ is_string(request('q')) ? request('q') : '' }}"
                                placeholder="Buscar estudiantes, cursos, matrículas o DNI..."
                                class="admin-input pl-10"
                            >
                        </div>
                        <button type="submit" class="admin-btn-secondary shrink-0">
                            <i class="fa-solid fa-magnifying-glass text-xs text-[#19A7A1]" aria-hidden="true"></i>
                            <span>Buscar</span>
                        </button>
                    </div>
                </form>

                <div class="flex flex-wrap items-center gap-2.5 lg:col-span-6 lg:justify-end">
                    @can('matriculas.crear')
                        <a href="{{ route('admin.matriculas.create') }}" class="admin-btn-primary">
                            <i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>
                            <span>Nueva matrícula</span>
                        </a>
                    @endcan
                    @can('estudiantes.crear')
                        <a href="{{ route('admin.estudiantes.create') }}" class="admin-btn-outline">
                            <i class="fa-solid fa-user-plus text-xs text-[#176B9A]" aria-hidden="true"></i>
                            <span>Nuevo estudiante</span>
                        </a>
                    @endcan
                    @can('reportes.ver')
                        <a href="{{ route('admin.reportes.index') }}" class="admin-btn-outline">
                            <i class="fa-solid fa-file-export text-xs text-[#19A7A1]" aria-hidden="true"></i>
                            <span>Reportes</span>
                        </a>
                    @endcan
                </div>
            </div>
        </section>

        {{-- Indicadores principales (3x2 en escritorio para proporciones amplias y legibles) --}}
        <section class="space-y-3" aria-label="Indicadores principales">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-bold text-[#0A2540]">Indicadores principales</h2>
                    <p class="text-xs text-[#64748B]">Métricas consolidadas de operación académica, ingresos y seguridad</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-[#E2E8F0] bg-white px-3 py-1 text-xs font-medium text-[#64748B] shadow-sm">
                    <span class="h-2 w-2 rounded-full bg-[#19A7A1]" aria-hidden="true"></span>
                    Actualizado al {{ now()->format('d/m/Y') }}
                </span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-admin.stat-card
                    title="Estudiantes activos"
                    :value="$estudiantesActivos"
                    icon="fa-user-graduate"
                    description="Padrón académico en estado activo"
                    variant="blue"
                    href="/admin/estudiantes"
                />
                <x-admin.stat-card
                    title="Cursos activos"
                    :value="$cursosActivos"
                    icon="fa-book-open"
                    description="Oferta formativa habilitada"
                    variant="teal"
                    href="/admin/cursos"
                />
                <x-admin.stat-card
                    title="Matrículas del mes"
                    :value="$matriculasMes"
                    icon="fa-clipboard-check"
                    description="Inscripciones registradas este mes"
                    variant="navy"
                    href="/admin/matriculas"
                />
                {{-- V-03: solo Gerencia recibe ingresosMes; para los demás roles el backend envía null. --}}
                @if (! is_null($ingresosMes))
                    <x-admin.stat-card
                        title="Ingresos del mes"
                        :value="'S/ '.number_format((float) $ingresosMes, 2, '.', ',')"
                        icon="fa-sack-dollar"
                        description="Recaudación de matrículas pagadas"
                        variant="teal"
                        href="/admin/reportes"
                    />
                @endif
                <x-admin.stat-card
                    title="Usuarios"
                    :value="$usuariosActivos"
                    icon="fa-user-shield"
                    description="Cuentas administrativas registradas"
                    variant="sky"
                    href="/admin/usuarios"
                />
                <x-admin.stat-card
                    title="Roles"
                    :value="$rolesActivos"
                    icon="fa-user-tag"
                    description="Perfiles de autorización activos"
                    variant="blue"
                    href="/admin/roles"
                />
            </div>
        </section>

        {{-- Evolución semestral de matrículas + Seguimiento operativo --}}
        <div class="grid gap-6 xl:grid-cols-12">
            <section class="admin-card flex min-w-0 flex-col xl:col-span-8">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[#E2E8F0] pb-4">
                    <div>
                        <p class="admin-eyebrow">Evolución académica</p>
                        <h2 class="mt-1 text-lg font-bold text-[#0A2540]">Matrículas de los últimos 6 meses</h2>
                        <p class="mt-0.5 text-xs text-[#64748B]">Cantidad registrada por mes</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3 py-1.5 text-xs font-medium text-[#64748B]">
                        <i class="fa-regular fa-calendar text-[#176B9A]" aria-hidden="true"></i>
                        Incluye el mes actual
                    </span>
                </div>

                <div class="mt-5 grid grid-cols-3 gap-3">
                    <div class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-[#64748B]">Total semestre</p>
                        <p class="mt-1 text-lg font-bold text-[#172B3A]">{{ $totalSemestre }}</p>
                    </div>
                    <div class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-[#64748B]">Promedio mensual</p>
                        <p class="mt-1 text-lg font-bold text-[#172B3A]">{{ number_format((float) $promedioSemestre, 1) }}</p>
                    </div>
                    <div class="rounded-xl border border-[#19A7A1]/35 bg-[#19A7A1]/10 px-3.5 py-2.5">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-[#176B9A]">Mayor registro</p>
                        <p class="mt-1 text-lg font-bold text-[#0A2540]">{{ $mesPico ? $mesPico['etiqueta'].' ('.$mesPico['total'].')' : '—' }}</p>
                    </div>
                </div>

                <div class="mt-5 flex flex-1 flex-col justify-end rounded-2xl border border-[#E2E8F0] bg-[#F4F7FB]/80 p-4 sm:p-6">
                    <div class="grid h-56 grid-cols-6 items-end gap-2.5 sm:gap-5" role="img" aria-label="Gráfico de barras de matrículas de los últimos seis meses">
                        @foreach ($meses as $mes)
                            <div class="flex h-full min-w-0 flex-col items-center justify-end gap-2.5">
                                <span class="inline-flex min-w-[1.75rem] items-center justify-center rounded-md border border-[#E2E8F0] bg-white px-1.5 py-0.5 text-xs font-bold text-[#0A2540] shadow-sm">
                                    {{ $mes['total'] }}
                                </span>
                                <div class="flex h-36 w-full max-w-14 items-end justify-center rounded-xl bg-[#E2E8F0]/70 p-1">
                                    <span
                                        class="{{ $mes['clase_altura'] }} w-full rounded-lg bg-gradient-to-t from-[#176B9A] via-[#287FB5] to-[#19A7A1] shadow-sm transition duration-200 hover:from-[#0A2540] hover:to-[#176B9A]"
                                        title="{{ $mes['total'] }} matrículas en {{ $mes['etiqueta'] }}"
                                    ></span>
                                </div>
                                <span class="text-xs font-semibold text-[#64748B]">{{ $mes['etiqueta'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="min-w-0 space-y-4 xl:col-span-4" aria-label="Indicadores operativos">
                <div class="px-0.5">
                    <h2 class="text-base font-bold text-[#0A2540]">Seguimiento operativo</h2>
                    <p class="text-xs text-[#64748B]">Bandeja de atención y flujos internos pendientes</p>
                </div>

                <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-1">
                    <x-admin.stat-card
                        title="Aprobaciones pendientes"
                        :value="$aprobacionesPendientes"
                        icon="fa-check-to-slot"
                        description="Solicitudes por validar"
                        variant="teal"
                        :href="route('admin.aprobaciones.index')"
                    />
                    <x-admin.stat-card
                        title="Notificaciones pendientes"
                        :value="$notificacionesPendientes"
                        icon="fa-bell"
                        description="Avisos sin leer en bandeja"
                        variant="navy"
                        :href="route('admin.notificaciones.index')"
                    />
                </div>
            </section>
        </div>

        {{-- Tablas de últimos registros: Matrículas y Estudiantes --}}
        <section class="grid gap-6 xl:grid-cols-12" aria-label="Registros académicos recientes">
            <div class="admin-card-flush min-w-0 xl:col-span-7">
                <div class="admin-card-header">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#176B9A]/10 text-[#176B9A]">
                            <i class="fa-solid fa-clipboard-check text-xs" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 class="text-base font-bold text-[#0A2540]">Últimas matrículas</h2>
                            <p class="text-xs text-[#64748B]">Inscripciones más recientes registradas</p>
                        </div>
                    </div>
                    <a href="/admin/matriculas" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#176B9A] transition hover:text-[#0A2540] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#176B9A]">
                        <span>Ver todas</span>
                        <i class="fa-solid fa-arrow-right text-[10px] text-[#19A7A1]" aria-hidden="true"></i>
                    </a>
                </div>

                @if ($ultimasMatriculas->isEmpty())
                    <div class="p-5 sm:p-6">
                        <x-admin.empty-state title="No hay matrículas" message="Las matrículas recientes aparecerán aquí." icon="fa-clipboard-list" />
                    </div>
                @else
                    <div class="admin-table-wrap">
                        <table class="admin-table min-w-[480px]">
                            <thead>
                                <tr>
                                    <th>Estudiante</th>
                                    <th>Curso</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ultimasMatriculas as $matricula)
                                    <tr>
                                        <td class="font-semibold text-[#172B3A]">
                                            <div class="flex items-center gap-2.5">
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#176B9A]/10 text-xs font-bold text-[#176B9A] ring-1 ring-[#176B9A]/20">
                                                    {{ mb_strtoupper(mb_substr($matricula->estudiante->nombres, 0, 1)) }}
                                                </span>
                                                <span class="leading-snug">{{ $matricula->estudiante->nombres }} {{ $matricula->estudiante->apellidos }}</span>
                                            </div>
                                        </td>
                                        <td class="text-[#64748B]">{{ $matricula->curso->nombre }}</td>
                                        <td><x-admin.badge-estado :estado="$matricula->estado" /></td>
                                        <td class="whitespace-nowrap font-medium text-[#64748B]">
                                            {{ $matricula->fecha_matricula->format('d/m/Y') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="admin-card-flush min-w-0 xl:col-span-5">
                <div class="admin-card-header">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#19A7A1]/15 text-[#0F766E]">
                            <i class="fa-solid fa-user-graduate text-xs" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 class="text-base font-bold text-[#0A2540]">Últimos estudiantes</h2>
                            <p class="text-xs text-[#64748B]">Incorporaciones recientes al padrón</p>
                        </div>
                    </div>
                    <a href="/admin/estudiantes" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#176B9A] transition hover:text-[#0A2540] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#176B9A]">
                        <span>Ver todos</span>
                        <i class="fa-solid fa-arrow-right text-[10px] text-[#19A7A1]" aria-hidden="true"></i>
                    </a>
                </div>

                @if ($ultimosEstudiantes->isEmpty())
                    <div class="p-5 sm:p-6">
                        <x-admin.empty-state title="No hay estudiantes" message="Los estudiantes registrados aparecerán aquí." icon="fa-user-graduate" />
                    </div>
                @else
                    <div class="admin-table-wrap">
                        <table class="admin-table min-w-[360px]">
                            <thead>
                                <tr>
                                    <th>DNI</th>
                                    <th>Estudiante</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ultimosEstudiantes as $estudiante)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <span class="inline-flex rounded-lg border border-[#E2E8F0] bg-[#F4F7FB] px-2.5 py-1 font-mono text-xs font-semibold text-[#172B3A]">
                                                {{ $estudiante->dni }}
                                            </span>
                                        </td>
                                        <td class="font-medium text-[#172B3A]">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</td>
                                        <td><x-admin.badge-estado :estado="$estudiante->estado" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        {{-- Bitácora de actividad reciente --}}
        <section class="admin-card-flush min-w-0" aria-label="Actividad reciente del sistema">
            <div class="admin-card-header">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#176B9A]/10 text-[#176B9A]">
                        <i class="fa-solid fa-clock-rotate-left text-xs" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h2 class="text-base font-bold text-[#0A2540]">Actividad reciente</h2>
                        <p class="text-xs text-[#64748B]">Últimos eventos registrados en la trazabilidad administrativa</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @can('auditoria.ver')
                        <a href="{{ route('admin.auditoria.index') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#172B3A] transition hover:text-[#176B9A] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#176B9A]">
                            <i class="fa-solid fa-clipboard-list text-[11px] text-[#19A7A1]" aria-hidden="true"></i>
                            <span>Bitácora de auditoría</span>
                        </a>
                    @endcan
                    <a href="/admin/configuracion" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#176B9A] transition hover:text-[#0A2540] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#176B9A]">
                        <span>Configuración</span>
                        <i class="fa-solid fa-arrow-right text-[10px] text-[#19A7A1]" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            @if ($auditoriasRecientes->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-admin.empty-state title="Sin actividad" message="Cuando realices cambios, aparecerán aquí los eventos más recientes." icon="fa-clock-rotate-left" />
                </div>
            @else
                <ul class="divide-y divide-[#E2E8F0]">
                    @foreach ($auditoriasRecientes as $audit)
                        <li class="px-5 py-3.5 transition-colors hover:bg-[#F4F7FB]/80 sm:px-6">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] text-xs text-[#176B9A]">
                                        <i class="fa-solid fa-bolt text-[#19A7A1]" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-[#172B3A]">{{ $audit->action }}</p>
                                        <p class="text-xs text-[#64748B]">{{ $audit->user?->name ?? 'Sistema' }} · {{ $audit->created_at?->format('d/m/Y H:i') }}</p>
                                    </div>
                                </div>
                                <span class="inline-flex items-center rounded-full border border-[#176B9A]/20 bg-[#176B9A]/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-[#176B9A]">
                                    {{ class_basename((string) ($audit->model_type ?: 'General')) }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
