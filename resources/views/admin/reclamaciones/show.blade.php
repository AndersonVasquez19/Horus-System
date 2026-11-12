@extends('admin.layouts.app')

@section('title', 'Detalle de reclamación #' . $reclamacion->numero)
@section('breadcrumb', 'Libro de Reclamaciones / Detalle')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <section class="admin-page-header">
        <div>
            <a href="{{ route('admin.reclamaciones.index') }}" class="admin-back-link">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver a la bandeja
            </a>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-[#172B3A]">Registro {{ $reclamacion->numero }}</h1>
            <p class="admin-page-subtitle">Presentado el {{ $reclamacion->recibida_at->timezone('America/Lima')->format('d/m/Y \a \l\a\s H:i') }} (Hora de Lima)</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($reclamacion->tipo === 'queja')
                <span class="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-bold text-sky-800">
                    QUEJA
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-bold text-[#0F766E]">
                    RECLAMO
                </span>
            @endif
            <x-admin.badge-estado :estado="$reclamacion->estado" />
            <a href="{{ route('admin.reclamaciones.hoja', $reclamacion) }}" target="_blank" rel="noopener" class="admin-btn-secondary px-3 py-1.5 text-xs">
                <i class="fa-solid fa-print text-xs text-[#19A7A1]" aria-hidden="true"></i>
                Imprimir hoja
            </a>
        </div>
    </section>

    {{-- A. Información del registro --}}
    <section class="admin-card sm:p-7">
        <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#0A2540] text-sm text-[#19A7A1]">
                    <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                </span>
                <h2 class="text-base font-bold text-[#172B3A]">A. Información del registro</h2>
            </div>
            <span class="text-xs font-semibold text-[#64748B]">Datos de la hoja</span>
        </div>
        <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Número de registro</dt>
                <dd class="mt-1 font-bold text-[#0A2540]">{{ $reclamacion->numero }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Tipo de registro</dt>
                <dd class="mt-1 font-semibold text-[#172B3A]">{{ strtoupper($reclamacion->tipo) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Fecha de presentación</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">{{ $reclamacion->recibida_at->timezone('America/Lima')->format('d/m/Y H:i') }} Lima</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Estado actual</dt>
                <dd class="mt-1">
                    <x-admin.badge-estado :estado="$reclamacion->estado" />
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Fecha límite de respuesta</dt>
                <dd class="mt-1 font-semibold text-[#172B3A]">
                    {{ $reclamacion->fecha_limite ? $reclamacion->fecha_limite->format('d/m/Y') : 'No establecida' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Canal de recepción</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">
                    @if ($reclamacion->canal === 'respaldo')
                        Libro físico de respaldo (#{{ $reclamacion->numero_hoja_respaldo }})
                    @else
                        Plataforma Web (Virtual)
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Canal preferido de respuesta</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">{{ ucfirst($reclamacion->canal_respuesta) }}</dd>
            </div>
            @if ($reclamacion->suspension_desde && $reclamacion->suspension_hasta)
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Suspensión por propuesta de solución</dt>
                    <dd class="mt-1 font-medium text-amber-800">
                        Del {{ $reclamacion->suspension_desde->format('d/m/Y') }} al {{ $reclamacion->suspension_hasta->format('d/m/Y') }}
                    </dd>
                </div>
            @endif
        </dl>

        {{-- Indicadores calculados en modo lectura: consultar no modifica el caso. --}}
        @if ($propuestaVencida)
            <p class="mt-5 rounded-xl border border-[#176B9A]/30 bg-[#176B9A]/10 px-4 py-3 text-sm text-[#0A2540]" role="status">
                <i class="fa-solid fa-circle-info text-[#176B9A]" aria-hidden="true"></i>
                La ventana de la propuesta de solución terminó el {{ $reclamacion->suspension_hasta?->format('d/m/Y') }}. El proceso diario de vencimientos reanudará el plazo y recalculará la fecha límite; la fecha mostrada aún no incluye ese ajuste.
            </p>
        @elseif ($avisoPlazo === 'vence')
            <p class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800" role="status">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                {{ now('America/Lima')->toDateString() > $reclamacion->fecha_limite->toDateString() ? 'El plazo legal de respuesta está vencido.' : 'El plazo legal de respuesta vence hoy.' }}
            </p>
        @elseif ($avisoPlazo === 'por_vencer')
            <p class="mt-5 rounded-xl border border-[#19A7A1]/40 bg-[#19A7A1]/10 px-4 py-3 text-sm font-semibold text-[#0A2540]" role="status">
                <i class="fa-solid fa-hourglass-half text-[#19A7A1]" aria-hidden="true"></i>
                El plazo legal de respuesta está por vencer ({{ $reclamacion->fecha_limite->format('d/m/Y') }}).
            </p>
        @endif
    </section>

    {{-- B. Datos del consumidor --}}
    <section class="admin-card sm:p-7">
        <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#176B9A]/15 text-sm text-[#176B9A]">
                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                </span>
                <h2 class="text-base font-bold text-[#172B3A]">B. Datos del consumidor</h2>
            </div>
            <span class="text-xs font-semibold text-[#64748B]">Titular del reclamo o queja</span>
        </div>
        <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
            <div class="sm:col-span-2">
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Nombres y apellidos</dt>
                <dd class="mt-1 text-base font-bold text-[#172B3A]">{{ $reclamacion->nombres }} {{ $reclamacion->apellidos }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Tipo y número de documento</dt>
                <dd class="mt-1 font-semibold text-[#172B3A]">
                    <span class="text-xs font-normal text-[#64748B]">{{ strtoupper($reclamacion->tipo_documento) }}</span>
                    {{ $reclamacion->numero_documento }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Correo electrónico</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">
                    <a href="mailto:{{ $reclamacion->email }}" class="text-[#176B9A] hover:underline">{{ $reclamacion->email }}</a>
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Teléfono</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">{{ $reclamacion->telefono }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Condición</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">
                    @if ($reclamacion->es_menor)
                        <span class="inline-flex items-center gap-1 text-amber-700">
                            <i class="fa-solid fa-child text-xs" aria-hidden="true"></i>
                            Menor de edad
                        </span>
                    @else
                        Mayor de edad
                    @endif
                </dd>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Domicilio</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">{{ $reclamacion->domicilio }}</dd>
            </div>
        </dl>
    </section>

    {{-- C. Información del bien o servicio --}}
    <section class="admin-card sm:p-7">
        <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#19A7A1]/15 text-sm text-[#0F766E]">
                    <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                </span>
                <h2 class="text-base font-bold text-[#172B3A]">C. Información del bien o servicio</h2>
            </div>
            <span class="text-xs font-semibold text-[#64748B]">Materia reclamada</span>
        </div>
        <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Tipo de bien</dt>
                <dd class="mt-1 font-semibold text-[#172B3A]">
                    {{ $reclamacion->bien_tipo ? ucfirst($reclamacion->bien_tipo) : 'No especificado' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Monto reclamado</dt>
                <dd class="mt-1 text-base font-bold text-[#0A2540]">
                    {{ $reclamacion->monto_bien !== null ? 'S/ ' . number_format((float) $reclamacion->monto_bien, 2, '.', ',') : 'No aplica' }}
                </dd>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Descripción del bien o servicio</dt>
                <dd class="mt-1 font-medium text-[#172B3A]">{{ $reclamacion->bien_descripcion ?: 'No especificado' }}</dd>
            </div>
        </dl>
    </section>

    {{-- D. Descripción del caso --}}
    <section class="admin-card sm:p-7">
        <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#287FB5]/15 text-sm text-[#176B9A]">
                    <i class="fa-solid fa-comment-dots" aria-hidden="true"></i>
                </span>
                <h2 class="text-base font-bold text-[#172B3A]">D. Descripción del caso</h2>
            </div>
            <span class="text-xs font-semibold text-[#64748B]">Detalle y pretensión</span>
        </div>
        <div class="space-y-6">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">
                    Detalle de la {{ $reclamacion->tipo === 'queja' ? 'queja' : 'reclamación' }}
                </h3>
                <div class="mt-2 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB]/70 p-4 text-sm leading-relaxed text-[#172B3A] whitespace-pre-line">{{ $reclamacion->detalle }}</div>
            </div>
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">
                    Pedido concreto del consumidor
                </h3>
                <div class="mt-2 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB]/70 p-4 text-sm leading-relaxed text-[#172B3A] whitespace-pre-line">{{ $reclamacion->pedido }}</div>
            </div>
        </div>
    </section>

    {{-- E. Representante (únicamente cuando corresponda) --}}
    @if ($reclamacion->es_menor || $reclamacion->representante_nombre)
        <section class="admin-card sm:p-7">
            <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-sm text-indigo-700">
                        <i class="fa-solid fa-users" aria-hidden="true"></i>
                    </span>
                    <h2 class="text-base font-bold text-[#172B3A]">E. Representante legal o apoderado</h2>
                </div>
                <span class="text-xs font-semibold text-[#64748B]">Padre, madre o tutor</span>
            </div>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Nombre del representante</dt>
                    <dd class="mt-1 font-bold text-[#172B3A]">{{ $reclamacion->representante_nombre ?: 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Teléfono del representante</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $reclamacion->representante_telefono ?: 'No registrado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Correo del representante</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">
                        @if ($reclamacion->representante_email)
                            <a href="mailto:{{ $reclamacion->representante_email }}" class="text-[#176B9A] hover:underline">{{ $reclamacion->representante_email }}</a>
                        @else
                            No registrado
                        @endif
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Domicilio del representante</dt>
                    <dd class="mt-1 font-medium text-[#172B3A]">{{ $reclamacion->representante_domicilio ?: 'No registrado' }}</dd>
                </div>
            </dl>
        </section>
    @endif

    {{-- Respuesta formal si ya estuviera respondida --}}
    @if ($reclamacion->respuesta)
        <section class="admin-card border-emerald-200 bg-emerald-50/30 sm:p-7">
            <div class="mb-5 flex items-center justify-between border-b border-emerald-200 pb-4">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 text-sm text-white">
                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                    </span>
                    <h2 class="text-base font-bold text-[#172B3A]">Respuesta formal emitida</h2>
                </div>
                <span class="text-xs font-semibold text-emerald-800">
                    Respondida el {{ $reclamacion->respondida_at?->timezone('America/Lima')->format('d/m/Y H:i') }} Lima
                </span>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-white p-5 text-sm leading-relaxed text-[#172B3A] whitespace-pre-line shadow-sm">{{ $reclamacion->respuesta }}</div>
        </section>
    @endif

    {{-- H. Gestión del caso (E4): solo para quien puede gestionar o responder. --}}
    @canany(['reclamaciones.gestionar', 'reclamaciones.responder'])
        @include('admin.reclamaciones._gestion')
    @endcanany

    {{-- F. Historial de actuaciones (solo de esta reclamación; cargado en una consulta agrupada) --}}
    <section class="admin-card sm:p-7" aria-labelledby="historial-titulo">
        <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#0A2540] text-sm text-[#19A7A1]">
                    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                </span>
                <h2 id="historial-titulo" class="text-base font-bold text-[#172B3A]">F. Historial de actuaciones</h2>
            </div>
            <span class="text-xs font-semibold text-[#64748B]">{{ $reclamacion->historial->count() }} {{ $reclamacion->historial->count() === 1 ? 'registro' : 'registros' }}</span>
        </div>
        @if ($reclamacion->historial->isEmpty())
            <p class="text-sm text-[#64748B]">No hay actuaciones registradas.</p>
        @else
            <ol class="relative space-y-5 border-l-2 border-[#E2E8F0] pl-6">
                @foreach ($reclamacion->historial as $actuacion)
                    @php
                        $transicion = \App\Support\PresentacionReclamacion::transicion($actuacion);
                        $detalles = \App\Support\PresentacionReclamacion::detalles($actuacion);
                    @endphp
                    <li class="relative" data-historial-evento="{{ $actuacion->evento }}">
                        <span class="absolute -left-[31px] top-1 h-3 w-3 rounded-full border-2 border-white bg-[#19A7A1] ring-2 ring-[#19A7A1]/30" aria-hidden="true"></span>
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                            <p class="text-sm font-bold text-[#0A2540]">{{ \App\Support\PresentacionReclamacion::evento($actuacion->evento) }}</p>
                            <time class="text-xs font-medium text-[#64748B]" datetime="{{ $actuacion->created_at->toIso8601String() }}">
                                {{ $actuacion->created_at->timezone('America/Lima')->format('d/m/Y H:i') }} Lima
                            </time>
                        </div>
                        <p class="mt-0.5 text-xs text-[#64748B]">Responsable: <span class="font-semibold text-[#172B3A]">{{ $actuacion->actor_nombre }}</span></p>
                        @if ($transicion)
                            <p class="mt-1 text-xs text-[#172B3A]">Estado: {{ $transicion[0] }} <i class="fa-solid fa-arrow-right mx-1 text-[10px] text-[#19A7A1]" aria-hidden="true"></i><span class="sr-only">a</span> {{ $transicion[1] }}</p>
                        @endif
                        @if ($detalles !== [])
                            <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-[#475569]">
                                @foreach ($detalles as $detalle)
                                    <li>{{ $detalle }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($actuacion->nota)
                            <p class="mt-2 whitespace-pre-line rounded-lg border border-[#E2E8F0] bg-[#F4F7FB]/70 p-3 text-sm text-[#172B3A]">{{ $actuacion->nota }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    {{-- G. Envíos y notificaciones al consumidor (sin cuerpos de correo, claves ni identificadores del proveedor) --}}
    <section class="admin-card sm:p-7" aria-labelledby="envios-titulo">
        <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#287FB5]/15 text-sm text-[#176B9A]">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                </span>
                <h2 id="envios-titulo" class="text-base font-bold text-[#172B3A]">G. Envíos y notificaciones</h2>
            </div>
            <span class="text-xs font-semibold text-[#64748B]">Constancia de lo remitido al consumidor</span>
        </div>
        @if ($reclamacion->envios->isEmpty())
            <p class="text-sm text-[#64748B]">No hay envíos registrados para esta reclamación.</p>
        @else
            <div class="admin-table-wrap" tabindex="0" role="region" aria-label="Envíos de la reclamación, desplazable horizontalmente">
                <table class="admin-table min-w-[720px]">
                    <caption class="sr-only">Envíos y notificaciones de la reclamación {{ $reclamacion->numero }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tipo</th>
                            <th scope="col">Canal</th>
                            <th scope="col">Destinatario</th>
                            <th scope="col">Fecha</th>
                            <th scope="col">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reclamacion->envios as $envio)
                            <tr data-envio-id="{{ $envio->id }}">
                                <td class="font-medium text-[#172B3A]">{{ \App\Support\PresentacionReclamacion::tipoEnvio($envio->tipo) }}</td>
                                <td>{{ \App\Support\PresentacionReclamacion::canal($envio->canal) }}</td>
                                <td class="text-sm">{{ \App\Support\PresentacionReclamacion::destinatario($reclamacion, $envio) }}</td>
                                <td class="text-sm">
                                    @if ($envio->enviado_at)
                                        {{ $envio->enviado_at->timezone('America/Lima')->format('d/m/Y H:i') }}
                                        <span class="block text-[11px] text-[#64748B]">Enviado (Lima)</span>
                                    @else
                                        {{ $envio->created_at?->timezone('America/Lima')->format('d/m/Y H:i') }}
                                        <span class="block text-[11px] text-[#64748B]">Registrado (Lima)</span>
                                    @endif
                                </td>
                                <td>
                                    <span @class([
                                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                                        'border-emerald-200 bg-emerald-50 text-emerald-800' => $envio->estado === 'enviado',
                                        'border-red-200 bg-red-50 text-red-700' => $envio->estado === 'fallido',
                                        'border-sky-200 bg-sky-50 text-sky-800' => ! in_array($envio->estado, ['enviado', 'fallido'], true),
                                    ])>{{ \App\Support\PresentacionReclamacion::estadoEnvio($envio->estado) }}</span>
                                    @if ($envio->intentos > 0)
                                        <span class="mt-1 block text-[11px] text-[#64748B]">{{ $envio->intentos }} {{ $envio->intentos === 1 ? 'intento' : 'intentos' }}</span>
                                    @endif
                                    @if ($envio->estado === 'fallido' && $envio->error_codigo)
                                        <span class="mt-0.5 block text-[11px] text-red-700">Código: {{ $envio->error_codigo }}</span>
                                    @endif
                                    @if ($envio->canal === 'carta' && $envio->constancia)
                                        <span class="mt-0.5 block text-[11px] text-[#64748B]">Constancia: {{ $envio->constancia }}</span>
                                    @endif
                                    @can('reclamaciones.gestionar')
                                        @if ($envio->estado === 'fallido' && $envio->canal === 'correo')
                                            <button type="button" data-admin-modal-open="gestion-reintento-{{ $envio->id }}" class="admin-btn-outline mt-2 min-h-8 px-3 py-1 text-xs">
                                                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reintentar
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @can('reclamaciones.gestionar')
                @php($operacionPrevia = $errors->any() ? (string) old('operacion') : '')
                @foreach ($reclamacion->envios->where('estado', 'fallido')->where('canal', 'correo') as $envio)
                    @php($reabrir = $operacionPrevia === 'reintento' && (string) old('envio_id') === (string) $envio->id)
                    <x-admin.form-modal id="gestion-reintento-{{ $envio->id }}" title="Reintentar envío" :auto-open="$reabrir">
                        @include('admin.reclamaciones._gestion-errores', ['mensajes' => $reabrir ? collect($errors->getMessages())->except('gestion')->flatten()->all() : []])
                        <form action="{{ route('admin.reclamaciones.gestionar', $reclamacion) }}" method="POST" class="space-y-5">
                            @csrf
                            <input type="hidden" name="operacion" value="reintento">
                            <input type="hidden" name="envio_id" value="{{ $envio->id }}">
                            <p class="text-sm text-[#475569]">Se reintentará el envío «{{ \App\Support\PresentacionReclamacion::tipoEnvio($envio->tipo) }}» con el mismo texto y el contador de intentos reiniciado.</p>
                            @include('admin.reclamaciones._gestion-aviso-correo', ['operacion' => $reabrir ? 'reintento' : '__ninguna__'])
                            <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                                <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reintentar envío</button>
                            </div>
                        </form>
                    </x-admin.form-modal>
                @endforeach
            @endcan
            @if (! \App\Support\Reclamaciones::envioHabilitado())
                <p class="mt-3 text-xs text-[#64748B]">El envío de correos del libro está desactivado: los correos registrados permanecen pendientes y no se remite nada.</p>
            @endif
        @endif
    </section>
</div>
@endsection
