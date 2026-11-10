@extends('admin.layouts.app')
@use('App\Support\OldInput')
@section('title', 'Publicar curso en la web')
@section('breadcrumb', 'Cursos / Publicación')
@section('content')
<section class="mx-auto max-w-3xl space-y-6 admin-card sm:p-8">
    @php
        $hoy = now('America/Lima')->startOfDay();
        $desde = OldInput::value('vigente_desde', $publicacion?->vigente_desde?->format('Y-m-d') ?? $hoy->toDateString());
        $hasta = OldInput::value('vigente_hasta', $publicacion?->vigente_hasta?->format('Y-m-d') ?? $hoy->copy()->addDays(30)->toDateString());
        $noDisponible = $curso->trashed() || $curso->estado !== 'activo';
        $programada = $publicacion && $publicacion->vigente_desde->toDateString() > $hoy->toDateString();
        $vencida = $publicacion && $publicacion->vigente_hasta->toDateString() < $hoy->toDateString();
        $abrirFechas = $programada || $vencida || $errors->hasAny(['vigente_desde', 'vigente_hasta']);
        $abrirDetalles = $errors->hasAny(['horario', 'inscripcion']);
    @endphp
    <div>
        <p class="admin-eyebrow">Curso {{ $curso->codigo }}</p>
        <h2 id="admin-form-title" class="admin-page-title">Publicar curso en la web</h2>
        <p class="mt-2 text-sm leading-6 text-[#64748B]">Revisa el título, el resumen y la modalidad que verá el visitante. Escribe solo información pública, sin datos personales, precios ni cupos.</p>
    </div>
    <div role="status" class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-4 py-3 text-sm text-[#172B3A]">
        @if (! $publicacion?->publicada)
            <p class="font-semibold">Este curso aún no tiene una publicación autorizada.</p>
            @if ($programada || $vencida)
                <p class="mt-1">Las fechas guardadas están {{ $vencida ? 'vencidas' : 'programadas para el futuro' }}. Revísalas antes de publicar.</p>
            @endif
        @elseif ($noDisponible)
            <p class="font-semibold">La ficha está autorizada, pero el curso está inactivo o eliminado y no se muestra en la web.</p>
        @elseif ($vencida)
            <p class="font-semibold">Publicación vencida: dejó de mostrarse después del {{ $publicacion->vigente_hasta->format('d/m/Y') }}.</p>
            <p class="mt-1">Revisa las fechas antes de volver a publicar.</p>
        @elseif ($programada)
            <p class="font-semibold">Publicación programada: se mostrará desde el {{ $publicacion->vigente_desde->format('d/m/Y') }}.</p>
            <p class="mt-1">Para mostrarla hoy, cambia la fecha de inicio a {{ $hoy->format('d/m/Y') }} y vuelve a publicar.</p>
        @else
            <p class="font-semibold">Ficha autorizada y vigente hasta el {{ $publicacion->vigente_hasta->format('d/m/Y') }}.</p>
        @endif
        <p class="mt-1">Las fichas vigentes aparecen en <a href="{{ route('educacion.cursos') }}#catalogo-cursos" class="font-semibold underline">Educación → Cursos</a>, mientras el curso esté activo y su autorización siga siendo válida.</p>
    </div>
    @if ($noDisponible)
        <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">El curso no está disponible para publicación. Puedes retirar su ficha existente.</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Revisa la ficha antes de publicar:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            @if ($errors->has('revision'))
                <a href="{{ route('admin.cursos.publicacion.edit', $curso) }}" class="mt-2 inline-flex min-h-11 items-center font-semibold text-[#176B9A] underline">Cargar versión actual</a>
            @endif
        </div>
    @endif
    <form method="POST" action="{{ route('admin.cursos.publicacion.update', $curso) }}" class="space-y-5">
        @csrf
        @method('PUT')
        <input type="hidden" name="_revision" value="{{ OldInput::value('_revision', $revision) }}">
        <div>
            <label for="titulo" class="admin-label">Título público</label>
            <input id="titulo" name="titulo" type="text" maxlength="120" required value="{{ OldInput::value('titulo', $publicacion?->titulo ?? '') }}" class="admin-input">
        </div>
        <div>
            <label for="resumen" class="admin-label">Resumen público</label>
            <textarea id="resumen" name="resumen" maxlength="500" rows="3" required class="admin-textarea">{{ OldInput::value('resumen', $publicacion?->resumen ?? '') }}</textarea>
        </div>
        <div>
            <label for="modalidad" class="admin-label">Modalidad pública</label>
            <select id="modalidad" name="modalidad" required class="admin-select">
                <option value="">Selecciona una modalidad confirmada</option>
                @foreach (['presencial' => 'Presencial', 'semipresencial' => 'Semipresencial', 'virtual' => 'Virtual', 'hibrido' => 'Híbrido'] as $value => $label)
                    <option value="{{ $value }}" @selected(OldInput::value('modalidad', $publicacion?->modalidad ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <details id="detalles-publicacion" class="rounded-xl border border-[#E2E8F0] px-4 py-2" @if ($abrirDetalles) open @endif>
            <summary class="min-h-11 cursor-pointer content-center text-sm font-semibold text-[#172B3A]">Añadir horario o información de inscripción (opcional)</summary>
            <div class="space-y-4 pb-2 pt-3">
                <p class="text-sm text-[#64748B]">Completa estos campos solo si tienes información oficial. Puedes dejarlos vacíos.</p>
                @foreach (['horario' => ['Horario del curso', 200], 'inscripcion' => ['Información de inscripción', 400]] as $field => [$label, $max])
                <div>
                    <label for="{{ $field }}" class="admin-label">{{ $label }}</label>
                    <textarea id="{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" rows="2" class="admin-textarea">{{ OldInput::value($field, $publicacion?->$field ?? '') }}</textarea>
                </div>
                @endforeach
            </div>
        </details>
        @if (! $publicacion)
            <p class="text-sm text-[#64748B]">Para una publicación nueva se sugieren hoy y 30 días de vigencia. Puedes ajustar las fechas a continuación.</p>
        @endif
        <details id="fechas-publicacion" class="rounded-xl border border-[#E2E8F0] px-4 py-2" @if ($abrirFechas) open @endif>
            <summary class="min-h-11 cursor-pointer content-center text-sm font-semibold text-[#172B3A]">Revisar o cambiar fechas de publicación</summary>
            <div class="space-y-4 pb-2 pt-3">
                <p class="text-sm text-[#64748B]">Estas fechas controlan cuándo se muestra el curso en la web; no son las fechas de las clases. Se cuentan días completos, en hora de Lima.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['vigente_desde' => ['Mostrar desde', $desde], 'vigente_hasta' => ['Mostrar hasta, inclusive', $hasta]] as $field => [$label, $value])
                    <div>
                        <label for="{{ $field }}" class="admin-label">{{ $label }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="date" required value="{{ $value }}" class="admin-input">
                    </div>
                    @endforeach
                </div>
                <p class="text-sm text-[#64748B]">Si eliges un inicio futuro, el curso permanecerá oculto hasta ese día. Puedes despublicarlo antes del vencimiento.</p>
            </div>
        </details>
        <label class="flex min-h-11 items-start gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-3.5 text-sm text-[#172B3A]">
            <input type="checkbox" name="confirmacion_publica" value="1" required class="mt-0.5 h-5 w-5 shrink-0 rounded border-[#CBD5E1] text-[#176B9A] focus:ring-[#176B9A]/30">
            <span>Revisé esta información y autorizo su publicación durante las fechas indicadas.</span>
        </label>
        <button type="submit" @disabled($noDisponible) class="admin-btn-primary">{{ $publicacion?->publicada ? 'Guardar publicación' : 'Publicar curso' }}</button>
    </form>
    @if ($publicacion?->publicada)
        <form method="POST" action="{{ route('admin.cursos.publicacion.destroy', $curso) }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="_revision" value="{{ OldInput::value('_revision', $revision) }}">
            <button type="submit" class="admin-btn-danger">Despublicar ficha</button>
        </form>
    @endif
    <a href="{{ route('admin.cursos.index') }}" class="admin-back-link min-h-11 underline">Volver a cursos</a>
</section>
@endsection
