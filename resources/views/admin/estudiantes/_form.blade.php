<div class="grid gap-5 md:grid-cols-2">
    <div>
        <label for="dni" class="mb-2 block text-sm font-semibold text-ink">DNI <span class="text-red-600">*</span></label>
        <input
            id="dni"
            name="dni"
            type="text"
            inputmode="numeric"
            maxlength="8"
            value="{{ old('dni', $estudiante->dni ?? '') }}"
            required
            class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
            placeholder="8 dígitos"
        >
        @error('dni')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="estado" class="mb-2 block text-sm font-semibold text-ink">Estado <span class="text-red-600">*</span></label>
        <select id="estado" name="estado" required class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
            <option value="activo" @selected(old('estado', $estudiante->estado ?? 'activo') === 'activo')>Activo</option>
            <option value="inactivo" @selected(old('estado', $estudiante->estado ?? 'activo') === 'inactivo')>Inactivo</option>
        </select>
        @error('estado')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="nombres" class="mb-2 block text-sm font-semibold text-ink">Nombres <span class="text-red-600">*</span></label>
        <input
            id="nombres"
            name="nombres"
            type="text"
            maxlength="100"
            value="{{ old('nombres', $estudiante->nombres ?? '') }}"
            required
            class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
            placeholder="Nombres del estudiante"
        >
        @error('nombres')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="apellidos" class="mb-2 block text-sm font-semibold text-ink">Apellidos <span class="text-red-600">*</span></label>
        <input
            id="apellidos"
            name="apellidos"
            type="text"
            maxlength="100"
            value="{{ old('apellidos', $estudiante->apellidos ?? '') }}"
            required
            class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
            placeholder="Apellidos del estudiante"
        >
        @error('apellidos')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="email" class="mb-2 block text-sm font-semibold text-ink">Correo electrónico</label>
        <input
            id="email"
            name="email"
            type="email"
            maxlength="150"
            value="{{ old('email', $estudiante->email ?? '') }}"
            class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
            placeholder="estudiante@correo.com"
        >
        @error('email')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="telefono" class="mb-2 block text-sm font-semibold text-ink">Teléfono</label>
        <input
            id="telefono"
            name="telefono"
            type="tel"
            maxlength="20"
            value="{{ old('telefono', $estudiante->telefono ?? '') }}"
            class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
            placeholder="Número de contacto"
        >
        @error('telefono')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="fecha_nacimiento" class="mb-2 block text-sm font-semibold text-ink">Fecha de nacimiento</label>
        <input
            id="fecha_nacimiento"
            name="fecha_nacimiento"
            type="date"
            value="{{ old('fecha_nacimiento', isset($estudiante) && $estudiante->fecha_nacimiento ? $estudiante->fecha_nacimiento->format('Y-m-d') : '') }}"
            class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
        >
        @error('fecha_nacimiento')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="md:col-span-2">
        <label for="direccion" class="mb-2 block text-sm font-semibold text-ink">Dirección</label>
        <input
            id="direccion"
            name="direccion"
            type="text"
            maxlength="255"
            value="{{ old('direccion', $estudiante->direccion ?? '') }}"
            class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
            placeholder="Dirección del estudiante"
        >
        @error('direccion')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
