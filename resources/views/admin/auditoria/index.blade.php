@extends('admin.layouts.app')

@section('title', 'Auditoría')
@section('breadcrumb', 'Auditoría')

@section('content')
    <div class="space-y-6">
        <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.12em] text-gold-dark">Seguridad</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Auditoría del sistema</h1>
            </div>

            @can('auditoria.exportar')
                <a href="{{ route('admin.auditoria.exportar') }}" class="inline-flex items-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-file-export"></i>
                    Exportar JSON
                </a>
            @endcan
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-4 shadow-sm sm:p-5">
            <form method="GET" action="{{ route('admin.auditoria.index') }}" class="grid gap-3 md:grid-cols-4 md:items-end">
                <div>
                    <label for="accion" class="mb-1 block text-sm font-medium text-stone-700">Acción</label>
                    <input id="accion" name="accion" type="text" value="{{ $accion ?? '' }}" placeholder="Ej. login" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
                <div>
                    <label for="modelo" class="mb-1 block text-sm font-medium text-stone-700">Modelo</label>
                    <input id="modelo" name="modelo" type="text" value="{{ $modelo ?? '' }}" placeholder="Ej. User" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
                <div>
                    <label for="usuario" class="mb-1 block text-sm font-medium text-stone-700">Usuario</label>
                    <input id="usuario" name="usuario" type="text" value="{{ $usuario ?? '' }}" placeholder="Nombre o correo" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gold px-4 py-2.5 text-sm font-semibold text-ink hover:bg-gold-dark">
                    <i class="fa-solid fa-magnifying-glass mr-2"></i>
                    Filtrar
                </button>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gold/20 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Fecha</th>
                            <th class="px-5 py-3 font-semibold">Usuario</th>
                            <th class="px-5 py-3 font-semibold">Acción</th>
                            <th class="px-5 py-3 font-semibold">Modelo</th>
                            <th class="px-5 py-3 font-semibold">ID</th>
                            <th class="px-5 py-3 font-semibold">IP</th>
                            <th class="px-5 py-3 font-semibold">Cambios</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($registros as $registro)
                            <tr class="hover:bg-stone-50">
                                <td class="whitespace-nowrap px-5 py-3 text-stone-600">{{ $registro->created_at->format('d/m/Y H:i:s') }}</td>
                                <td class="px-5 py-3">
                                    <div class="font-medium text-ink">{{ $registro->user?->name ?? 'Sistema' }}</div>
                                    <div class="text-xs text-stone-500">{{ $registro->user?->email ?? 'N/A' }}</div>
                                </td>
                                <td class="px-5 py-3 font-medium text-ink">{{ $registro->action }}</td>
                                <td class="px-5 py-3 text-stone-600">{{ $registro->model_type ?? 'N/A' }}</td>
                                <td class="px-5 py-3 text-stone-600">{{ $registro->model_id ?? 'N/A' }}</td>
                                <td class="px-5 py-3 text-stone-600">{{ $registro->ip_address ?? 'N/A' }}</td>
                                <td class="px-5 py-3 text-stone-600">
                                    @if (! empty($registro->changes))
                                        <pre class="max-w-xs overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($registro->redactedChanges(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    @else
                                        <span class="text-stone-400">Sin cambios</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8">
                                    <x-admin.empty-state title="Sin auditoría" message="No hay registros del sistema en esta vista." icon="fa-shield-halved" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($registros->hasPages())
            <div class="rounded-2xl border border-gold/20 bg-white px-5 py-3 shadow-sm">
                {{ $registros->links() }}
            </div>
        @endif
    </div>
@endsection
