@extends('admin.layouts.app')

@section('title', 'Notificaciones')
@section('breadcrumb', 'Notificaciones')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Comunicaciones</p>
                <h1 class="admin-page-title">Notificaciones internas</h1>
                <p class="admin-page-subtitle">Consulta los avisos operativos y confirma su lectura.</p>
            </div>
            <span class="inline-flex items-center gap-2 rounded-full border border-[#19A7A1]/30 bg-[#19A7A1]/10 px-3.5 py-1.5 text-xs font-semibold text-[#0F766E]">
                <span class="h-2 w-2 rounded-full bg-[#19A7A1]" aria-hidden="true"></span>
                {{ $pendientes }} pendientes
            </span>
        </section>

        <section class="space-y-3 admin-card">
            @forelse ($notificaciones as $notificacion)
                <div class="flex flex-col gap-3 rounded-xl border {{ $notificacion->leida ? 'border-[#E2E8F0] bg-white' : 'border-[#287FB5]/30 bg-[#F4F7FB]' }} p-4 transition sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2.5">
                            <span class="inline-flex h-2.5 w-2.5 shrink-0 rounded-full {{ $notificacion->leida ? 'bg-slate-300' : match($notificacion->tipo) { 'success' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'danger' => 'bg-red-500', default => 'bg-[#19A7A1]' } }}"></span>
                            <h2 class="text-base font-bold text-[#172B3A]">{{ $notificacion->titulo }}</h2>
                        </div>
                        <p class="text-sm leading-6 text-[#64748B]">{{ $notificacion->mensaje }}</p>
                        <p class="text-xs font-medium text-[#64748B]">{{ $notificacion->created_at->format('d/m/Y H:i') }}</p>
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if (! $notificacion->leida)
                            <form action="{{ route('admin.notificaciones.leida', $notificacion) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="admin-btn-primary min-h-9 px-3.5 py-1.5 text-xs">
                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                    Marcar leída
                                </button>
                            </form>
                        @endif
                        @if ($notificacion->vinculo)
                            <a href="{{ $notificacion->vinculo }}" class="admin-btn-outline min-h-9 px-3.5 py-1.5 text-xs">
                                <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
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
            <div class="admin-card px-5 py-4">
                {{ $notificaciones->links() }}
            </div>
        @endif
    </div>
@endsection
