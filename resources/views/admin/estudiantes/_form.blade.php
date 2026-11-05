@use('App\Support\OldInput')
<div class="grid gap-5 md:grid-cols-2">
    <div>
        <label for="dni" class="admin-label">DNI <span class="text-red-600">*</span></label>
        <input
            id="dni"
            name="dni"
            type="text"
            inputmode="numeric"
            maxlength="8"
            value="{{ OldInput::value('dni', $estudiante->dni ?? '') }}"
            required
            class="admin-input"
            placeholder="8 dígitos"
        >
        @error('dni')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="estado" class="admin-label">Estado <span class="text-red-600">*</span></label>
        <select id="estado" name="estado" required class="admin-select">
            <option value="activo" @selected(old('estado', $estudiante->estado ?? 'activo') === 'activo')>Activo</option>
            <option value="inactivo" @selected(old('estado', $estudiante->estado ?? 'activo') === 'inactivo')>Inactivo</option>
        </select>
        @error('estado')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="nombres" class="admin-label">Nombres <span class="text-red-600">*</span></label>
        <input
            id="nombres"
            name="nombres"
            type="text"
            maxlength="100"
            value="{{ OldInput::value('nombres', $estudiante->nombres ?? '') }}"
            required
            class="admin-input"
            placeholder="Nombres del estudiante"
        >
        @error('nombres')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="apellidos" class="admin-label">Apellidos <span class="text-red-600">*</span></label>
        <input
            id="apellidos"
            name="apellidos"
            type="text"
            maxlength="100"
            value="{{ OldInput::value('apellidos', $estudiante->apellidos ?? '') }}"
            required
            class="admin-input"
            placeholder="Apellidos del estudiante"
        >
        @error('apellidos')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="email" class="admin-label">Correo electrónico</label>
        <input
            id="email"
            name="email"
            type="email"
            maxlength="150"
            value="{{ OldInput::value('email', $estudiante->email ?? '') }}"
            class="admin-input"
            placeholder="estudiante@correo.com"
        >
        @error('email')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="telefono" class="admin-label">Teléfono</label>
        <input
            id="telefono"
            name="telefono"
            type="tel"
            maxlength="20"
            value="{{ OldInput::value('telefono', $estudiante->telefono ?? '') }}"
            class="admin-input"
            placeholder="Número de contacto"
        >
        @error('telefono')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="fecha_nacimiento" class="admin-label">Fecha de nacimiento</label>
        <input
            id="fecha_nacimiento"
            name="fecha_nacimiento"
            type="date"
            value="{{ OldInput::value('fecha_nacimiento', isset($estudiante) && $estudiante->fecha_nacimiento ? $estudiante->fecha_nacimiento->format('Y-m-d') : '') }}"
            class="admin-input"
        >
        @error('fecha_nacimiento')<p class="admin-error">{{ $message }}</p>@enderror
    </div>

    <div class="md:col-span-2">
        <label for="direccion" class="admin-label">Dirección</label>
        <input
            id="direccion"
            name="direccion"
            type="text"
            maxlength="255"
            value="{{ OldInput::value('direccion', $estudiante->direccion ?? '') }}"
            class="admin-input"
            placeholder="Dirección del estudiante"
        >
        @error('direccion')<p class="admin-error">{{ $message }}</p>@enderror
    </div>
</div>
