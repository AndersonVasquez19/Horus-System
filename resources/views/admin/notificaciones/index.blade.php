@extends('admin.layouts.app')

@section('title', 'Notificaciones')
@section('breadcrumb', 'Notificaciones')

@section('content')
    <div class="space-y-6">
        <section class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Comunicaciones</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Notificaciones internas</h1>
            </div>
            <span class="rounded-full bg-gold/10 px-3 py-1.5 text-xs font-semibold text-gold-dark">{{ $pendientes }} pendientes</span>
        </section>

        <section class="space-y-4 rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
            @forelse ($notificaciones as $notificacion)
                <div class="flex flex-col gap-3 rounded-2xl border border-stone-200 p-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-2.5 w-2.5 rounded-full {{ $notificacion->leida ? 'bg-stone-300' : match($notificacion->tipo) { 'success' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'danger' => 'bg-red-500', default => 'bg-sky-500' } }}"></span>
                            <h2 class="text-base font-bold text-ink">{{ $notificacion->titulo }}</h2>
                        </div>
                        <p class="text-sm leading-6 text-stone-600">{{ $notificacion->mensaje }}</p>
                        <p class="text-xs text-stone-500">{{ $notificacion->created_at->format('d/m/Y H:i') }}</p>
                    </div>

                    <div class="flex gap-2">
                        @if (! $notificacion->leida)
                            <form action="{{ route('admin.notificaciones.leida', $notificacion) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-ink px-3 py-1.5 text-xs font-semibold text-white hover:bg-ink/90">
                                    <i class="fa-solid fa-check"></i>
                                    Marcar leída
                                </button>
                            </form>
                        @endif
                        @if ($notificacion->vinculo)
                            <a href="{{ $notificacion->vinculo }}" class="inline-flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                Ver
                            </a>
                        @endif
                    </div>
                </div>
            @empty
                <x-admin.empty-state title="Sin notificaciones" message="Cuando haya novedades internas, aparecerán aquí." icon="fa-bell" />
            @endforelse
        </section>

        @if ($notificaciones->hasPages())
            <div class="rounded-2xl border border-gold/20 bg-white px-5 py-3 shadow-sm">
                {{ $notificaciones->links() }}
            </div>
        @endif
    </div>
@endsection
