@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Aprobaciones internas')
@section('breadcrumb', 'Aprobaciones internas')

@php
    $etiquetasTipo = [
        'compra' => 'Compra / Presupuesto',
        'operativo' => 'Operativo / Académico',
    ];
    $etiquetasRol = [
        'admin' => 'Gerencia',
        'coordinador' => 'Coordinación',
    ];
    $usuario = auth()->user();
    $camposFormulario = ['titulo', 'tipo', 'descripcion', 'comentario', 'referencia', 'solicitante_id', 'aprobador_id', 'estado'];
    $formularioConErrores = $errors->hasAny($camposFormulario) && old('_form') === 'nueva';
@endphp

@section('content')
    <div class="space-y-6" x-data="{ formOpen: @js($formularioConErrores) }">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Control interno</p>
                <h1 class="admin-page-title">Aprobaciones internas</h1>
                <p class="admin-page-subtitle">Registra, evalúa y da seguimiento a las solicitudes de aprobación según la matriz de autoridad.</p>
            </div>

            @can('aprobaciones.gestionar')
                <button
                    type="button"
                    @click="formOpen = true"
                    :aria-expanded="formOpen.toString()"
                    aria-controls="form-nueva-aprobacion"
                    class="admin-btn-primary w-full justify-center sm:w-auto"
                    :class="formOpen && '!bg-white !text-[#176B9A] ring-1 ring-[#176B9A]/40 hover:!bg-[#F4F7FB]'"
                >
                    <i class="fa-solid" :class="formOpen ? 'fa-xmark' : 'fa-plus'" aria-hidden="true"></i>
                    <span x-text="formOpen ? 'Cerrar formulario' : 'Nueva solicitud'">Nueva solicitud</span>
                </button>
            @endcan
        </section>

        {{-- Errores de validación (el layout solo muestra avisos de sesión) --}}
        @if ($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                <p class="flex items-center gap-2 font-semibold">
                    <i class="fa-solid fa-circle-exclamation text-red-600" aria-hidden="true"></i>
                    No se pudo completar la operación:
                </p>
                <ul class="mt-2 list-disc space-y-0.5 pl-6 text-xs leading-5">
                    @foreach ($errors->all() as $mensaje)
                        <li>{{ $mensaje }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Filtros --}}
        <section class="admin-card">
            <form method="GET" action="{{ route('admin.aprobaciones.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_200px_200px_auto] lg:items-end">
                <div class="sm:col-span-2 lg:col-span-1">
                    <label for="busqueda" class="admin-label">Buscar</label>
                    <input id="busqueda" name="busqueda" type="search" value="{{ $busqueda ?? '' }}" placeholder="Título, tipo, comentario o referencia" class="admin-input" />
                </div>
                <div>
                    <label for="tipo" class="admin-label">Tipo de solicitud</label>
                    <select id="tipo" name="tipo" class="admin-select">
                        <option value="">Todos los tipos</option>
                        @foreach (\App\Models\AprobacionInterna::TIPOS_AUTORIDAD as $valorTipo => $rolTipo)
                            <option value="{{ $valorTipo }}" @selected(($tipo ?? '') === $valorTipo)>
                                {{ $etiquetasTipo[$valorTipo] ?? ucfirst($valorTipo) }} ({{ $etiquetasRol[$rolTipo] ?? $rolTipo }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="estado" class="admin-label">Estado</label>
                    <select id="estado" name="estado" class="admin-select">
                        <option value="">Todos los estados</option>
                        <option value="pendiente" @selected(($estado ?? '') === 'pendiente')>Pendiente</option>
                        <option value="aprobado" @selected(($estado ?? '') === 'aprobado')>Aprobado</option>
                        <option value="rechazado" @selected(($estado ?? '') === 'rechazado')>Rechazado</option>
                    </select>
                </div>
                <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                    <button type="submit" class="admin-btn-secondary flex-1 justify-center lg:flex-none">
                        <i class="fa-solid fa-magnifying-glass text-xs text-[#19A7A1]" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    @if (($busqueda ?? '') !== '' || ($tipo ?? '') !== '' || ($estado ?? '') !== '')
                        <a href="{{ route('admin.aprobaciones.index') }}" class="admin-btn-outline justify-center" title="Quitar filtros">
                            <i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>
                            <span class="sr-only">Quitar filtros</span>
                        </a>
                    @endif
                </div>
            </form>
        </section>

        {{-- Registro de solicitud (plegable) --}}
        @can('aprobaciones.gestionar')
            <x-admin.form-modal id="form-nueva-aprobacion" title="Nueva solicitud" state="formOpen" on-close="formOpen = false">
                @if ($formularioConErrores)
                    <ul role="alert" class="admin-error mb-4 list-inside list-disc">
                        @foreach ($errors->all() as $mensaje)<li>{{ $mensaje }}</li>@endforeach
                    </ul>
                @endif
                <div class="flex flex-col gap-3 border-b border-[#E2E8F0] pb-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-[#172B3A]">Registrar nueva solicitud</h2>
                        <p class="text-xs text-[#64748B]">Las decisiones se asignan a Gerencia o Coordinación según el tipo seleccionado.</p>
                    </div>
                    <span class="w-fit rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3 py-1 text-xs font-semibold text-[#176B9A]">
                        Solicitante: {{ $usuario?->name }}
                    </span>
                </div>

                <form method="POST" action="{{ route('admin.aprobaciones.store') }}" class="mt-4 grid gap-4 md:grid-cols-2">
                    @csrf
                    <input type="hidden" name="_form" value="nueva">
                    <div class="md:col-span-2">
                        <label for="titulo" class="admin-label">Título de la solicitud <span class="text-red-600">*</span></label>
                        <input id="titulo" x-ref="tituloNueva" name="titulo" type="text" required maxlength="255" value="{{ old('_form') === 'nueva' ? old('titulo') : '' }}" placeholder="Ej. Adquisición de materiales para el campus" class="admin-input" />
                    </div>

                    <div>
                        <label for="tipo_form" class="admin-label">Tipo de solicitud <span class="text-red-600">*</span></label>
                        <select id="tipo_form" name="tipo" required class="admin-select">
                            @foreach (\App\Models\AprobacionInterna::TIPOS_AUTORIDAD as $valorTipo => $rolTipo)
                                <option value="{{ $valorTipo }}" @selected(old('tipo') === $valorTipo)>
                                    {{ $etiquetasTipo[$valorTipo] ?? ucfirst($valorTipo) }} (Autoridad: {{ $etiquetasRol[$rolTipo] ?? $rolTipo }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="referencia" class="admin-label">Código o referencia</label>
                        <input id="referencia" name="referencia" type="text" maxlength="255" value="{{ old('_form') === 'nueva' ? old('referencia') : '' }}" placeholder="Ej. OC-2026-041" class="admin-input" />
                    </div>

                    <div class="md:col-span-2">
                        <label for="descripcion" class="admin-label">Descripción detallada</label>
                        <textarea id="descripcion" name="descripcion" rows="3" maxlength="5000" placeholder="Detalla los motivos, montos o necesidades de la solicitud..." class="admin-textarea">{{ old('_form') === 'nueva' ? old('descripcion') : '' }}</textarea>
                    </div>

                    <div class="md:col-span-2">
                        <label for="comentario" class="admin-label">Comentario u observaciones iniciales</label>
                        <textarea id="comentario" name="comentario" rows="2" maxlength="2000" placeholder="Observaciones complementarias..." class="admin-textarea">{{ old('_form') === 'nueva' ? old('comentario') : '' }}</textarea>
                    </div>

                    <div class="flex flex-col-reverse gap-2 border-t border-[#E2E8F0] pt-4 sm:flex-row sm:justify-end md:col-span-2">
                        <button type="button" @click="formOpen = false" class="admin-btn-outline justify-center">
                            Cancelar
                        </button>
                        <button type="submit" class="admin-btn-primary justify-center">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                            Enviar solicitud
                        </button>
                    </div>
                </form>
            </x-admin.form-modal>
        @endcan

        {{-- Listado --}}
        <section class="space-y-4">
            @forelse ($aprobaciones as $aprobacion)
                @php
                    $rolAutorizado = $aprobacion->rolAutorizado();
                    $historial = $historiales->get($aprobacion->id, collect());
                    $esPropia = $aprobacion->esSolicitante($usuario);
                @endphp
                <article class="admin-card transition duration-150 hover:border-[#176B9A]/40" x-data="{ showHistory: false, panel: @js($errors->any() && old('_modal') === 'rechazar-'.$aprobacion->id ? 'rechazar' : ($errors->any() && old('_modal') === 'reabrir-'.$aprobacion->id ? 'reabrir' : null)) }">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'rounded-lg border px-2.5 py-0.5 text-xs font-bold uppercase tracking-wider',
                                    'border-[#176B9A]/30 bg-[#176B9A]/10 text-[#176B9A]' => $aprobacion->tipo === 'compra',
                                    'border-[#19A7A1]/30 bg-[#19A7A1]/10 text-[#13807B]' => $aprobacion->tipo === 'operativo',
                                    'border-slate-200 bg-slate-50 text-slate-700' => $rolAutorizado === null,
                                ])>
                                    {{ $etiquetasTipo[$aprobacion->tipo] ?? $aprobacion->tipo }}
                                </span>
                                @if ($aprobacion->referencia)
                                    <span class="rounded-lg border border-[#E2E8F0] bg-[#F4F7FB] px-2 py-0.5 font-mono text-xs font-medium text-[#64748B]">
                                        {{ $aprobacion->referencia }}
                                    </span>
                                @endif
                                <span class="text-xs text-[#64748B]">
                                    <i class="fa-regular fa-clock mr-1" aria-hidden="true"></i>
                                    Creada el {{ \App\Support\HoraLocal::formato($aprobacion->created_at) }}
                                </span>
                            </div>
                            <h2 class="mt-2 break-words text-lg font-bold text-[#172B3A]">{{ $aprobacion->titulo }}</h2>
                        </div>

                        <span @class([
                            'w-fit shrink-0 rounded-full border px-3 py-1 text-xs font-bold uppercase tracking-wide',
                            'border-emerald-200 bg-emerald-50 text-emerald-700' => $aprobacion->estado === 'aprobado',
                            'border-red-200 bg-red-50 text-red-700' => $aprobacion->estado === 'rechazado',
                            'border-amber-200 bg-amber-50 text-amber-800' => ! in_array($aprobacion->estado, ['aprobado', 'rechazado'], true),
                        ])>
                            <i class="fa-solid mr-1 {{ match ($aprobacion->estado) { 'aprobado' => 'fa-circle-check', 'rechazado' => 'fa-circle-xmark', default => 'fa-clock' } }}" aria-hidden="true"></i>
                            {{ $aprobacion->estado }}
                        </span>
                    </div>

                    <p class="mt-3 break-words text-sm leading-6 text-[#475569]">{{ $aprobacion->descripcion ?: 'Sin descripción adicional.' }}</p>

                    @if ($aprobacion->estado === 'rechazado' && $aprobacion->comentario)
                        <div class="mt-3 rounded-xl border border-red-200 bg-red-50/70 p-3 text-sm text-red-800">
                            <p class="flex items-center gap-2 font-semibold">
                                <i class="fa-solid fa-triangle-exclamation text-red-600" aria-hidden="true"></i>
                                Motivo de rechazo
                            </p>
                            <p class="mt-1 break-words text-xs leading-5 text-red-700">{{ $aprobacion->comentario }}</p>
                        </div>
                    @elseif ($aprobacion->comentario)
                        <p class="mt-2 break-words text-xs leading-5 text-[#64748B]"><span class="font-semibold text-[#172B3A]">Comentario:</span> {{ $aprobacion->comentario }}</p>
                    @endif

                    <dl class="mt-4 grid gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-3.5 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Solicitante</dt>
                            <dd class="mt-0.5 flex items-center gap-1.5 truncate font-medium text-[#172B3A]">
                                <i class="fa-regular fa-user text-[#176B9A]" aria-hidden="true"></i>
                                {{ $aprobacion->solicitante?->name ?? 'Sin registrar' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Autoridad competente</dt>
                            <dd class="mt-0.5 font-medium text-[#172B3A]">
                                {{ $rolAutorizado !== null ? $etiquetasRol[$rolAutorizado] : 'Sin autoridad asignada' }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Decidido por</dt>
                            <dd class="mt-0.5 truncate font-medium text-[#172B3A]">
                                {{ $aprobacion->aprobador?->name ?? ($aprobacion->estado === 'pendiente' ? 'Pendiente de decisión' : 'Sin registrar') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Última actualización</dt>
                            <dd class="mt-0.5 text-xs text-[#64748B]">{{ \App\Support\HoraLocal::formato($aprobacion->updated_at) }}</dd>
                        </div>
                    </dl>

                    {{-- Historial de decisiones (AuditLog) --}}
                    @if ($historial->isNotEmpty())
                        <div class="mt-3 border-t border-[#E2E8F0] pt-2">
                            <button type="button" @click="showHistory = ! showHistory" :aria-expanded="showHistory.toString()" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#176B9A] hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#176B9A]/40">
                                <i class="fa-solid" :class="showHistory ? 'fa-chevron-up' : 'fa-clock-rotate-left'" aria-hidden="true"></i>
                                <span x-text="showHistory ? 'Ocultar historial' : 'Ver historial de decisiones ({{ $historial->count() }})'">Ver historial de decisiones ({{ $historial->count() }})</span>
                            </button>

                            <div x-show="showHistory" x-cloak class="mt-3 rounded-xl border border-[#E2E8F0] bg-white p-3 text-xs">
                                <ul class="divide-y divide-[#E2E8F0]">
                                    @foreach ($historial as $evento)
                                        @php($cambios = $evento->redactedChanges())
                                        <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <span class="font-semibold text-[#172B3A]">
                                                    {{ match ($evento->action) {
                                                        'aprobacion_creada' => 'Solicitud registrada',
                                                        'aprobacion_actualizada' => 'Datos actualizados',
                                                        'aprobacion_aprobada' => 'Aprobada',
                                                        'aprobacion_rechazada' => 'Rechazada',
                                                        'aprobacion_reabierta' => 'Reabierta',
                                                        default => $evento->action,
                                                    } }}
                                                </span>
                                                <span class="text-[#64748B]">por {{ $evento->user?->name ?? 'Usuario eliminado' }}</span>
                                                @if (! empty($cambios['estado_anterior']) && ! empty($cambios['estado_nuevo']) && $cambios['estado_anterior'] !== $cambios['estado_nuevo'])
                                                    <span class="text-[#94A3B8]">({{ $cambios['estado_anterior'] }} → {{ $cambios['estado_nuevo'] }})</span>
                                                @endif
                                                @if (! empty($cambios['motivo']))
                                                    <p class="mt-0.5 break-words text-amber-800">Motivo: «{{ $cambios['motivo'] }}»</p>
                                                @elseif (! empty($cambios['comentario']))
                                                    <p class="mt-0.5 break-words text-[#64748B]">Comentario: «{{ $cambios['comentario'] }}»</p>
                                                @endif
                                            </div>
                                            <span class="whitespace-nowrap text-[11px] text-[#94A3B8]">{{ \App\Support\HoraLocal::formato($evento->created_at, 'd/m/Y H:i:s') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    {{-- Acciones: solo las que el servidor aceptaría para este usuario --}}
                    @can('aprobaciones.ver')
                        <div class="mt-4 border-t border-[#E2E8F0] pt-4">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($aprobacion->estado === 'pendiente')
                                    @if ($esPropia)
                                        <span class="inline-flex items-center gap-1.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">
                                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                            Tu solicitud espera la decisión de {{ $rolAutorizado !== null ? $etiquetasRol[$rolAutorizado] : 'la autoridad competente' }}.
                                        </span>
                                    @elseif ($aprobacion->puedeResolver($usuario))
                                        <form action="{{ route('admin.aprobaciones.aprobar', $aprobacion) }}" method="POST" data-confirm="¿Confirmas que deseas aprobar esta solicitud? La decisión quedará registrada.">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-9 items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                                Aprobar
                                            </button>
                                        </form>
                                        <button type="button" @click="panel = panel === 'rechazar' ? null : 'rechazar'" :aria-expanded="(panel === 'rechazar').toString()" class="admin-btn-danger min-h-9 px-3.5 py-1.5 text-xs">
                                            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
                                            Rechazar
                                        </button>
                                    @elseif ($rolAutorizado === null)
                                        <span class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-600">
                                            <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                                            Tipo sin autoridad asignada: nadie puede resolverla hasta que se reclasifique.
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-600">
                                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                            Resolución reservada a {{ $etiquetasRol[$rolAutorizado] }}.
                                        </span>
                                    @endif

                                    @if ($esPropia || $usuario?->hasRole('admin'))
                                        <form action="{{ route('admin.aprobaciones.destroy', $aprobacion) }}" method="POST" class="sm:ml-auto" data-confirm="¿Deseas eliminar esta solicitud pendiente? Quedará constancia en la auditoría.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="admin-btn-outline min-h-9 px-3.5 py-1.5 text-xs">
                                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                Eliminar
                                            </button>
                                        </form>
                                    @endif
                                @else
                                    @if ($aprobacion->puedeReabrir($usuario))
                                        <button type="button" @click="panel = panel === 'reabrir' ? null : 'reabrir'" :aria-expanded="(panel === 'reabrir').toString()" class="inline-flex min-h-9 items-center gap-2 rounded-xl border border-[#176B9A]/30 bg-[#F4F7FB] px-3.5 py-1.5 text-xs font-semibold text-[#176B9A] transition hover:bg-[#176B9A]/10">
                                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                            Reabrir solicitud
                                        </button>
                                    @endif
                                    <span class="flex items-center gap-1 text-xs italic text-[#64748B] sm:ml-auto">
                                        <i class="fa-solid fa-check-double text-emerald-600" aria-hidden="true"></i>
                                        Decisión registrada.
                                    </span>
                                @endif
                            </div>

                            {{-- Motivo obligatorio para rechazar --}}
                            @if ($aprobacion->estado === 'pendiente' && ! $esPropia && $aprobacion->puedeResolver($usuario))
                                <x-admin.form-modal id="rechazar-{{ $aprobacion->id }}" title="Rechazar solicitud" state="panel === 'rechazar'" on-close="panel = null">
                                    <form action="{{ route('admin.aprobaciones.rechazar', $aprobacion) }}" method="POST" data-confirm="¿Confirmas el rechazo de esta solicitud?">
                                        @csrf
                                        <input type="hidden" name="_modal" value="rechazar-{{ $aprobacion->id }}">
                                        <label for="motivo-rechazo-{{ $aprobacion->id }}" class="admin-label">Motivo del rechazo <span class="text-red-600">*</span></label>
                                        <textarea id="motivo-rechazo-{{ $aprobacion->id }}" name="motivo" rows="2" required minlength="3" maxlength="1000" class="admin-textarea" placeholder="Explica por qué se rechaza; el solicitante lo verá.">{{ old('_modal') === 'rechazar-'.$aprobacion->id ? OldInput::value('motivo') : '' }}</textarea>
                                        @if (old('_modal') === 'rechazar-'.$aprobacion->id) @error('motivo')<p class="admin-error" role="alert">{{ $message }}</p>@enderror @endif
                                        <div class="mt-2 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                            <button type="button" @click="panel = null" class="admin-btn-outline min-h-9 justify-center px-3.5 py-1.5 text-xs">Cancelar</button>
                                            <button type="submit" class="admin-btn-danger min-h-9 justify-center px-3.5 py-1.5 text-xs">Confirmar rechazo</button>
                                        </div>
                                    </form>
                                </x-admin.form-modal>
                            @endif

                            {{-- Motivo obligatorio para reabrir --}}
                            @if ($aprobacion->estado !== 'pendiente' && $aprobacion->puedeReabrir($usuario))
                                <x-admin.form-modal id="reabrir-{{ $aprobacion->id }}" title="Reabrir solicitud" state="panel === 'reabrir'" on-close="panel = null">
                                    <form action="{{ route('admin.aprobaciones.reabrir', $aprobacion) }}" method="POST" data-confirm="¿Confirmas la reapertura? La decisión anterior se conservará en el historial.">
                                        @csrf
                                        <input type="hidden" name="_modal" value="reabrir-{{ $aprobacion->id }}">
                                        <label for="motivo-reapertura-{{ $aprobacion->id }}" class="admin-label">Motivo de la reapertura <span class="text-red-600">*</span></label>
                                        <textarea id="motivo-reapertura-{{ $aprobacion->id }}" name="motivo" rows="2" required minlength="3" maxlength="1000" class="admin-textarea" placeholder="Indica por qué debe evaluarse de nuevo.">{{ old('_modal') === 'reabrir-'.$aprobacion->id ? OldInput::value('motivo') : '' }}</textarea>
                                        @if (old('_modal') === 'reabrir-'.$aprobacion->id) @error('motivo')<p class="admin-error" role="alert">{{ $message }}</p>@enderror @endif
                                        <div class="mt-2 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                            <button type="button" @click="panel = null" class="admin-btn-outline min-h-9 justify-center px-3.5 py-1.5 text-xs">Cancelar</button>
                                            <button type="submit" class="admin-btn-primary min-h-9 justify-center px-3.5 py-1.5 text-xs">Confirmar reapertura</button>
                                        </div>
                                    </form>
                                </x-admin.form-modal>
                            @endif
                        </div>
                    @endcan
                </article>
            @empty
                <div class="admin-card">
                    <x-admin.empty-state title="No hay aprobaciones" message="Las solicitudes de aprobación aparecerán aquí." icon="fa-check-to-slot" />
                </div>
            @endforelse
        </section>

        @if ($aprobaciones->hasPages())
            <div class="admin-card px-5 py-4">
                {{ $aprobaciones->links() }}
            </div>
        @endif
    </div>
@endsection
