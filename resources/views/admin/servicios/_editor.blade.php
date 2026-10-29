<section data-editor hidden aria-labelledby="servicio-titulo" class="space-y-6 rounded-2xl border border-gold/20 bg-white p-5 sm:p-8">
    <h2 id="servicio-titulo" tabindex="-1" class="text-2xl font-bold text-ink">Revisar servicio</h2>
    <dl data-detalle class="grid gap-3 break-words text-sm sm:grid-cols-2">
        @foreach (['codigo' => 'Código', 'nombre' => 'Nombre administrativo', 'estado' => 'Estado administrativo', 'estado_editorial' => 'Estado editorial', 'identificador_publico' => 'Identificador estable'] as $campo => $label)
            <div class="min-w-0"><dt class="font-semibold">{{ $label }}</dt><dd data-dato="{{ $campo }}"></dd></div>
        @endforeach
    </dl>
    <section data-conflicto hidden aria-labelledby="comparacion-titulo" class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
        <h3 id="comparacion-titulo" class="font-bold">Revisión pendiente</h3>
        <p>Tu borrador se conserva. Carga la versión actual y compara los cambios antes de decidir volver a guardar.</p>
        <button type="button" data-accion="comparar" class="min-h-11 rounded-xl border border-stone-400 px-4 py-2 font-semibold">Cargar versión actual para comparar</button>
        <pre data-comparacion hidden tabindex="0" aria-label="Versión actual del servicio para comparar" class="max-h-80 overflow-auto whitespace-pre-wrap break-words text-sm"></pre>
        <button type="button" data-accion="aceptar-version" hidden class="min-h-11 rounded-xl bg-ink px-4 py-2 font-semibold text-white">Revisé la versión actual; conservar mi borrador</button>
    </section>
    @can('servicios.crear')
        <form data-form="crear" method="POST" action="{{ route('admin.servicios.store') }}" hidden>
            @csrf
            <fieldset class="space-y-4"><legend class="mb-3 font-bold">Registrar servicio</legend>
                <div><label for="crear-codigo" class="mb-2 block font-semibold">Código único</label><input id="crear-codigo" name="codigo" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" class="min-h-11 w-full rounded-xl border border-stone-300 p-3" aria-describedby="codigo-ayuda"><p id="codigo-ayuda" class="mt-1 text-sm text-stone-600">Letras, números, guion o guion bajo. El código no podrá cambiarse.</p></div>
                <div><label for="crear-nombre" class="mb-2 block font-semibold">Nombre administrativo</label><input id="crear-nombre" name="nombre" required maxlength="120" class="min-h-11 w-full rounded-xl border border-stone-300 p-3"></div>
                <div><label for="crear-categoria" class="mb-2 block font-semibold">Categoría administrativa (opcional)</label><input id="crear-categoria" name="categoria" maxlength="80" class="min-h-11 w-full rounded-xl border border-stone-300 p-3"></div>
                <p class="text-sm text-stone-600">Se registrará inactivo y sin ficha pública. Guardar no publica información.</p>
                <button type="submit" class="min-h-11 rounded-xl bg-gold px-5 py-3 font-semibold text-ink">Registrar servicio</button>
            </fieldset>
        </form>
    @endcan
    @can('servicios.editar')
        <form data-form="administrativo" method="POST" hidden>
            @csrf
            <fieldset class="space-y-4"><legend class="mb-3 font-bold">Información administrativa</legend>
                <div><label for="editar-nombre" class="mb-2 block font-semibold">Nombre administrativo</label><input id="editar-nombre" name="nombre" required maxlength="120" class="min-h-11 w-full rounded-xl border border-stone-300 p-3"></div>
                <div><label for="editar-categoria" class="mb-2 block font-semibold">Categoría administrativa (opcional)</label><input id="editar-categoria" name="categoria" maxlength="80" class="min-h-11 w-full rounded-xl border border-stone-300 p-3"></div>
                <button type="submit" class="min-h-11 rounded-xl bg-gold px-5 py-3 font-semibold text-ink">Guardar información administrativa</button>
            </fieldset>
        </form>
        <form data-form="estado" method="POST" hidden>
            @csrf
            <fieldset class="space-y-3"><legend class="mb-3 font-bold">Cambiar estado administrativo</legend>
                <label for="editar-estado" class="block font-semibold">Nuevo estado</label>
                <select id="editar-estado" name="estado" class="min-h-11 w-full rounded-xl border border-stone-300 p-3">
                    <option value="activo">Activo</option><option value="inactivo">Inactivo</option><option value="archivado">Archivado</option>
                </select>
                <p class="text-sm text-stone-600">Inactivar o archivar retira la ficha publicada. Reactivar no la publica de nuevo.</p>
                <button type="submit" class="min-h-11 rounded-xl border border-stone-400 px-5 py-3 font-semibold">Cambiar estado</button>
            </fieldset>
        </form>
    @endcan
    <section data-ficha hidden class="space-y-3 border-t border-stone-200 pt-5">
        <h3 class="font-bold">Ficha editorial guardada</h3>
        <p data-sin-ficha>No hay ficha editorial. Su preparación requiere permiso de edición.</p>
        <dl class="space-y-3 break-words">
            @foreach (['titulo' => 'Título público', 'resumen' => 'Resumen', 'descripcion' => 'Descripción editorial', 'categoria' => 'Categoría pública', 'vigente_desde' => 'Desde (Lima)', 'vigente_hasta' => 'Hasta, inclusive (Lima)'] as $campo => $label)
                <div><dt class="font-semibold">{{ $label }}</dt><dd data-publicacion="{{ $campo }}" class="whitespace-pre-wrap"></dd></div>
            @endforeach
        </dl>
        <p class="text-sm text-stone-600">La autorización editorial no activa el catálogo ni conecta esta ficha a la web pública.</p>
    </section>
    @can('servicios.editar')
        <p data-aviso-publicada hidden class="rounded-xl bg-stone-100 p-4 text-sm">La ficha está autorizada. Debe retirarse con permiso de publicación antes de editar su contenido.</p>
        <form data-form="editorial" method="POST" hidden>
            @csrf
            <fieldset class="space-y-4"><legend class="mb-3 font-bold">Preparar borrador editorial</legend>
                <p class="text-sm text-stone-600">Redacta únicamente contenido público autorizado. No incluyas datos personales, comerciales privados ni credenciales.</p>
                @foreach (['titulo' => ['Título público', 120, 2], 'resumen' => ['Resumen público', 500, 3], 'descripcion' => ['Descripción editorial', 5000, 6]] as $campo => [$label, $maximo, $filas])
                    <div><label for="ficha-{{ $campo }}" class="mb-2 block font-semibold">{{ $label }}</label><textarea id="ficha-{{ $campo }}" name="{{ $campo }}" required maxlength="{{ $maximo }}" rows="{{ $filas }}" class="w-full rounded-xl border border-stone-300 p-3"></textarea></div>
                @endforeach
                <div><label for="ficha-categoria" class="mb-2 block font-semibold">Categoría pública (opcional)</label><input id="ficha-categoria" name="categoria" maxlength="80" class="min-h-11 w-full rounded-xl border border-stone-300 p-3"></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['vigente_desde' => 'Vigente desde (Lima)', 'vigente_hasta' => 'Vigente hasta, inclusive (Lima)'] as $campo => $label)
                        <div class="min-w-0"><label for="ficha-{{ $campo }}" class="mb-2 block font-semibold">{{ $label }}</label><input id="ficha-{{ $campo }}" name="{{ $campo }}" type="date" required class="min-h-11 w-full min-w-0 rounded-xl border border-stone-300 p-3"></div>
                    @endforeach
                </div>
                <button type="submit" class="min-h-11 rounded-xl bg-gold px-5 py-3 font-semibold text-ink">Guardar borrador editorial</button>
            </fieldset>
        </form>
    @endcan
    @can('servicios.publicar')
        <form data-form="autorizar" method="POST" hidden>
            @csrf
            <fieldset class="space-y-4"><legend class="mb-3 font-bold">Autorizar ficha revisada</legend>
                <p class="text-sm text-stone-600">Revisa la ficha guardada y su vigencia. Guarda antes los cambios pendientes. Una fecha inicial futura programa la autorización.</p>
                <label class="flex min-h-11 items-start gap-3"><input type="checkbox" name="confirmacion_publica" required class="mt-1 h-5 w-5"><span>Revisé la ficha guardada y autorizo su publicación durante la vigencia indicada.</span></label>
                <button type="submit" class="min-h-11 rounded-xl bg-gold px-5 py-3 font-semibold text-ink">Autorizar publicación</button>
            </fieldset>
        </form>
        <button type="button" data-accion="retirar" hidden class="min-h-11 rounded-xl border border-red-600 px-5 py-3 font-semibold text-red-700">Retirar publicación</button>
    @endcan
</section>
