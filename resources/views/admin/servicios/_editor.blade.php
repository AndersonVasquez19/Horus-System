<section data-editor hidden aria-labelledby="servicio-titulo" class="space-y-6 admin-card sm:p-8">
    <h2 id="servicio-titulo" tabindex="-1" class="text-2xl font-bold tracking-tight text-[#172B3A]">Revisar servicio</h2>
    <dl data-detalle class="grid gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-4 break-words text-sm sm:grid-cols-2">
        @foreach (['codigo' => 'Código', 'nombre' => 'Nombre administrativo', 'estado' => 'Estado administrativo', 'estado_editorial' => 'Estado editorial', 'identificador_publico' => 'Identificador estable'] as $campo => $label)
            <div class="min-w-0"><dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">{{ $label }}</dt><dd data-dato="{{ $campo }}" class="mt-0.5 font-medium text-[#172B3A]"></dd></div>
        @endforeach
    </dl>
    <section data-conflicto hidden aria-labelledby="comparacion-titulo" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <h3 id="comparacion-titulo" class="font-bold">Revisión pendiente</h3>
        <p>Tu borrador se conserva. Carga la versión actual y compara los cambios antes de decidir volver a guardar.</p>
        <button type="button" data-accion="comparar" class="admin-btn-outline">Cargar versión actual para comparar</button>
        <pre data-comparacion hidden tabindex="0" aria-label="Versión actual del servicio para comparar" class="max-h-80 overflow-auto rounded-lg border border-amber-200 bg-white p-3 whitespace-pre-wrap break-words text-xs text-[#172B3A]"></pre>
        <button type="button" data-accion="aceptar-version" hidden class="admin-btn-secondary">Revisé la versión actual; conservar mi borrador</button>
    </section>
    @can('servicios.crear')
        <form data-form="crear" method="POST" action="{{ route('admin.servicios.store') }}" hidden>
            @csrf
            <fieldset class="space-y-4"><legend class="mb-3 text-base font-bold text-[#172B3A]">Registrar servicio</legend>
                <div><label for="crear-codigo" class="admin-label">Código único</label><input id="crear-codigo" name="codigo" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" class="admin-input" aria-describedby="codigo-ayuda"><p id="codigo-ayuda" class="admin-help">Letras, números, guion o guion bajo. El código no podrá cambiarse.</p></div>
                <div><label for="crear-nombre" class="admin-label">Nombre administrativo</label><input id="crear-nombre" name="nombre" required maxlength="120" class="admin-input"></div>
                <div><label for="crear-categoria" class="admin-label">Categoría administrativa (opcional)</label><input id="crear-categoria" name="categoria" maxlength="80" class="admin-input"></div>
                <p class="text-sm text-[#64748B]">Se registrará inactivo y sin ficha pública. Guardar no publica información.</p>
                <button type="submit" class="admin-btn-primary">Registrar servicio</button>
            </fieldset>
        </form>
    @endcan
    @can('servicios.editar')
        <form data-form="administrativo" method="POST" hidden>
            @csrf
            <fieldset class="space-y-4"><legend class="mb-3 text-base font-bold text-[#172B3A]">Información administrativa</legend>
                <div><label for="editar-nombre" class="admin-label">Nombre administrativo</label><input id="editar-nombre" name="nombre" required maxlength="120" class="admin-input"></div>
                <div><label for="editar-categoria" class="admin-label">Categoría administrativa (opcional)</label><input id="editar-categoria" name="categoria" maxlength="80" class="admin-input"></div>
                <button type="submit" class="admin-btn-primary">Guardar información administrativa</button>
            </fieldset>
        </form>
        <form data-form="estado" method="POST" hidden>
            @csrf
            <fieldset class="space-y-3 border-t border-[#E2E8F0] pt-5"><legend class="mb-3 text-base font-bold text-[#172B3A]">Cambiar estado administrativo</legend>
                <label for="editar-estado" class="admin-label">Nuevo estado</label>
                <select id="editar-estado" name="estado" class="admin-select">
                    <option value="activo">Activo</option><option value="inactivo">Inactivo</option><option value="archivado">Archivado</option>
                </select>
                <p class="text-sm text-[#64748B]">Inactivar o archivar retira la ficha publicada. Reactivar no la publica de nuevo.</p>
                <button type="submit" class="admin-btn-outline">Cambiar estado</button>
            </fieldset>
        </form>
    @endcan
    <section data-ficha hidden class="space-y-3 border-t border-[#E2E8F0] pt-5">
        <h3 class="text-base font-bold text-[#172B3A]">Ficha editorial guardada</h3>
        <p data-sin-ficha class="text-sm text-[#64748B]">No hay ficha editorial. Su preparación requiere permiso de edición.</p>
        <dl class="space-y-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-4 break-words text-sm">
            @foreach (['titulo' => 'Título público', 'resumen' => 'Resumen', 'descripcion' => 'Descripción editorial', 'categoria' => 'Categoría pública', 'vigente_desde' => 'Desde (Lima)', 'vigente_hasta' => 'Hasta, inclusive (Lima)'] as $campo => $label)
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">{{ $label }}</dt><dd data-publicacion="{{ $campo }}" class="mt-0.5 whitespace-pre-wrap font-medium text-[#172B3A]"></dd></div>
            @endforeach
        </dl>
        <p class="text-sm text-[#64748B]">La autorización editorial no activa el catálogo ni conecta esta ficha a la web pública.</p>
    </section>
    @can('servicios.editar')
        <p data-aviso-publicada hidden class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-4 text-sm text-[#172B3A]">La ficha está autorizada. Debe retirarse con permiso de publicación antes de editar su contenido.</p>
        <form data-form="editorial" method="POST" hidden>
            @csrf
            <fieldset class="space-y-4 border-t border-[#E2E8F0] pt-5"><legend class="mb-3 text-base font-bold text-[#172B3A]">Preparar borrador editorial</legend>
                <p class="text-sm text-[#64748B]">Redacta únicamente contenido público autorizado. No incluyas datos personales, comerciales privados ni credenciales.</p>
                @foreach (['titulo' => ['Título público', 120, 2], 'resumen' => ['Resumen público', 500, 3], 'descripcion' => ['Descripción editorial', 5000, 6]] as $campo => [$label, $maximo, $filas])
                    <div><label for="ficha-{{ $campo }}" class="admin-label">{{ $label }}</label><textarea id="ficha-{{ $campo }}" name="{{ $campo }}" required maxlength="{{ $maximo }}" rows="{{ $filas }}" class="admin-textarea"></textarea></div>
                @endforeach
                <div><label for="ficha-categoria" class="admin-label">Categoría pública (opcional)</label><input id="ficha-categoria" name="categoria" maxlength="80" class="admin-input"></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['vigente_desde' => 'Vigente desde (Lima)', 'vigente_hasta' => 'Vigente hasta, inclusive (Lima)'] as $campo => $label)
                        <div class="min-w-0"><label for="ficha-{{ $campo }}" class="admin-label">{{ $label }}</label><input id="ficha-{{ $campo }}" name="{{ $campo }}" type="date" required class="admin-input min-w-0"></div>
                    @endforeach
                </div>
                <button type="submit" class="admin-btn-primary">Guardar borrador editorial</button>
            </fieldset>
        </form>
    @endcan
    @can('servicios.publicar')
        <form data-form="autorizar" method="POST" hidden>
            @csrf
            <fieldset class="space-y-4 border-t border-[#E2E8F0] pt-5"><legend class="mb-3 text-base font-bold text-[#172B3A]">Autorizar ficha revisada</legend>
                <p class="text-sm text-[#64748B]">Revisa la ficha guardada y su vigencia. Guarda antes los cambios pendientes. Una fecha inicial futura programa la autorización.</p>
                <label class="flex min-h-11 items-start gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-3.5 text-sm text-[#172B3A]"><input type="checkbox" name="confirmacion_publica" required class="mt-0.5 h-5 w-5 shrink-0 rounded border-[#CBD5E1] text-[#176B9A] focus:ring-[#176B9A]/30"><span>Revisé la ficha guardada y autorizo su publicación durante la vigencia indicada.</span></label>
                <button type="submit" class="admin-btn-primary">Autorizar publicación</button>
            </fieldset>
        </form>
        <button type="button" data-accion="retirar" hidden class="admin-btn-danger">Retirar publicación</button>
    @endcan
</section>
