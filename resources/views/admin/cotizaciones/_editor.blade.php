<section data-editor hidden class="min-w-0 space-y-5 rounded-2xl border border-gold/20 bg-white p-5 shadow-sm" aria-labelledby="cotizacion-titulo">
    <h2 id="cotizacion-titulo" tabindex="-1" class="text-xl font-bold">Solicitud</h2>
    <p class="text-sm text-stone-600">Borradores solo en memoria de esta página. No incluyas secretos ni documentos sensibles.</p>
    <div data-detalle hidden class="grid min-w-0 gap-3 text-sm sm:grid-cols-2">
        @foreach (['codigo' => 'Código', 'estado' => 'Estado', 'recibida_at' => 'Recepción', 'responsable_id' => 'Responsable (ID)', 'servicio_uuid' => 'UUID del servicio', 'servicio_nombre_historico' => 'Nombre histórico', 'proximo_seguimiento' => 'Próximo seguimiento', 'revision' => 'Revisión'] as $campo => $etiqueta)
            <div class="min-w-0"><strong>{{ $etiqueta }}</strong><p data-dato="{{ $campo }}" class="whitespace-pre-wrap [overflow-wrap:anywhere]"></p></div>
        @endforeach
        @foreach (['contacto_nombre' => 'Nombre de contacto', 'contacto_email' => 'Correo de contacto', 'contacto_telefono' => 'Teléfono de contacto', 'necesidad_cliente' => 'Necesidad del cliente'] as $campo => $etiqueta)
            <div class="min-w-0 sm:col-span-2"><strong>{{ $etiqueta }}</strong><p data-dato="{{ $campo }}" class="whitespace-pre-wrap [overflow-wrap:anywhere]"></p></div>
        @endforeach
    </div>
    <div data-conciliacion hidden class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
        <p>El borrador se conserva. Compara la versión guardada antes de decidir. Una lectura coincidente no demuestra quién realizó el cambio.</p>
        <button type="button" data-accion="comparar" class="min-h-11 rounded-xl border border-stone-400 px-4 py-2">Comparar versión actual</button>
        <pre data-comparacion hidden tabindex="0" aria-label="Versión actual para comparar" class="whitespace-pre-wrap font-sans [overflow-wrap:anywhere]"></pre>
        <button type="button" data-accion="adoptar" hidden class="min-h-11 rounded-xl border border-stone-400 px-4 py-2">Confirmar revisión comparada y conservar borradores</button>
        <button type="button" data-accion="conciliar" hidden class="min-h-11 rounded-xl border border-stone-400 px-4 py-2">Conciliar misma intención y misma clave</button>
        <button type="button" data-accion="revisado" hidden class="min-h-11 rounded-xl border border-stone-400 px-4 py-2">Confirmar revisión del resultado sin reenviar</button>
    </div>
    @if (auth()->user()->can('cotizaciones.crear') || auth()->user()->can('cotizaciones.editar'))
        <form data-form="informacion" autocomplete="off"><fieldset class="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend class="mb-3 font-semibold">Información de la solicitud</legend>
            @foreach (['contacto_nombre' => ['Nombre de contacto', 120], 'contacto_email' => ['Correo de contacto', 254], 'contacto_telefono' => ['Teléfono de contacto', 30], 'servicio_uuid' => ['UUID del servicio (opcional, asociación única)', 36]] as $campo => [$etiqueta, $maximo])
                <div class="min-w-0"><label for="c-{{ $campo }}" class="block text-sm font-semibold">{{ $etiqueta }}</label><input id="c-{{ $campo }}" name="{{ $campo }}" maxlength="{{ $maximo }}" @required($campo === 'contacto_nombre') type="{{ $campo === 'contacto_email' ? 'email' : 'text' }}" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-stone-300 p-3"></div>
            @endforeach
            <div class="sm:col-span-2"><label for="c-necesidad" class="block text-sm font-semibold">Necesidad del cliente</label><textarea id="c-necesidad" name="necesidad_cliente" required maxlength="5000" rows="5" class="mt-1 w-full min-w-0 rounded-xl border border-stone-300 p-3"></textarea><p class="text-sm">Indica al menos correo o teléfono. La asociación requiere el UUID administrativo existente; no publica ni garantiza disponibilidad.</p></div>
            <button type="submit" class="min-h-11 rounded-xl bg-gold px-4 py-3 font-semibold">Guardar información</button>
        </fieldset></form>
    @endif
    @can('cotizaciones.gestionar')
        <div data-gestion hidden class="space-y-5">
            <form data-form="estado"><fieldset><legend class="mb-2 font-semibold">Estado operativo</legend><button type="submit" data-transicion class="min-h-11 rounded-xl border border-stone-300 px-4 py-2">Pasar a en revisión</button></fieldset></form>
            <form data-form="responsable"><fieldset class="space-y-2"><legend class="font-semibold">Responsable</legend><label for="c-responsable" class="block text-sm">ID de personal autorizado con permiso de lectura; vacío desasigna</label><input id="c-responsable" name="responsable_id" type="number" min="1" max="2147483647" class="min-h-11 w-full rounded-xl border border-stone-300 p-3"><button type="submit" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2">Guardar responsable</button></fieldset></form>
            <form data-form="seguimiento" autocomplete="off"><fieldset class="space-y-3"><legend class="font-semibold">Seguimiento privado</legend><label for="c-nota" class="block text-sm">Nota privada</label><textarea id="c-nota" name="nota" required maxlength="3000" rows="4" class="w-full min-w-0 rounded-xl border border-stone-300 p-3"></textarea><label for="c-proximo" class="block text-sm">Próxima fecha (vacío elimina la fecha anterior)</label><input id="c-proximo" name="proximo_seguimiento" type="date" class="min-h-11 w-full min-w-0 rounded-xl border border-stone-300 p-3"><button type="submit" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2">Registrar seguimiento</button></fieldset></form>
        </div>
    @endcan
    <section data-historial hidden class="space-y-3" aria-label="Historial privado"><h3 class="font-semibold">Historial privado</h3><div data-eventos class="space-y-3"></div><p data-rango-historial class="text-sm"></p><div class="flex flex-wrap gap-3"><button type="button" data-accion="historial-anterior" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2">Historial anterior</button><button type="button" data-accion="historial-siguiente" class="min-h-11 rounded-xl border border-stone-300 px-4 py-2">Historial siguiente</button></div></section>
</section>
