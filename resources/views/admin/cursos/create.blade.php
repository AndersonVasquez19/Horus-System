@extends('admin.layouts.app')

@section('title', 'Nuevo curso')
@section('breadcrumb', 'Cursos / Crear')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <section>
            <a href="{{ route('admin.cursos.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-stone-500 transition hover:text-ink">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver a cursos
            </a>
            <h1 class="mt-3 text-2xl font-bold text-ink">Nuevo curso</h1>
            <p class="mt-1 text-sm text-stone-600">Completa la información para registrar un curso.</p>
        </section>

        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm sm:p-8">
            @if ($errors->any())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    <p class="font-semibold">Revisa los siguientes campos:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.cursos.store') }}" class="space-y-6">
                @csrf
                @include('admin.cursos._form')
                <div class="flex flex-col-reverse gap-3 border-t border-stone-100 pt-5 sm:flex-row sm:justify-end">
                    <a href="{{ route('admin.cursos.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gold px-5 py-2.5 text-sm font-semibold text-gold-dark transition hover:bg-gold/10">
                        Cancelar
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-gold-dark">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        Guardar curso
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
