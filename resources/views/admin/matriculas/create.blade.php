@extends('admin.layouts.app')

@section('title', 'Nueva matrícula')
@section('breadcrumb', 'Matrículas / Crear')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <section>
            <a href="{{ route('admin.matriculas.index') }}" class="admin-back-link">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver a matrículas
            </a>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-[#172B3A]">Nueva matrícula</h1>
            <p class="admin-page-subtitle">Selecciona al estudiante y el curso para registrar la matrícula.</p>
        </section>

        <section class="admin-card sm:p-8">
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
                <div class="flex flex-col-reverse gap-3 border-t border-[#E2E8F0] pt-5 sm:flex-row sm:justify-end">
                    <a href="{{ route('admin.matriculas.index') }}" class="admin-btn-outline">
                        Cancelar
                    </a>
                    <button type="submit" @disabled($estudiantes->isEmpty() || $cursos->isEmpty()) class="admin-btn-primary">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        Guardar matrícula
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
