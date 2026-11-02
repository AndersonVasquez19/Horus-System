<section data-editor hidden class="min-w-0 space-y-5 admin-card sm:p-8" aria-labelledby="cotizacion-titulo">
    <div>
        <h2 id="cotizacion-titulo" tabindex="-1" class="text-xl font-bold tracking-tight text-[#172B3A]">Solicitud</h2>
        <p class="mt-1 text-sm text-[#64748B]">Borradores solo en memoria de esta página. No incluyas secretos ni documentos sensibles.</p>
    </div>
    <div data-detalle hidden class="grid min-w-0 gap-3 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] p-4 text-sm sm:grid-cols-2">
        @foreach (['codigo' => 'Código', 'estado' => 'Estado', 'recibida_at' => 'Recepción', 'responsable_id' => 'Responsable (ID)', 'servicio_uuid' => 'UUID del servicio', 'servicio_nombre_historico' => 'Nombre histórico', 'proximo_seguimiento' => 'Próximo seguimiento', 'revision' => 'Revisión'] as $campo => $etiqueta)
            <div class="min-w-0"><strong class="block text-xs font-semibold uppercase tracking-wide text-[#64748B]">{{ $etiqueta }}</strong><p data-dato="{{ $campo }}" class="mt-0.5 font-medium text-[#172B3A] whitespace-pre-wrap [overflow-wrap:anywhere]"></p></div>
        @endforeach
        @foreach (['contacto_nombre' => 'Nombre de contacto', 'contacto_email' => 'Correo de contacto', 'contacto_telefono' => 'Teléfono de contacto', 'necesidad_cliente' => 'Necesidad del cliente'] as $campo => $etiqueta)
            <div class="min-w-0 sm:col-span-2"><strong class="block text-xs font-semibold uppercase tracking-wide text-[#64748B]">{{ $etiqueta }}</strong><p data-dato="{{ $campo }}" class="mt-0.5 font-medium text-[#172B3A] whitespace-pre-wrap [overflow-wrap:anywhere]"></p></div>
        @endforeach
    </div>
    <div data-conciliacion hidden class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <p>El borrador se conserva. Compara la versión guardada antes de decidir. Una lectura coincidente no demuestra quién realizó el cambio.</p>
        <button type="button" data-accion="comparar" class="admin-btn-outline">Comparar versión actual</button>
        <pre data-comparacion hidden tabindex="0" aria-label="Versión actual para comparar" class="rounded-lg border border-amber-200 bg-white p-3 whitespace-pre-wrap font-sans text-xs text-[#172B3A] [overflow-wrap:anywhere]"></pre>
        <button type="button" data-accion="adoptar" hidden class="admin-btn-secondary">Confirmar revisión comparada y conservar borradores</button>
        <button type="button" data-accion="conciliar" hidden class="admin-btn-primary">Conciliar misma intención y misma clave</button>
        <button type="button" data-accion="revisado" hidden class="admin-btn-outline">Confirmar revisión del resultado sin reenviar</button>
    </div>
    @if (auth()->user()->can('cotizaciones.crear') || auth()->user()->can('cotizaciones.editar'))
        <form data-form="informacion" autocomplete="off"><fieldset class="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend class="mb-3 text-base font-bold text-[#172B3A]">Información de la solicitud</legend>
            @foreach (['contacto_nombre' => ['Nombre de contacto', 120], 'contacto_email' => ['Correo de contacto', 254], 'contacto_telefono' => ['Teléfono de contacto', 30], 'servicio_uuid' => ['UUID del servicio (opcional, asociación única)', 36]] as $campo => [$etiqueta, $maximo])
                <div class="min-w-0"><label for="c-{{ $campo }}" class="admin-label">{{ $etiqueta }}</label><input id="c-{{ $campo }}" name="{{ $campo }}" maxlength="{{ $maximo }}" @required($campo === 'contacto_nombre') type="{{ $campo === 'contacto_email' ? 'email' : 'text' }}" class="admin-input min-w-0"></div>
            @endforeach
            <div class="sm:col-span-2"><label for="c-necesidad" class="admin-label">Necesidad del cliente</label><textarea id="c-necesidad" name="necesidad_cliente" required maxlength="5000" rows="5" class="admin-textarea min-w-0"></textarea><p class="admin-help">Indica al menos correo o teléfono. La asociación requiere el UUID administrativo existente; no publica ni garantiza disponibilidad.</p></div>
            <div><button type="submit" class="admin-btn-primary">Guardar información</button></div>
        </fieldset></form>
    @endif
    @can('cotizaciones.gestionar')
        <div data-gestion hidden class="space-y-5 border-t border-[#E2E8F0] pt-5">
            <form data-form="estado"><fieldset><legend class="mb-2 text-base font-bold text-[#172B3A]">Estado operativo</legend><button type="submit" data-transicion class="admin-btn-outline">Pasar a en revisión</button></fieldset></form>
            <form data-form="responsable"><fieldset class="space-y-2"><legend class="text-base font-bold text-[#172B3A]">Responsable</legend><label for="c-responsable" class="admin-label">ID de personal autorizado con permiso de lectura; vacío desasigna</label><input id="c-responsable" name="responsable_id" type="number" min="1" max="2147483647" class="admin-input"><button type="submit" class="admin-btn-outline">Guardar responsable</button></fieldset></form>
            <form data-form="seguimiento" autocomplete="off"><fieldset class="space-y-3"><legend class="text-base font-bold text-[#172B3A]">Seguimiento privado</legend><label for="c-nota" class="admin-label">Nota privada</label><textarea id="c-nota" name="nota" required maxlength="3000" rows="4" class="admin-textarea min-w-0"></textarea><label for="c-proximo" class="admin-label">Próxima fecha (vacío elimina la fecha anterior)</label><input id="c-proximo" name="proximo_seguimiento" type="date" class="admin-input min-w-0"><button type="submit" class="admin-btn-secondary">Registrar seguimiento</button></fieldset></form>
        </div>
    @endcan
    <section data-historial hidden class="space-y-3 border-t border-[#E2E8F0] pt-5" aria-label="Historial privado"><h3 class="text-base font-bold text-[#172B3A]">Historial privado</h3><div data-eventos class="space-y-3 text-sm text-[#172B3A]"></div><p data-rango-historial class="text-sm text-[#64748B]"></p><div class="flex flex-wrap gap-3"><button type="button" data-accion="historial-anterior" class="admin-btn-outline">Historial anterior</button><button type="button" data-accion="historial-siguiente" class="admin-btn-outline">Historial siguiente</button></div></section>
</section>
