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
            @foreach (['titulo' => 'Título público', 'resumen' => 'Resumen', 'descripcion' => 'Descripción editorial', 'categoria' => 'Categoría pública', 'titulo_tarjetas' => 'Título de la sección de tarjetas', 'imagen_principal_alt' => 'Texto alternativo de la imagen principal', 'vigente_desde' => 'Desde (Lima)', 'vigente_hasta' => 'Hasta, inclusive (Lima)'] as $campo => $label)
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
                <div><label for="ficha-titulo_tarjetas" class="admin-label">Título de la sección de tarjetas (opcional)</label><input id="ficha-titulo_tarjetas" name="titulo_tarjetas" maxlength="120" class="admin-input"></div>
                <div><label for="ficha-imagen_principal_alt" class="admin-label">Texto alternativo de la imagen principal (opcional)</label><input id="ficha-imagen_principal_alt" name="imagen_principal_alt" maxlength="160" class="admin-input" aria-describedby="alt-ayuda"><p id="alt-ayuda" class="admin-help">Descríbela para lectores de pantalla. Déjalo vacío solo si la imagen es decorativa.</p></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['vigente_desde' => 'Vigente desde (Lima)', 'vigente_hasta' => 'Vigente hasta, inclusive (Lima)'] as $campo => $label)
                        <div class="min-w-0"><label for="ficha-{{ $campo }}" class="admin-label">{{ $label }}</label><input id="ficha-{{ $campo }}" name="{{ $campo }}" type="date" required class="admin-input min-w-0"></div>
                    @endforeach
                </div>
                <button type="submit" class="admin-btn-primary">Guardar borrador editorial</button>
            </fieldset>
        </form>
    @endcan
    @can('servicios.editar')
        <section data-contenido hidden aria-labelledby="contenido-titulo" class="space-y-5 border-t border-[#E2E8F0] pt-5">
            <h3 id="contenido-titulo" class="text-base font-bold text-[#172B3A]">Contenido de la página pública</h3>
            <p class="text-sm text-[#64748B]">Imagen principal y tarjetas que verá el visitante. Las imágenes (JPG, PNG o WebP, hasta 3 MB) se guardan en el servidor; nunca se publican hasta autorizar la ficha y activar el catálogo.</p>
            <p data-contenido-bloqueado hidden class="rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-4 text-sm text-[#172B3A]">La ficha está autorizada: retírala para modificar su imagen o sus tarjetas.</p>
            <form data-form="portada" method="POST" enctype="multipart/form-data" hidden>
                @csrf
                <fieldset class="space-y-3"><legend class="mb-2 text-sm font-bold text-[#172B3A]">Imagen principal</legend>
                    <img data-portada-imagen alt="Imagen principal actual" hidden class="max-h-40 rounded-xl border border-[#E2E8F0] object-contain">
                    <div><label for="portada-imagen" class="admin-label">Cambiar imagen principal</label><input id="portada-imagen" name="imagen" type="file" accept="image/jpeg,image/png,image/webp" class="admin-input"></div>
                    <button type="submit" class="admin-btn-secondary">Subir imagen principal</button>
                </fieldset>
            </form>
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3"><h4 class="text-sm font-bold text-[#172B3A]">Tarjetas</h4>
                    <button type="button" data-accion="tarjeta-nueva" class="admin-btn-outline px-3.5 py-2 text-xs">Nueva tarjeta</button></div>
                <p data-sin-tarjetas hidden class="text-sm text-[#64748B]">Aún no hay tarjetas.</p>
                <ul data-tarjetas class="space-y-2" aria-label="Tarjetas de la página"></ul>
            </div>
            <form data-form="tarjeta" method="POST" enctype="multipart/form-data" hidden>
                @csrf
                <fieldset class="space-y-4 rounded-xl border border-[#E2E8F0] p-4"><legend class="px-1 text-sm font-bold text-[#172B3A]">Datos de la tarjeta</legend>
                    <div><label for="tarjeta-titulo" class="admin-label">Título</label><input id="tarjeta-titulo" name="titulo" required maxlength="120" class="admin-input"></div>
                    <div><label for="tarjeta-descripcion" class="admin-label">Descripción</label><textarea id="tarjeta-descripcion" name="descripcion" required maxlength="1000" rows="4" class="admin-textarea"></textarea></div>
                    <div><label for="tarjeta-caracteristicas" class="admin-label">Características o especificaciones (una por línea)</label><textarea id="tarjeta-caracteristicas" name="caracteristicas" rows="4" class="admin-textarea"></textarea></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="min-w-0"><label for="tarjeta-icono" class="admin-label">Icono (opcional)</label><input id="tarjeta-icono" name="icono" maxlength="60" placeholder="fas fa-video" pattern="fa[srb] fa-[a-z0-9]+(-[a-z0-9]+)*" class="admin-input min-w-0" aria-describedby="icono-ayuda"><p id="icono-ayuda" class="admin-help">Clase Font Awesome ya usada en el sitio, por ejemplo «fas fa-bolt».</p></div>
                        <div class="min-w-0"><label for="tarjeta-variante" class="admin-label">Encuadre de la fotografía</label>
                            <select id="tarjeta-variante" name="variante_imagen" class="admin-select"><option value="">Estándar</option><option value="producto">Producto</option><option value="escena">Escena</option></select></div>
                        <div class="min-w-0"><label for="tarjeta-orden" class="admin-label">Orden de visualización</label><input id="tarjeta-orden" name="orden" type="number" min="0" max="9999" class="admin-input min-w-0" aria-describedby="orden-ayuda"><p id="orden-ayuda" class="admin-help">Menor número, primero. Vacío = al final.</p></div>
                        <div class="min-w-0"><label for="tarjeta-imagen_alt" class="admin-label">Texto alternativo de la fotografía</label><input id="tarjeta-imagen_alt" name="imagen_alt" maxlength="160" class="admin-input min-w-0"></div>
                    </div>
                    <div><label for="tarjeta-imagen" class="admin-label">Fotografía (subir o cambiar)</label><input id="tarjeta-imagen" name="imagen" type="file" accept="image/jpeg,image/png,image/webp" class="admin-input"></div>
                    <label class="flex min-h-11 items-center gap-3 text-sm text-[#172B3A]"><input type="checkbox" name="quitar_imagen" class="h-5 w-5 rounded border-[#CBD5E1] text-[#176B9A]"><span>Quitar la fotografía actual</span></label>
                    <label class="flex min-h-11 items-center gap-3 text-sm text-[#172B3A]"><input type="checkbox" name="activo" class="h-5 w-5 rounded border-[#CBD5E1] text-[#176B9A]"><span>Tarjeta activa (visible cuando la ficha esté publicada)</span></label>
                    <div class="flex flex-wrap gap-3"><button type="submit" class="admin-btn-primary">Guardar tarjeta</button><button type="button" data-accion="tarjeta-cancelar" class="admin-btn-outline">Cancelar</button></div>
                </fieldset>
            </form>
        </section>
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
