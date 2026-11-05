@extends('admin.layouts.app')

@section('title', 'Auditoría')
@section('breadcrumb', 'Auditoría')

@section('content')
    <div class="space-y-6">
        <section class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Seguridad</p>
                <h1 class="admin-page-title">Auditoría del sistema</h1>
                <p class="admin-page-subtitle">Registro trazable de acciones administrativas y cambios sobre entidades.</p>
            </div>

            @can('auditoria.exportar')
                <a href="{{ route('admin.auditoria.exportar') }}" class="admin-btn-primary">
                    <i class="fa-solid fa-file-export" aria-hidden="true"></i>
                    Exportar JSON
                </a>
            @endcan
        </section>

        <section class="admin-card">
            <form method="GET" action="{{ route('admin.auditoria.index') }}" class="grid gap-3 md:grid-cols-[1fr_1fr_1fr_auto] md:items-end">
                <div>
                    <label for="accion" class="admin-label">Acción</label>
                    <input id="accion" name="accion" type="text" value="{{ $accion ?? '' }}" placeholder="Ej. login" class="admin-input" />
                </div>
                <div>
                    <label for="modelo" class="admin-label">Modelo</label>
                    <input id="modelo" name="modelo" type="text" value="{{ $modelo ?? '' }}" placeholder="Ej. User" class="admin-input" />
                </div>
                <div>
                    <label for="usuario" class="admin-label">Usuario</label>
                    <input id="usuario" name="usuario" type="text" value="{{ $usuario ?? '' }}" placeholder="Nombre o correo" class="admin-input" />
                </div>
                <button type="submit" class="admin-btn-secondary">
                    <i class="fa-solid fa-magnifying-glass text-xs text-[#19A7A1]" aria-hidden="true"></i>
                    Filtrar
                </button>
            </form>
        </section>

        <section class="admin-card-flush">
            <div class="admin-table-wrap">
                <table class="admin-table min-w-[900px]">
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Usuario</th>
                            <th scope="col">Acción</th>
                            <th scope="col">Modelo</th>
                            <th scope="col">ID</th>
                            <th scope="col">IP</th>
                            <th scope="col">Cambios</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($registros as $registro)
                            <tr>
                                <td class="whitespace-nowrap">{{ $registro->created_at->format('d/m/Y H:i:s') }}</td>
                                <td>
                                    <div class="font-semibold text-[#172B3A]">{{ $registro->user?->name ?? 'Sistema' }}</div>
                                    <div class="text-xs text-[#64748B]">{{ $registro->user?->email ?? 'N/A' }}</div>
                                </td>
                                <td>
                                    <span class="inline-flex rounded-full border border-[#176B9A]/20 bg-[#176B9A]/10 px-2.5 py-0.5 text-xs font-semibold text-[#176B9A]">{{ $registro->action }}</span>
                                </td>
                                <td class="font-medium text-[#172B3A]">{{ $registro->model_type ?? 'N/A' }}</td>
                                <td>{{ $registro->model_id ?? 'N/A' }}</td>
                                <td class="whitespace-nowrap font-mono text-xs">{{ $registro->ip_address ?? 'N/A' }}</td>
                                <td>
                                    @if (! empty($registro->changes))
                                        <pre class="max-h-36 max-w-xs overflow-auto rounded-lg border border-[#E2E8F0] bg-[#F4F7FB] p-2.5 whitespace-pre-wrap text-xs text-[#172B3A]">{{ json_encode($registro->redactedChanges(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    @else
                                        <span class="text-xs text-[#64748B]">Sin cambios</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-6">
                                    <x-admin.empty-state title="Sin auditoría" message="No hay registros del sistema en esta vista." icon="fa-clipboard-list" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($registros->hasPages())
            <div class="admin-card px-5 py-4">
                {{ $registros->links() }}
            </div>
        @endif
    </div>
@endsection
