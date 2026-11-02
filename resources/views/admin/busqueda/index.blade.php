@extends('admin.layouts.app')

@section('title', 'Buscar')
@section('breadcrumb', 'Buscar')

@section('content')
    <div class="space-y-6">
        <section>
            <p class="admin-eyebrow">Operación interna</p>
            <h1 class="admin-page-title">Búsqueda general</h1>
            <p class="admin-page-subtitle">Localiza rápidamente estudiantes, cursos, matrículas o usuarios del sistema.</p>
        </section>

        <section class="admin-card">
            <form method="GET" action="{{ route('admin.busqueda.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="q" class="admin-label">Buscar en el sistema</label>
                    <input id="q" name="q" type="search" value="{{ $busqueda }}" placeholder="Ingrese un nombre, DNI, curso o correo" class="admin-input" />
                </div>
                <button type="submit" class="admin-btn-primary">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    Buscar
                </button>
            </form>
        </section>

        @if ($busqueda === '')
            <div class="admin-card">
                <x-admin.empty-state title="Busca información" message="Escribe una palabra clave para localizar estudiantes, cursos, matrículas o usuarios." icon="fa-magnifying-glass" />
            </div>
        @else
            <section class="grid gap-5 lg:grid-cols-2">
                @foreach (['estudiantes' => 'Estudiantes', 'cursos' => 'Cursos', 'matriculas' => 'Matrículas', 'usuarios' => 'Usuarios'] as $key => $label)
                    <div class="admin-card">
                        <div class="mb-4 flex items-center justify-between border-b border-[#E2E8F0] pb-3">
                            <h2 class="text-base font-bold text-[#172B3A]">{{ $label }}</h2>
                            <span class="rounded-full border border-[#19A7A1]/30 bg-[#19A7A1]/10 px-2.5 py-0.5 text-xs font-semibold text-[#0F766E]">{{ $resultados[$key]->count() }}</span>
                        </div>

                        @if ($resultados[$key]->isEmpty())
                            <p class="text-sm text-[#64748B]">No se encontraron coincidencias.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($resultados[$key] as $item)
                                    <li class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2.5 text-sm font-medium text-[#172B3A]">
                                        @if ($key === 'estudiantes')
                                            {{ $item->nombres }} {{ $item->apellidos }} <span class="text-xs font-normal text-[#64748B]">· DNI: {{ $item->dni }}</span>
                                        @elseif ($key === 'cursos')
                                            {{ $item->nombre }} <span class="text-xs font-semibold text-[#176B9A]">· Código: {{ $item->codigo }}</span>
                                        @elseif ($key === 'matriculas')
                                            Matrícula #{{ $item->id }} <span class="text-xs font-normal text-[#64748B]">· {{ $item->estudiante?->nombres }} {{ $item->estudiante?->apellidos }}</span>
                                        @else
                                            {{ $item->name }} <span class="text-xs font-normal text-[#64748B]">· {{ $item->email }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
    </div>
@endsection
