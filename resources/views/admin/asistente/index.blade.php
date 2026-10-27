@extends('admin.layouts.app')

@section('title', 'Asistente Virtual')
@section('breadcrumb', 'Asistente Virtual')

@section('content')
    <div class="space-y-6">
        <section>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">Atención al usuario</p>
            <h1 class="mt-1 text-2xl font-bold text-ink">Asistente Virtual</h1>
            <p class="mt-1 text-sm text-stone-600">Prueba las respuestas por reglas y revisa las consultas recientes.</p>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
            <div class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
                <div class="flex items-center gap-3 border-b border-stone-100 px-5 py-4">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gold/15 text-gold-dark">
                        <i class="fa-solid fa-robot" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h2 class="font-bold text-ink">Conversación de prueba</h2>
                        <p class="text-xs text-stone-500">El historial de esta conversación no se guarda en el navegador.</p>
                    </div>
                </div>
                <div class="h-[520px]">
                    <x-chat.conversation :endpoint="route('admin.asistente.mensaje')" />
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
                <div class="border-b border-stone-100 px-5 py-4">
                    <h2 class="font-bold text-ink">Intenciones configuradas</h2>
                    <p class="mt-1 text-sm text-stone-500">Respuestas automáticas ordenadas por prioridad.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[400px] text-left text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Intención</th>
                                <th class="px-5 py-3 font-semibold">Prioridad</th>
                                <th class="px-5 py-3 font-semibold">Activa</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($intents as $intent)
                                <tr>
                                    <td class="px-5 py-3">
                                        <span class="block font-semibold text-ink">{{ str_replace('_', ' ', $intent->nombre) }}</span>
                                        <span class="mt-1 block max-w-xs truncate text-xs text-stone-500">{{ $intent->respuesta }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-stone-600">{{ $intent->prioridad }}</td>
                                    <td class="px-5 py-3">
                                        <input type="checkbox" disabled @checked($intent->activo) aria-label="Intención activa" class="h-4 w-4 rounded border-stone-300 text-gold focus:ring-gold disabled:opacity-100">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-stone-100 p-4 sm:flex-row sm:items-end sm:justify-between sm:p-5">
                <div>
                    <h2 class="font-bold text-ink">Consultas recientes</h2>
                    <p class="mt-1 text-sm text-stone-500">Se muestran hasta 50 interacciones guardadas.</p>
                </div>
                <form method="GET" action="{{ route('admin.asistente.index') }}" class="flex flex-wrap gap-2">
                    <label>
                        <span class="sr-only">Filtrar por intención</span>
                        <select name="intent" class="w-full min-w-48 rounded-xl border border-stone-200 bg-white px-4 py-2.5 text-sm outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
                            <option value="">Todas las intenciones</option>
                            @foreach ($intents as $intent)
                                <option value="{{ $intent->nombre }}" @selected($intentSeleccionado === $intent->nombre)>
                                    {{ str_replace('_', ' ', $intent->nombre) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-stone-800">Filtrar</button>
                </form>
            </div>

            @if ($logs->isEmpty())
                <div class="p-5">
                    <x-admin.empty-state title="Sin consultas registradas" message="Las interacciones del asistente aparecerán aquí." icon="fa-comments" />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Fecha</th>
                                <th class="px-5 py-3 font-semibold">Mensaje</th>
                                <th class="px-5 py-3 font-semibold">Respuesta</th>
                                <th class="px-5 py-3 font-semibold">Intención</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($logs as $log)
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-4 text-stone-500">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="max-w-xs whitespace-pre-line px-5 py-4 text-ink">{{ $log->mensaje_usuario }}</td>
                                    <td class="max-w-md whitespace-pre-line px-5 py-4 text-stone-600">{{ $log->respuesta_bot }}</td>
                                    <td class="px-5 py-4">
                                        @if ($log->intent_detectado)
                                            <span class="rounded-full bg-gold/10 px-2.5 py-1 text-xs font-semibold text-gold-dark">{{ str_replace('_', ' ', $log->intent_detectado) }}</span>
                                        @else
                                            <span class="text-xs text-stone-400">Sin coincidencia</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
