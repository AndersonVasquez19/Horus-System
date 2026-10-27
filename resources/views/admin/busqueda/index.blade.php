@extends('admin.layouts.app')

@section('title', 'Buscar')
@section('breadcrumb', 'Buscar')

@section('content')
    <div class="space-y-6">
        <section class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.busqueda.index') }}" class="flex flex-col gap-3 md:flex-row md:items-center">
                <div class="flex-1">
                    <label for="q" class="mb-1.5 block text-sm font-medium text-stone-700">Buscar en el sistema</label>
                    <input id="q" name="q" type="search" value="{{ $busqueda }}" placeholder="Ingrese un nombre, DNI, curso o correo" class="w-full rounded-xl border border-stone-200 px-3 py-2.5 focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/20" />
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white hover:bg-ink/90">
                    <i class="fa-solid fa-magnifying-glass mr-2"></i>
                    Buscar
                </button>
            </form>
        </section>

        @if ($busqueda === '')
            <x-admin.empty-state title="Busca información" message="Escribe una palabra clave para localizar estudiantes, cursos, matrículas o usuarios." icon="fa-magnifying-glass" />
        @else
            <section class="space-y-5">
                @foreach (['estudiantes' => 'Estudiantes', 'cursos' => 'Cursos', 'matriculas' => 'Matrículas', 'usuarios' => 'Usuarios'] as $key => $label)
                    <div class="rounded-2xl border border-gold/20 bg-white p-5 shadow-sm">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-lg font-bold text-ink">{{ $label }}</h2>
                            <span class="rounded-full bg-stone-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-stone-600">{{ $resultados[$key]->count() }}</span>
                        </div>

                        @if ($resultados[$key]->isEmpty())
                            <p class="text-sm text-stone-500">No se encontraron coincidencias.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($resultados[$key] as $item)
                                    <li class="rounded-xl border border-stone-200 px-3 py-2 text-sm text-stone-700">
                                        @if ($key === 'estudiantes')
                                            {{ $item->nombres }} {{ $item->apellidos }} · DNI: {{ $item->dni }}
                                        @elseif ($key === 'cursos')
                                            {{ $item->nombre }} · Código: {{ $item->codigo }}
                                        @elseif ($key === 'matriculas')
                                            Matrícula #{{ $item->id }} · {{ $item->estudiante?->nombres }} {{ $item->estudiante?->apellidos }}
                                        @else
                                            {{ $item->name }} · {{ $item->email }}
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
