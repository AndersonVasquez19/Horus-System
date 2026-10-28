@extends('admin.layouts.app')
@use('App\Support\OldInput')
@section('title', 'Publicación del curso')
@section('breadcrumb', 'Cursos / Publicación')
@section('content')
<section class="mx-auto max-w-3xl space-y-6 rounded-2xl border border-gold/20 bg-white p-5 sm:p-8">
    <h1 class="text-2xl font-bold text-ink">Ficha pública del curso</h1>
    <p class="text-sm text-stone-600">Curso administrativo: {{ $curso->codigo }}. Escribe y revisa únicamente información autorizada para el público. No incluyas datos personales, matrículas, precios ni cupos. Los datos administrativos no se copian a esta ficha.</p>
    <p class="text-sm font-semibold">{{ $publicacion?->publicada ? 'Ficha autorizada: solo será visible mientras el curso esté activo, no eliminado y dentro de su vigencia.' : 'Ficha sin publicación autorizada.' }}</p>
    @if ($curso->trashed() || $curso->estado !== 'activo')
        <p class="text-sm text-red-700">El curso no está disponible para publicación. Puedes retirar su ficha existente.</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4">
            <p class="font-semibold">Revisa la ficha antes de publicar:</p>
            <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <form method="POST" action="{{ route('admin.cursos.publicacion.update', $curso) }}" class="space-y-5">
        @csrf
        @method('PUT')
        @foreach (['titulo' => ['Título público', 120, true], 'resumen' => ['Resumen público', 500, true], 'horario' => ['Horario oficial del curso (opcional)', 200, false], 'inscripcion' => ['Información pública de inscripción (opcional)', 400, false]] as $field => [$label, $max, $required])
            <div>
                <label for="{{ $field }}" class="mb-2 block font-semibold">{{ $label }}</label>
                <textarea id="{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" rows="{{ $field === 'resumen' ? 4 : 2 }}" @required($required) class="w-full rounded-xl border border-stone-300 p-3">{{ OldInput::value($field, $publicacion?->$field ?? '') }}</textarea>
            </div>
        @endforeach
        <p class="text-sm text-stone-600">Deja horario e inscripción vacíos si no hay información oficial. La web y el asistente indicarán que no está publicada.</p>
        <div>
            <label for="modalidad" class="mb-2 block font-semibold">Modalidad pública</label>
            <select id="modalidad" name="modalidad" required class="min-h-11 w-full rounded-xl border border-stone-300 p-3">
                <option value="">Selecciona una modalidad confirmada</option>
                @foreach (['presencial' => 'Presencial', 'semipresencial' => 'Semipresencial', 'virtual' => 'Virtual', 'hibrido' => 'Híbrido'] as $value => $label)
                    <option value="{{ $value }}" @selected(OldInput::value('modalidad', $publicacion?->modalidad ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 font-semibold">Vigencia de la información (hora de Lima, días completos)</legend>
            @foreach (['vigente_desde' => 'Desde', 'vigente_hasta' => 'Hasta, inclusive'] as $field => $label)
                <div>
                    <label for="{{ $field }}" class="mb-2 block">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="date" required value="{{ OldInput::value($field, $publicacion?->$field?->format('Y-m-d') ?? '') }}" class="min-h-11 w-full rounded-xl border border-stone-300 p-3">
                </div>
            @endforeach
        </fieldset>
        <label class="flex min-h-11 items-start gap-3">
            <input type="checkbox" name="confirmacion_publica" value="1" required class="mt-1 h-5 w-5">
            <span>Confirmo que revisé esta información y autorizo su publicación en la web y el chatbot durante la vigencia indicada.</span>
        </label>
        <button type="submit" @disabled($curso->trashed() || $curso->estado !== 'activo') class="min-h-11 rounded-xl bg-gold px-5 py-3 font-semibold text-ink disabled:opacity-50">Publicar ficha revisada</button>
    </form>
    @if ($publicacion?->publicada)
        <form method="POST" action="{{ route('admin.cursos.publicacion.destroy', $curso) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="min-h-11 rounded-xl border border-red-600 px-5 py-3 font-semibold text-red-700">Despublicar ficha</button>
        </form>
    @endif
    <a href="{{ route('admin.cursos.index') }}" class="inline-flex min-h-11 items-center underline">Volver a cursos</a>
</section>
@endsection
