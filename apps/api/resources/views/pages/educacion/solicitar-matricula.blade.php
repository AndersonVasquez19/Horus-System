@use('App\Support\OldInput')
@extends('layouts.app')
@section('title', 'Solicitar matrícula | Horus Group SRL')

@section('content')
<section class="bg-[#F4F7FB] px-4 pb-16 pt-32 text-[#172B3A] sm:px-6">
    <div class="mx-auto max-w-3xl space-y-6">
        <header>
            <a href="{{ route('educacion.cursos') }}#catalogo-cursos" class="admin-back-link"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a cursos</a>
            <p class="admin-eyebrow mt-6">Educación Horus</p>
            <h1 class="mt-2 text-3xl font-bold">Solicita tu matrícula</h1>
            <p class="mt-3 text-sm leading-6 text-[#64748B]">Cuéntanos qué curso te interesa. Nuestro equipo revisará tu solicitud y se pondrá en contacto contigo para orientarte.</p>
        </header>
        @if (session('solicitud_matricula_recibida'))
            <div role="status" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
                <p class="font-bold">Recibimos tu solicitud de matrícula.</p>
                <p class="mt-2 break-all">Referencia: {{ session('solicitud_matricula_recibida') }}</p>
                <p class="mt-2">Queda pendiente de revisión. El envío no confirma la matrícula ni reserva un cupo.</p>
            </div>
        @endif
        @if (! $habilitado || empty($cursos))
            <div role="status" class="admin-card">
                <p>La recepción de solicitudes no está disponible en este momento{{ $habilitado ? ': no hay cursos publicados vigentes' : '' }}.</p>
                <a href="{{ route('contactos') }}" class="admin-back-link mt-4 underline">Contactar con Horus</a>
            </div>
        @else
            <form action="{{ route('matricula-publica.store') }}" method="POST" class="admin-card space-y-6 sm:p-8">
                @csrf
                <input type="hidden" name="marca" value="{{ $marca }}">
                <div hidden aria-hidden="true"><label for="solicitud-sitio-web">Deja este campo vacío</label><input id="solicitud-sitio-web" name="sitio_web" tabindex="-1" autocomplete="off"></div>
                @if ($errors->any())
                    <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <p class="font-semibold">Revisa los siguientes datos:</p>
                        <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
                <div>
                    <label for="solicitud-curso" class="admin-label">Curso de interés <span class="text-red-600">*</span></label>
                    <select id="solicitud-curso" name="curso" required class="admin-select" aria-describedby="solicitud-cupo">
                        <option value="">Selecciona un curso publicado</option>
                        @foreach ($cursos as $curso)
                            <option value="{{ $curso['valor'] }}" @selected(OldInput::value('curso', request()->query('curso')) === $curso['valor'])>{{ $curso['titulo'] }} · {{ ucfirst($curso['modalidad']) }}</option>
                        @endforeach
                    </select>
                    <p id="solicitud-cupo" class="admin-help">La solicitud no reserva cupo, confirma fechas ni genera pagos. El equipo verificará los requisitos y la disponibilidad.</p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach (['nombres' => ['Nombres', 'text', 'given-name', 120], 'apellidos' => ['Apellidos', 'text', 'family-name', 120], 'email' => ['Correo electrónico', 'email', 'email', 254], 'telefono' => ['Teléfono de contacto', 'tel', 'tel', 30]] as $campo => [$etiqueta, $tipo, $autocompletar, $maximo])
                        <div>
                            <label for="solicitud-{{ $campo }}" class="admin-label">{{ $etiqueta }} <span class="text-red-600">*</span></label>
                            <input id="solicitud-{{ $campo }}" name="{{ $campo }}" type="{{ $tipo }}" autocomplete="{{ $autocompletar }}" required maxlength="{{ $maximo }}" value="{{ OldInput::value($campo) }}" class="admin-input" @if ($errors->has($campo)) aria-invalid="true" @endif>
                            @error($campo)<p class="admin-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                <div>
                    <label for="solicitud-mensaje" class="admin-label">Consulta adicional <span class="font-normal text-[#64748B]">(opcional)</span></label>
                    <textarea id="solicitud-mensaje" name="mensaje" rows="3" maxlength="1000" class="admin-textarea" aria-describedby="solicitud-datos">{{ OldInput::value('mensaje') }}</textarea>
                    <p id="solicitud-datos" class="admin-help">No incluyas documentos de identidad, datos bancarios ni información sensible.</p>
                </div>
                <label class="flex items-start gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-4 text-sm leading-6">
                    <input type="checkbox" name="consentimiento" value="1" required class="admin-checkbox mt-1 shrink-0">
                    <span>Autorizo a Horus a utilizar estos datos para atender esta solicitud y contactarme. He leído la <a href="{{ route('legales.privacidad') }}" target="_blank" rel="noopener" class="font-semibold underline">política de privacidad</a>.</span>
                </label>
                <div class="flex flex-col-reverse gap-3 border-t border-[#E2E8F0] pt-5 sm:flex-row sm:justify-end">
                    <a href="{{ route('educacion.cursos') }}#catalogo-cursos" class="admin-btn-outline">Cancelar</a>
                    <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar solicitud</button>
                </div>
            </form>
        @endif
    </div>
</section>
@endsection
