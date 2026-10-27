@extends('admin.layouts.app')

@section('title', 'Aprobaciones internas')
@section('breadcrumb', 'Aprobaciones internas')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Control interno</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Aprobaciones internas</h1>
            </div>

            @can('aprobaciones.gestionar')
                <button type="button" onclick="document.getElementById('form-nueva-aprobacion').scrollIntoView({behavior: 'smooth'});" class="inline-flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-plus"></i>
                    Nueva solicitud
                </button>
            @endcan
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-4 shadow-sm sm:p-5">
            <form method="GET" action="{{ route('admin.aprobaciones.index') }}" class="grid gap-3 md:grid-cols-[1fr_220px_140px] md:items-end">
                <div>
                    <label for="busqueda" class="mb-1 block text-sm font-medium text-stone-700">Buscar</label>
                    <input id="busqueda" name="busqueda" type="search" value="{{ $busqueda ?? '' }}" placeholder="Título, tipo o comentario" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
                <div>
                    <label for="estado" class="mb-1 block text-sm font-medium text-stone-700">Estado</label>
                    <select id="estado" name="estado" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20">
                        <option value="">Todos</option>
                        <option value="pendiente" {{ ($estado ?? '') === 'pendiente' ? 'selected' : '' }}>Pendiente</option>
                        <option value="aprobado" {{ ($estado ?? '') === 'aprobado' ? 'selected' : '' }}>Aprobado</option>
                        <option value="rechazado" {{ ($estado ?? '') === 'rechazado' ? 'selected' : '' }}>Rechazado</option>
                    </select>
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink hover:bg-gold-dark">
                    <i class="fa-solid fa-magnifying-glass mr-2"></i>
                    Filtrar
                </button>
            </form>
        </section>

        @can('aprobaciones.gestionar')
            <section id="form-nueva-aprobacion" class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-ink">Registrar solicitud</h2>
                <form method="POST" action="{{ route('admin.aprobaciones.store') }}" class="mt-4 grid gap-4 md:grid-cols-2">
                    @csrf
                    <div class="md:col-span-2">
                        <label for="titulo" class="mb-1 block text-sm font-medium text-stone-700">Título</label>
                        <input id="titulo" name="titulo" type="text" required class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                    </div>
                    <div>
                        <label for="tipo" class="mb-1 block text-sm font-medium text-stone-700">Tipo</label>
                        <input id="tipo" name="tipo" type="text" value="solicitud" required class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                    </div>
                    <div>
                        <label for="referencia" class="mb-1 block text-sm font-medium text-stone-700">Referencia</label>
                        <input id="referencia" name="referencia" type="text" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="descripcion" class="mb-1 block text-sm font-medium text-stone-700">Descripción</label>
                        <textarea id="descripcion" name="descripcion" rows="3" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20"></textarea>
                    </div>
                    <div>
                        <label for="solicitante_id" class="mb-1 block text-sm font-medium text-stone-700">Solicitante</label>
                        <select id="solicitante_id" name="solicitante_id" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20">
                            <option value="">Seleccionar</option>
                            @foreach ($usuarios as $usuario)
                                <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="aprobador_id" class="mb-1 block text-sm font-medium text-stone-700">Aprobador</label>
                        <select id="aprobador_id" name="aprobador_id" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20">
                            <option value="">Seleccionar</option>
                            @foreach ($usuarios as $usuario)
                                <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label for="comentario" class="mb-1 block text-sm font-medium text-stone-700">Comentario</label>
                        <textarea id="comentario" name="comentario" rows="2" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20"></textarea>
                    </div>
                    <div class="md:col-span-2 flex justify-end">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink hover:bg-gold-dark">
                            <i class="fa-solid fa-save"></i>
                            Guardar solicitud
                        </button>
                    </div>
                </form>
            </section>
        @endcan

        <section class="space-y-4">
            @forelse ($aprobaciones as $aprobacion)
                <article class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gold-dark">{{ $aprobacion->tipo }}</p>
                            <h2 class="mt-2 text-xl font-bold text-ink">{{ $aprobacion->titulo }}</h2>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ match($aprobacion->estado) { 'aprobado' => 'bg-emerald-100 text-emerald-700', 'rechazado' => 'bg-red-100 text-red-700', default => 'bg-amber-100 text-amber-700' } }}">
                            {{ $aprobacion->estado }}
                        </span>
                    </div>

                    <p class="mt-3 text-sm leading-6 text-stone-600">{{ $aprobacion->descripcion ?: 'Sin descripción adicional.' }}</p>

                    <dl class="mt-4 grid gap-3 text-sm text-stone-600 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="font-semibold text-stone-500">Solicitante</dt>
                            <dd>{{ $aprobacion->solicitante?->name ?? 'Sin asignar' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-500">Aprobador</dt>
                            <dd>{{ $aprobacion->aprobador?->name ?? 'Sin asignar' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-500">Referencia</dt>
                            <dd>{{ $aprobacion->referencia ?? 'Ninguna' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-500">Comentario</dt>
                            <dd>{{ $aprobacion->comentario ?: 'Sin comentarios' }}</dd>
                        </div>
                    </dl>

                    @can('aprobaciones.gestionar')
                        <div class="mt-5 flex flex-wrap gap-2">
                            <form action="{{ route('admin.aprobaciones.update', $aprobacion) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="titulo" value="{{ $aprobacion->titulo }}">
                                <input type="hidden" name="tipo" value="{{ $aprobacion->tipo }}">
                                <input type="hidden" name="descripcion" value="{{ $aprobacion->descripcion ?? '' }}">
                                <input type="hidden" name="solicitante_id" value="{{ $aprobacion->solicitante_id }}">
                                <input type="hidden" name="aprobador_id" value="{{ $aprobacion->aprobador_id }}">
                                <input type="hidden" name="comentario" value="{{ $aprobacion->comentario ?? '' }}">
                                <input type="hidden" name="referencia" value="{{ $aprobacion->referencia ?? '' }}">
                                <input type="hidden" name="estado" value="aprobado">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                    <i class="fa-solid fa-circle-check"></i>
                                    Aprobar
                                </button>
                            </form>
                            <form action="{{ route('admin.aprobaciones.update', $aprobacion) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="titulo" value="{{ $aprobacion->titulo }}">
                                <input type="hidden" name="tipo" value="{{ $aprobacion->tipo }}">
                                <input type="hidden" name="descripcion" value="{{ $aprobacion->descripcion ?? '' }}">
                                <input type="hidden" name="solicitante_id" value="{{ $aprobacion->solicitante_id }}">
                                <input type="hidden" name="aprobador_id" value="{{ $aprobacion->aprobador_id }}">
                                <input type="hidden" name="comentario" value="{{ $aprobacion->comentario ?? '' }}">
                                <input type="hidden" name="referencia" value="{{ $aprobacion->referencia ?? '' }}">
                                <input type="hidden" name="estado" value="rechazado">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                    Rechazar
                                </button>
                            </form>
                            <form action="{{ route('admin.aprobaciones.destroy', $aprobacion) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta aprobación?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100">
                                    <i class="fa-solid fa-trash"></i>
                                    Eliminar
                                </button>
                            </form>
                        </div>
                    @endcan
                </article>
            @empty
                <div class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
                    <x-admin.empty-state title="No hay aprobaciones" message="Las solicitudes de aprobación aparecerán aquí." icon="fa-check-to-slot" />
                </div>
            @endforelse
        </section>

        @if ($aprobaciones->hasPages())
            <div class="rounded-2xl border border-gold/20 bg-white px-5 py-3 shadow-sm">
                {{ $aprobaciones->links() }}
            </div>
        @endif
    </div>
@endsection
