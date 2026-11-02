@extends('admin.layouts.app')

@section('title', 'Editar matrícula')
@section('breadcrumb', 'Matrículas / Editar')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <section>
            <a href="{{ route('admin.matriculas.show', $matricula) }}" class="admin-back-link">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver al detalle
            </a>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-[#172B3A]">Editar matrícula</h1>
            <p class="admin-page-subtitle">Actualiza el estado, el curso o los datos de pago de la matrícula.</p>
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

            <form method="POST" action="{{ route('admin.matriculas.update', $matricula) }}" class="space-y-6">
                @csrf
                @method('PUT')
                @include('admin.matriculas._form')
                <div class="flex flex-col-reverse gap-3 border-t border-[#E2E8F0] pt-5 sm:flex-row sm:justify-end">
                    <a href="{{ route('admin.matriculas.show', $matricula) }}" class="admin-btn-outline">
                        Cancelar
                    </a>
                    <button type="submit" class="admin-btn-primary">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        Guardar cambios
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
