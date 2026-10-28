@php
    // old() devuelve un array si la petición llegó manipulada (campo[]=x). La validación ya lo rechaza;
    // al volver a mostrar el formulario se trata como vacío: ni se convierte a texto (error 500) ni se
    // reinterpreta como un id válido.
    $valorPrevio = static function (string $campo, mixed $actual = ''): string {
        $valor = old($campo, $actual);

        return is_scalar($valor) ? (string) $valor : '';
    };
@endphp
<div class="grid gap-5 md:grid-cols-2">
    <div>
        <label for="estudiante_id" class="mb-2 block text-sm font-semibold text-ink">Estudiante <span class="text-red-600">*</span></label>
        <select id="estudiante_id" name="estudiante_id" required class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
            <option value="">Selecciona un estudiante</option>
            @foreach ($estudiantes as $estudiante)
                <option value="{{ $estudiante->id }}" @selected($valorPrevio('estudiante_id', $matricula->estudiante_id ?? $estudianteSeleccionado ?? '') === (string) $estudiante->id)>
                    {{ $estudiante->dni }} · {{ $estudiante->nombres }} {{ $estudiante->apellidos }}{{ $estudiante->trashed() ? ' (eliminado)' : '' }}
                </option>
            @endforeach
        </select>
        @error('estudiante_id')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="curso_id" class="mb-2 block text-sm font-semibold text-ink">Curso <span class="text-red-600">*</span></label>
        <select id="curso_id" name="curso_id" required class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
            <option value="">Selecciona un curso</option>
            @foreach ($cursos as $curso)
                <option value="{{ $curso->id }}" @selected($valorPrevio('curso_id', $matricula->curso_id ?? $cursoSeleccionado ?? '') === (string) $curso->id)>
                    {{ $curso->codigo }} · {{ $curso->nombre }} ({{ $curso->cupos_disponibles }} cupos){{ $curso->trashed() ? ' (eliminado)' : '' }}
                </option>
            @endforeach
        </select>
        @error('curso_id')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="fecha_matricula" class="mb-2 block text-sm font-semibold text-ink">Fecha de matrícula <span class="text-red-600">*</span></label>
        <input id="fecha_matricula" name="fecha_matricula" type="date" value="{{ $valorPrevio('fecha_matricula', isset($matricula) ? $matricula->fecha_matricula->format('Y-m-d') : today()->format('Y-m-d')) }}" required class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
        @error('fecha_matricula')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="estado" class="mb-2 block text-sm font-semibold text-ink">Estado <span class="text-red-600">*</span></label>
        <select id="estado" name="estado" required class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
            <option value="pendiente" @selected(old('estado', $matricula->estado ?? 'pendiente') === 'pendiente')>Pendiente</option>
            <option value="pagada" @selected(old('estado', $matricula->estado ?? 'pendiente') === 'pagada')>Pagada</option>
            <option value="anulada" @selected(old('estado', $matricula->estado ?? 'pendiente') === 'anulada')>Anulada</option>
        </select>
        @error('estado')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="monto_pagado" class="mb-2 block text-sm font-semibold text-ink">Monto pagado (S/)</label>
        <input id="monto_pagado" name="monto_pagado" type="number" min="0" step="0.01" value="{{ $valorPrevio('monto_pagado', $matricula->monto_pagado ?? '') }}" class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="0.00">
        @error('monto_pagado')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="md:col-span-2">
        <label for="observaciones" class="mb-2 block text-sm font-semibold text-ink">Observaciones</label>
        <textarea id="observaciones" name="observaciones" rows="4" class="w-full rounded-xl border border-stone-200 px-4 py-3 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="Notas adicionales sobre la matrícula">{{ $valorPrevio('observaciones', $matricula->observaciones ?? '') }}</textarea>
        @error('observaciones')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
