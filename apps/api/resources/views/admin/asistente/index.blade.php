@extends('admin.layouts.app')

@section('title', 'Asistente Virtual')
@section('breadcrumb', 'Asistente Virtual')

@section('content')
    <div class="space-y-6">
        <section>
            <p class="admin-eyebrow">Atención al usuario</p>
            <h1 class="admin-page-title">Asistente Virtual</h1>
            <p class="admin-page-subtitle">Prueba Horus AI Python y revisa las consultas recientes. Si está deshabilitado o no disponible, se utiliza la contingencia institucional.</p>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
            <div class="admin-card-flush">
                <div class="flex items-center gap-3 border-b border-[#E2E8F0] px-5 py-4">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#19A7A1]/15 text-[#19A7A1]">
                        <i class="fa-solid fa-robot" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h2 class="font-bold text-[#172B3A]">Conversación de prueba</h2>
                        <p class="text-xs text-[#64748B]">El historial de esta conversación no se guarda en el navegador.</p>
                    </div>
                </div>
                <div class="h-[520px]">
                    <x-chat.conversation :endpoint="route('admin.asistente.mensaje')" />
                </div>
            </div>

            <div class="admin-card-flush">
                <div class="border-b border-[#E2E8F0] px-5 py-4">
                    <h2 class="font-bold text-[#172B3A]">Registro histórico de intenciones</h2>
                    <p class="mt-0.5 text-xs text-[#64748B]">Estas reglas PHP se conservan para consulta histórica y no generan respuestas públicas.</p>
                </div>
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[400px]">
                        <thead>
                            <tr>
                                <th scope="col">Intención</th>
                                <th scope="col">Prioridad</th>
                                <th scope="col">Activa</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($intents as $intent)
                                <tr>
                                    <td>
                                        <span class="block font-semibold text-[#172B3A]">{{ str_replace('_', ' ', $intent->nombre) }}</span>
                                        <span class="mt-0.5 block max-w-xs truncate text-xs text-[#64748B]">{{ $intent->respuesta }}</span>
                                    </td>
                                    <td class="font-semibold text-[#172B3A]">{{ $intent->prioridad }}</td>
                                    <td>
                                        <input type="checkbox" disabled @checked($intent->activo) aria-label="Intención activa" class="admin-checkbox disabled:opacity-100">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="admin-card-flush">
            <div class="flex flex-col gap-3 border-b border-[#E2E8F0] p-4 sm:flex-row sm:items-end sm:justify-between sm:p-5">
                <div>
                    <h2 class="font-bold text-[#172B3A]">Consultas recientes</h2>
                    <p class="mt-0.5 text-xs text-[#64748B]">Se muestran hasta 50 interacciones guardadas.</p>
                </div>
                <form method="GET" action="{{ route('admin.asistente.index') }}" class="flex flex-wrap items-center gap-2">
                    <label class="block">
                        <span class="sr-only">Filtrar por intención</span>
                        <select name="intent" class="admin-select min-w-48">
                            <option value="">Todas las intenciones</option>
                            @foreach ($intents as $intent)
                                <option value="{{ $intent->nombre }}" @selected($intentSeleccionado === $intent->nombre)>
                                    {{ str_replace('_', ' ', $intent->nombre) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="admin-btn-secondary">
                        <i class="fa-solid fa-filter text-xs text-[#19A7A1]" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    @if ($intentSeleccionado !== '')
                        <a href="{{ route('admin.asistente.index') }}" class="admin-btn-outline">
                            Limpiar
                        </a>
                    @endif
                </form>
            </div>

            @if ($logs->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-admin.empty-state title="Sin consultas registradas" message="Las interacciones del asistente aparecerán aquí." icon="fa-comments" />
                </div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table min-w-[800px]">
                        <thead>
                            <tr>
                                <th scope="col">Fecha</th>
                                <th scope="col">Mensaje</th>
                                <th scope="col">Respuesta</th>
                                <th scope="col">Intención</th>
                                <th scope="col">Fuente</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($logs as $log)
                                <tr>
                                    <td class="whitespace-nowrap">{{ \App\Support\HoraLocal::formato($log->created_at) }}</td>
                                    <td class="max-w-xs whitespace-pre-line font-medium text-[#172B3A]">{{ $log->mensaje_usuario }}</td>
                                    <td class="max-w-md whitespace-pre-line">{{ $log->respuesta_bot }}</td>
                                    <td>
                                        @if ($log->intent_detectado)
                                            <span class="inline-flex rounded-full border border-[#19A7A1]/30 bg-[#19A7A1]/10 px-2.5 py-1 text-xs font-semibold text-[#0F766E]">{{ str_replace('_', ' ', $log->intent_detectado) }}</span>
                                        @else
                                            <span class="text-xs text-[#64748B]">Sin coincidencia</span>
                                        @endif
                                    </td>
                                    <td>{{ ['advisor' => 'Horus AI Python', 'respaldo' => 'Contingencia institucional'][$log->fuente] ?? 'No registrada' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
