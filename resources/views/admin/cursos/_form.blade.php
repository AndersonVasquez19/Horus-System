@use('App\Support\OldInput')
<div class="grid gap-5 md:grid-cols-2">
    <div>
        <label for="codigo" class="mb-2 block text-sm font-semibold text-ink">Código <span class="text-red-600">*</span></label>
        <input id="codigo" name="codigo" type="text" maxlength="20" value="{{ OldInput::value('codigo', $curso->codigo ?? '') }}" required class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="CUR-009">
        @error('codigo')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="nombre" class="mb-2 block text-sm font-semibold text-ink">Nombre del curso <span class="text-red-600">*</span></label>
        <input id="nombre" name="nombre" type="text" maxlength="150" value="{{ OldInput::value('nombre', $curso->nombre ?? '') }}" required class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="Nombre del curso">
        @error('nombre')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="categoria" class="mb-2 block text-sm font-semibold text-ink">Categoría <span class="text-red-600">*</span></label>
        <select id="categoria" name="categoria" required class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
            <option value="">Selecciona una categoría</option>
            <option value="tecnologia" @selected(old('categoria', $curso->categoria ?? '') === 'tecnologia')>Tecnología</option>
            <option value="educacion" @selected(old('categoria', $curso->categoria ?? '') === 'educacion')>Educación</option>
            <option value="salud" @selected(old('categoria', $curso->categoria ?? '') === 'salud')>Salud</option>
            <option value="gestion" @selected(old('categoria', $curso->categoria ?? '') === 'gestion')>Gestión</option>
            <option value="otros" @selected(old('categoria', $curso->categoria ?? '') === 'otros')>Otros</option>
        </select>
        @error('categoria')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="modalidad" class="mb-2 block text-sm font-semibold text-ink">Modalidad <span class="text-red-600">*</span></label>
        <select id="modalidad" name="modalidad" required class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
            <option value="">Selecciona una modalidad</option>
            <option value="presencial" @selected(old('modalidad', $curso->modalidad ?? '') === 'presencial')>Presencial</option>
            <option value="virtual" @selected(old('modalidad', $curso->modalidad ?? '') === 'virtual')>Virtual</option>
            <option value="hibrido" @selected(old('modalidad', $curso->modalidad ?? '') === 'hibrido')>Híbrido</option>
        </select>
        @error('modalidad')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="md:col-span-2">
        <label for="descripcion" class="mb-2 block text-sm font-semibold text-ink">Descripción <span class="text-red-600">*</span></label>
        <textarea id="descripcion" name="descripcion" rows="4" required class="w-full rounded-xl border border-stone-200 px-4 py-3 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="Describe el contenido y objetivos del curso">{{ OldInput::value('descripcion', $curso->descripcion ?? '') }}</textarea>
        @error('descripcion')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="duracion_horas" class="mb-2 block text-sm font-semibold text-ink">Duración (horas) <span class="text-red-600">*</span></label>
        <input id="duracion_horas" name="duracion_horas" type="number" min="1" step="1" value="{{ OldInput::value('duracion_horas', $curso->duracion_horas ?? '') }}" required class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="40">
        @error('duracion_horas')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="precio" class="mb-2 block text-sm font-semibold text-ink">Precio (S/) <span class="text-red-600">*</span></label>
        <input id="precio" name="precio" type="number" min="0" step="0.01" value="{{ OldInput::value('precio', $curso->precio ?? '') }}" required class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="350.00">
        @error('precio')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="horario" class="mb-2 block text-sm font-semibold text-ink">Horario <span class="text-red-600">*</span></label>
        <input id="horario" name="horario" type="text" maxlength="100" value="{{ OldInput::value('horario', $curso->horario ?? '') }}" required class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="Lun-Mié, 18:00-20:00">
        @error('horario')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="cupos_maximos" class="mb-2 block text-sm font-semibold text-ink">Cupos máximos <span class="text-red-600">*</span></label>
        <input id="cupos_maximos" name="cupos_maximos" type="number" min="1" step="1" value="{{ OldInput::value('cupos_maximos', $curso->cupos_maximos ?? 30) }}" required class="w-full rounded-xl border border-stone-200 px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold" placeholder="30">
        @error('cupos_maximos')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        @isset($matriculasActivas)
            <p class="mt-1.5 text-xs text-stone-500">Matrículas activas actuales: {{ $matriculasActivas }}.</p>
        @endisset
    </div>

    <div>
        <label for="estado" class="mb-2 block text-sm font-semibold text-ink">Estado <span class="text-red-600">*</span></label>
        <select id="estado" name="estado" required class="w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold">
            <option value="activo" @selected(old('estado', $curso->estado ?? 'activo') === 'activo')>Activo</option>
            <option value="inactivo" @selected(old('estado', $curso->estado ?? 'activo') === 'inactivo')>Inactivo</option>
            <option value="archivado" @selected(old('estado', $curso->estado ?? 'activo') === 'archivado')>Archivado</option>
        </select>
        @error('estado')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
