@extends('admin.layouts.app')

@section('title', 'Nuevo estudiante')
@section('breadcrumb', 'Estudiantes / Crear')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <section>
            <a href="{{ route('admin.estudiantes.index') }}" class="admin-back-link">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver a estudiantes
            </a>
            <h2 id="admin-form-title" class="mt-3 text-2xl font-bold tracking-tight text-[#172B3A]">Nuevo estudiante</h2>
            <p class="admin-page-subtitle">Completa los datos para registrar a un estudiante.</p>
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

            <form method="POST" action="{{ route('admin.estudiantes.store') }}" class="space-y-6">
                @csrf
                @include('admin.estudiantes._form')
                <div class="flex flex-col-reverse gap-3 border-t border-[#E2E8F0] pt-5 sm:flex-row sm:justify-end">
                    <a href="{{ route('admin.estudiantes.index') }}" class="admin-btn-outline">
                        Cancelar
                    </a>
                    <button type="submit" class="admin-btn-primary">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        Guardar estudiante
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
