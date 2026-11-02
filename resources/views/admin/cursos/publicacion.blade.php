@extends('admin.layouts.app')
@use('App\Support\OldInput')
@section('title', 'Publicación del curso')
@section('breadcrumb', 'Cursos / Publicación')
@section('content')
<section class="mx-auto max-w-3xl space-y-6 admin-card sm:p-8">
    <div>
        <p class="admin-eyebrow">Publicación editorial</p>
        <h1 class="admin-page-title">Ficha pública del curso</h1>
        <p class="mt-2 text-sm leading-6 text-[#64748B]">Curso administrativo: {{ $curso->codigo }}. Escribe y revisa únicamente información autorizada para el público. No incluyas datos personales, matrículas, precios ni cupos. Los datos administrativos no se copian a esta ficha.</p>
    </div>
    <p class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-4 py-3 text-sm font-semibold text-[#172B3A]">{{ $publicacion?->publicada ? 'Ficha autorizada: solo será visible mientras el curso esté activo, no eliminado y dentro de su vigencia.' : 'Ficha sin publicación autorizada.' }}</p>
    @if ($curso->trashed() || $curso->estado !== 'activo')
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
        @foreach (['titulo' => ['Título público', 120, true], 'resumen' => ['Resumen público', 500, true], 'horario' => ['Horario oficial del curso (opcional)', 200, false], 'inscripcion' => ['Información pública de inscripción (opcional)', 400, false]] as $field => [$label, $max, $required])
            <div>
                <label for="{{ $field }}" class="admin-label">{{ $label }}</label>
                <textarea id="{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" rows="{{ $field === 'resumen' ? 4 : 2 }}" @required($required) class="admin-textarea">{{ OldInput::value($field, $publicacion?->$field ?? '') }}</textarea>
            </div>
        @endforeach
        <p class="text-sm text-[#64748B]">Deja horario e inscripción vacíos si no hay información oficial. La web y el asistente indicarán que no está publicada.</p>
        <div>
            <label for="modalidad" class="admin-label">Modalidad pública</label>
            <select id="modalidad" name="modalidad" required class="admin-select">
                <option value="">Selecciona una modalidad confirmada</option>
                @foreach (['presencial' => 'Presencial', 'semipresencial' => 'Semipresencial', 'virtual' => 'Virtual', 'hibrido' => 'Híbrido'] as $value => $label)
                    <option value="{{ $value }}" @selected(OldInput::value('modalidad', $publicacion?->modalidad ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-semibold text-[#172B3A]">Vigencia de la información (hora de Lima, días completos)</legend>
            @foreach (['vigente_desde' => 'Desde', 'vigente_hasta' => 'Hasta, inclusive'] as $field => $label)
                <div>
                    <label for="{{ $field }}" class="admin-label">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="date" required value="{{ OldInput::value($field, $publicacion?->$field?->format('Y-m-d') ?? '') }}" class="admin-input">
                </div>
            @endforeach
        </fieldset>
        <label class="flex min-h-11 items-start gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-3.5 text-sm text-[#172B3A]">
            <input type="checkbox" name="confirmacion_publica" value="1" required class="mt-0.5 h-5 w-5 shrink-0 rounded border-[#CBD5E1] text-[#176B9A] focus:ring-[#176B9A]/30">
            <span>Confirmo que revisé esta información y autorizo su publicación en la web y el chatbot durante la vigencia indicada.</span>
        </label>
        <button type="submit" @disabled($curso->trashed() || $curso->estado !== 'activo') class="admin-btn-primary">Publicar ficha revisada</button>
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
