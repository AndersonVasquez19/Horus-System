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
        <label for="estudiante_id" class="admin-label">Estudiante <span class="text-red-600">*</span></label>
        <select id="estudiante_id" name="estudiante_id" required class="admin-select">
            <option value="">Selecciona un estudiante</option>
            @foreach ($estudiantes as $estudiante)
                <option value="{{ $estudiante->id }}" @selected($valorPrevio('estudiante_id', $matricula->estudiante_id ?? $estudianteSeleccionado ?? '') === (string) $estudiante->id)>
                    {{ $estudiante->dni }} · {{ $estudiante->nombres }} {{ $estudiante->apellidos }}{{ $estudiante->trashed() ? ' (eliminado)' : '' }}
                </option>
            @endforeach
        </select>
        @error('estudiante_id')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="curso_id" class="admin-label">Curso <span class="text-red-600">*</span></label>
        <select id="curso_id" name="curso_id" required class="admin-select">
            <option value="">Selecciona un curso</option>
            @foreach ($cursos as $curso)
                <option value="{{ $curso->id }}" @selected($valorPrevio('curso_id', $matricula->curso_id ?? $cursoSeleccionado ?? '') === (string) $curso->id)>
                    {{ $curso->codigo }} · {{ $curso->nombre }} ({{ $curso->cupos_disponibles }} cupos){{ $curso->trashed() ? ' (eliminado)' : '' }}
                </option>
            @endforeach
        </select>
        @error('curso_id')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="fecha_matricula" class="admin-label">Fecha de matrícula <span class="text-red-600">*</span></label>
        <input id="fecha_matricula" name="fecha_matricula" type="date" value="{{ $valorPrevio('fecha_matricula', isset($matricula) ? $matricula->fecha_matricula->format('Y-m-d') : today()->format('Y-m-d')) }}" required class="admin-input">
        @error('fecha_matricula')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="estado" class="admin-label">Estado <span class="text-red-600">*</span></label>
        <select id="estado" name="estado" required class="admin-select">
            <option value="pendiente" @selected(old('estado', $matricula->estado ?? 'pendiente') === 'pendiente')>Pendiente</option>
            <option value="pagada" @selected(old('estado', $matricula->estado ?? 'pendiente') === 'pagada')>Pagada</option>
            <option value="anulada" @selected(old('estado', $matricula->estado ?? 'pendiente') === 'anulada')>Anulada</option>
        </select>
        @error('estado')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="monto_pagado" class="admin-label">Monto pagado (S/)</label>
        <input id="monto_pagado" name="monto_pagado" type="number" min="0" step="0.01" value="{{ $valorPrevio('monto_pagado', $matricula->monto_pagado ?? '') }}" class="admin-input" placeholder="0.00">
        @error('monto_pagado')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div class="md:col-span-2">
        <label for="observaciones" class="admin-label">Observaciones</label>
        <textarea id="observaciones" name="observaciones" rows="4" class="admin-textarea" placeholder="Notas adicionales sobre la matrícula">{{ $valorPrevio('observaciones', $matricula->observaciones ?? '') }}</textarea>
        @error('observaciones')<p class="admin-error">{{ $message }}</p>@enderror
    </div>
</div>
