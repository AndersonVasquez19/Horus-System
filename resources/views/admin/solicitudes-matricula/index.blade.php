@use('App\Models\SolicitudMatricula')
@use('App\Support\OldInput')
@extends('admin.layouts.app')
@section('title', 'Solicitudes de matrícula')
@section('breadcrumb', 'Solicitudes de matrícula')

@section('content')
<div class="space-y-6">
    <header class="admin-page-header">
        <div>
            <p class="admin-eyebrow">Gestión académica</p>
            <h1 class="admin-page-title">Solicitudes de matrícula</h1>
            <p class="admin-page-subtitle">Revisa los contactos recibidos desde la web. Atender una solicitud no registra una matrícula ni reserva cupos.</p>
        </div>
        <a href="{{ route('matricula-publica.create') }}" target="_blank" rel="noopener" class="admin-btn-outline">Ver formulario público</a>
    </header>
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <a href="{{ route('admin.solicitudes-matricula.index') }}" class="mt-2 inline-block font-semibold underline">Recargar listado</a>
        </div>
    @endif
    <section class="admin-card-flush">
        <form method="GET" action="{{ route('admin.solicitudes-matricula.index') }}" class="flex flex-wrap items-end gap-3 border-b border-[#E2E8F0] p-5">
            <div><label for="estado-solicitud" class="admin-label">Estado</label><select id="estado-solicitud" name="estado" class="admin-select"><option value="">Todos</option>@foreach (SolicitudMatricula::ESTADOS as $valor => $etiqueta)<option value="{{ $valor }}" @selected(($filtros['estado'] ?? '') === $valor)>{{ $etiqueta }}</option>@endforeach</select></div>
            <button type="submit" class="admin-btn-secondary">Filtrar</button>
            <a href="{{ route('admin.solicitudes-matricula.index') }}" class="admin-btn-outline">Limpiar</a>
        </form>
        <div class="admin-table-wrap" tabindex="0" role="region" aria-label="Solicitudes recibidas">
            <table class="admin-table">
                <thead><tr><th>Referencia / recepción</th><th>Curso</th><th>Estado</th><th>Acción</th></tr></thead>
                <tbody>
                    @forelse ($solicitudes as $solicitud)
                        <tr>
                            <td><span class="block max-w-xs break-all text-xs">{{ $solicitud->identificador }}</span><span class="text-xs">{{ $solicitud->created_at->timezone('America/Lima')->format('d/m/Y H:i') }}</span></td>
                            <td class="font-semibold">{{ $solicitud->curso_titulo }}<span class="block text-xs font-normal">{{ ucfirst($solicitud->modalidad) }}</span></td>
                            <td>{{ SolicitudMatricula::ESTADOS[$solicitud->estado] }}</td>
                            <td><button type="button" data-admin-modal-open="solicitud-{{ $solicitud->identificador }}" class="admin-btn-outline">Revisar solicitud</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-6 text-center">No hay solicitudes que coincidan con el filtro.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-[#E2E8F0] p-5">{{ $solicitudes->links() }}</div>
    </section>
    @foreach ($solicitudes as $solicitud)
        @php $esFormularioPrevio = old('_solicitud') === $solicitud->identificador; @endphp
        <x-admin.form-modal id="solicitud-{{ $solicitud->identificador }}" title="Revisar solicitud de matrícula" :auto-open="$esFormularioPrevio && $errors->any()">
            <div class="space-y-5">
                <div><h3 class="text-xl font-bold">{{ $solicitud->curso_titulo }}</h3><p class="admin-help">{{ ucfirst($solicitud->modalidad) }} · {{ SolicitudMatricula::ESTADOS[$solicitud->estado] }}</p></div>
                <dl class="grid gap-4 rounded-xl bg-[#F4F7FB] p-4 text-sm sm:grid-cols-2">
                    @foreach (['nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'email' => 'Correo electrónico', 'telefono' => 'Teléfono'] as $campo => $etiqueta)
                        <div class="min-w-0"><dt class="font-semibold">{{ $etiqueta }}</dt><dd class="mt-1 break-words">{{ $solicitud->$campo }}</dd></div>
                    @endforeach
                    <div class="sm:col-span-2"><dt class="font-semibold">Consulta adicional</dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $solicitud->mensaje ?: 'Sin consulta adicional.' }}</dd></div>
                </dl>
                <p class="admin-help">Consentimiento para atender la solicitud: {{ $solicitud->consentimiento_at->timezone('America/Lima')->format('d/m/Y H:i') }} (Lima).</p>
                @if ($solicitud->nota_revision)
                    <div class="rounded-xl border border-[#E2E8F0] p-4 text-sm"><p class="font-semibold">Última nota de revisión</p><p class="mt-2 whitespace-pre-wrap break-words">{{ $solicitud->nota_revision }}</p></div>
                @endif
                @if ($esFormularioPrevio && $errors->any())
                    <ul role="alert" class="admin-error list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                @endif
                @can('solicitudes-matricula.gestionar')
                    @if (in_array($solicitud->estado, ['pendiente', 'en_revision'], true))
                        <form action="{{ route('admin.solicitudes-matricula.update', $solicitud->identificador) }}" method="POST" class="space-y-5">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="_solicitud" value="{{ $solicitud->identificador }}">
                            <input type="hidden" name="revision" value="{{ $esFormularioPrevio ? OldInput::value('revision', $solicitud->revision) : $solicitud->revision }}">
                            <div><label for="revision-estado-{{ $solicitud->id }}" class="admin-label">Nuevo estado</label><select id="revision-estado-{{ $solicitud->id }}" name="estado" required class="admin-select"><option value="">Selecciona una acción</option>
                                @foreach (($solicitud->estado === 'pendiente' ? ['en_revision', 'rechazada'] : ['atendida', 'rechazada']) as $estado)
                                    <option value="{{ $estado }}" @selected($esFormularioPrevio && OldInput::value('estado') === $estado)>{{ SolicitudMatricula::ESTADOS[$estado] }}</option>
                                @endforeach
                            </select></div>
                            <div><label for="revision-nota-{{ $solicitud->id }}" class="admin-label">Nota interna de revisión</label><textarea id="revision-nota-{{ $solicitud->id }}" name="nota_revision" maxlength="1000" rows="3" class="admin-textarea">{{ $esFormularioPrevio ? OldInput::value('nota_revision') : '' }}</textarea><p class="admin-help">Obligatoria para rechazar una solicitud. Esta nota no se envía al visitante.</p></div>
                            <div class="flex flex-wrap justify-end gap-3 border-t border-[#E2E8F0] pt-4"><button type="button" data-admin-modal-close class="admin-btn-outline">Cancelar</button><button type="submit" class="admin-btn-primary">Guardar revisión</button></div>
                        </form>
                    @else
                        <p role="status" class="admin-help">La revisión está cerrada. El registro de matrículas se gestiona por separado.</p>
                    @endif
                @endcan
            </div>
        </x-admin.form-modal>
    @endforeach
</div>
@endsection
