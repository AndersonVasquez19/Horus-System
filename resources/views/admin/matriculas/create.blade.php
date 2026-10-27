@extends('admin.layouts.app')

@section('title', 'Nueva matrícula')
@section('breadcrumb', 'Matrículas / Crear')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <section>
            <a href="{{ route('admin.matriculas.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-stone-500 transition hover:text-ink">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver a matrículas
            </a>
            <h1 class="mt-3 text-2xl font-bold text-ink">Nueva matrícula</h1>
            <p class="mt-1 text-sm text-stone-600">Selecciona al estudiante y el curso para registrar la matrícula.</p>
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

            @if ($estudiantes->isEmpty() || $cursos->isEmpty())
                <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                    @if ($estudiantes->isEmpty())
                        No hay estudiantes activos disponibles. Registra o activa un estudiante antes de continuar.
                    @endif
                    @if ($cursos->isEmpty())
                        No hay cursos activos con cupos disponibles para esta matrícula.
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('admin.matriculas.store') }}" class="space-y-6">
                @csrf
                @include('admin.matriculas._form')
                <div class="flex flex-col-reverse gap-3 border-t border-stone-100 pt-5 sm:flex-row sm:justify-end">
                    <a href="{{ route('admin.matriculas.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gold px-5 py-2.5 text-sm font-semibold text-gold-dark transition hover:bg-gold/10">
                        Cancelar
                    </a>
                    <button type="submit" @disabled($estudiantes->isEmpty() || $cursos->isEmpty()) class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-gold-dark disabled:cursor-not-allowed disabled:opacity-50">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        Guardar matrícula
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
