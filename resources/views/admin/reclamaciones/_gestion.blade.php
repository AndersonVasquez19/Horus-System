@use('App\Support\OldInput')
@use('App\Support\HoraLocal')
{{--
    H. Gestión del caso (E4). Solo propone las operaciones que el estado admite: la autoridad es
    GestionReclamaciones (permiso, estado y reglas bajo bloqueo). Cada formulario envía `operacion` a la
    única ruta de gestión; si vuelve con errores, su modal se reabre según old('operacion').
--}}
@php
    $abierto = $reclamacion->estado === 'abierto';
    $propuestaPendiente = $reclamacion->suspension_desde !== null && $reclamacion->suspension_hasta !== null;
    $puedeGestionar = auth()->user()?->can('reclamaciones.gestionar') ?? false;
    $puedeResponder = auth()->user()?->can('reclamaciones.responder') ?? false;
    $operacionPrevia = $errors->any() ? (string) old('operacion') : '';
    $responsableActual = collect($responsables)->firstWhere('id', $reclamacion->responsable_id);
    $accionGestion = route('admin.reclamaciones.gestionar', $reclamacion);
    $hoy = HoraLocal::hoy();
    $fechaPorDefecto = $propuestaPendiente
        ? min(max($hoy, $reclamacion->suspension_desde->toDateString()), $reclamacion->suspension_hasta->toDateString())
        : $hoy;
@endphp

<section class="admin-card sm:p-7" aria-labelledby="gestion-titulo" data-gestion-reclamacion>
    <div class="mb-5 flex items-center justify-between border-b border-[#E2E8F0] pb-4">
        <div class="flex items-center gap-2">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#19A7A1]/15 text-sm text-[#0F766E]">
                <i class="fa-solid fa-list-check" aria-hidden="true"></i>
            </span>
            <h2 id="gestion-titulo" class="text-base font-bold text-[#172B3A]">H. Gestión del caso</h2>
        </div>
        <span class="text-xs font-semibold text-[#64748B]">Cada acción queda en el historial y la auditoría</span>
    </div>

    @if ($errors->has('gestion'))
        <x-admin.error-summary class="mb-5" title="La operación no se pudo completar:" :errors="new \Illuminate\Support\MessageBag(['gestion' => $errors->get('gestion')])" />
    @endif

    <dl class="mb-5 grid gap-x-8 gap-y-4 sm:grid-cols-3">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Responsable</dt>
            <dd class="mt-1 font-semibold text-[#172B3A]">{{ $responsableActual['name'] ?? ($reclamacion->responsable_id ? 'Usuario sin acceso al libro' : 'Sin asignar') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Propuesta de solución</dt>
            <dd class="mt-1 font-medium text-[#172B3A]">
                {{ $propuestaPendiente ? 'Pendiente hasta el '.$reclamacion->suspension_hasta->format('d/m/Y') : 'Ninguna pendiente' }}
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-[#64748B]">Situación</dt>
            <dd class="mt-1 font-medium text-[#172B3A]">{{ $abierto ? 'Abierto: admite gestión' : 'Cerrado: solo notas y reintentos' }}</dd>
        </div>
    </dl>

    <div class="flex flex-wrap gap-2">
        @if ($puedeGestionar)
            <button type="button" data-admin-modal-open="gestion-responsable" class="admin-btn-outline">
                <i class="fa-solid fa-user-check" aria-hidden="true"></i> Asignar responsable
            </button>
            @if ($abierto)
                <button type="button" data-admin-modal-open="gestion-tipo" class="admin-btn-outline">
                    <i class="fa-solid fa-tags" aria-hidden="true"></i> Reclasificar
                </button>
            @endif
            <button type="button" data-admin-modal-open="gestion-nota" class="admin-btn-outline">
                <i class="fa-solid fa-note-sticky" aria-hidden="true"></i> Nota interna
            </button>
            @if ($abierto && $reclamacion->tipo === 'reclamo' && ! $propuestaPendiente)
                <button type="button" data-admin-modal-open="gestion-propuesta" class="admin-btn-outline">
                    <i class="fa-solid fa-handshake" aria-hidden="true"></i> Propuesta de solución
                </button>
            @endif
            @if ($abierto && $propuestaPendiente)
                <button type="button" data-admin-modal-open="gestion-rechazo" class="admin-btn-outline">
                    <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Registrar rechazo
                </button>
            @endif
        @endif
        @if ($puedeResponder && $abierto && $propuestaPendiente)
            <button type="button" data-admin-modal-open="gestion-aceptacion" class="admin-btn-outline">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Registrar aceptación
            </button>
        @endif
        @if ($puedeResponder && $abierto && ! $propuestaPendiente)
            <button type="button" data-admin-modal-open="gestion-respuesta" class="admin-btn-primary">
                <i class="fa-solid fa-reply" aria-hidden="true"></i> Respuesta formal
            </button>
        @endif
    </div>
    @if ($abierto && $propuestaPendiente && $puedeResponder)
        <p class="mt-3 text-xs text-[#64748B]">La respuesta formal se habilita cuando se resuelva la propuesta pendiente.</p>
    @endif
</section>

{{-- Errores de validación del formulario que se reabre (el 409 se muestra arriba, en la sección). --}}
@php
    $erroresFormulario = function (string $operacion) use ($errors, $operacionPrevia) {
        return $operacionPrevia === $operacion ? collect($errors->getMessages())->except('gestion')->flatten()->all() : [];
    };
@endphp

@if ($puedeGestionar)
    <x-admin.form-modal id="gestion-responsable" title="Asignar responsable" :auto-open="$operacionPrevia === 'responsable'">
        @include('admin.reclamaciones._gestion-errores', ['mensajes' => $erroresFormulario('responsable')])
        <form action="{{ $accionGestion }}" method="POST" class="space-y-5">
            @csrf
            <input type="hidden" name="operacion" value="responsable">
            <div>
                <label for="gestion-responsable-id" class="admin-label">Responsable</label>
                @php($seleccionado = $operacionPrevia === 'responsable' ? OldInput::value('responsable_id') : (string) ($reclamacion->responsable_id ?? ''))
                <select id="gestion-responsable-id" name="responsable_id" class="admin-select">
                    <option value="">Sin responsable</option>
                    @foreach ($responsables as $responsable)
                        <option value="{{ $responsable['id'] }}" @selected($seleccionado === (string) $responsable['id'])>{{ $responsable['name'] }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-[#64748B]">Solo personal del panel con permiso para ver reclamaciones.</p>
            </div>
            <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
            </div>
        </form>
    </x-admin.form-modal>

    @if ($abierto)
        <x-admin.form-modal id="gestion-tipo" title="Reclasificar el registro" :auto-open="$operacionPrevia === 'tipo'">
            @include('admin.reclamaciones._gestion-errores', ['mensajes' => $erroresFormulario('tipo')])
            <form action="{{ $accionGestion }}" method="POST" class="space-y-5">
                @csrf
                <input type="hidden" name="operacion" value="tipo">
                <fieldset>
                    <legend class="admin-label">Tipo de registro</legend>
                    <div class="mt-2 space-y-2">
                        <label class="flex items-center gap-2 text-sm text-[#172B3A]">
                            <input type="radio" name="tipo" value="queja" class="admin-checkbox" @checked($reclamacion->tipo === 'queja')>
                            Queja (malestar por la atención, sin bien o servicio reclamado)
                        </label>
                        <label class="flex items-center gap-2 text-sm text-[#172B3A]">
                            <input type="radio" name="tipo" value="reclamo" class="admin-checkbox" @checked($reclamacion->tipo === 'reclamo') @disabled($reclamacion->bien_tipo === null)>
                            Reclamo (disconformidad con un bien o servicio)
                        </label>
                    </div>
                    @if ($reclamacion->bien_tipo === null)
                        <p class="mt-2 text-xs text-[#64748B]">No se puede pasar a reclamo: el registro no identifica un bien o servicio.</p>
                    @endif
                </fieldset>
                <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                    <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
                </div>
            </form>
        </x-admin.form-modal>
    @endif

    <x-admin.form-modal id="gestion-nota" title="Nota interna" :auto-open="$operacionPrevia === 'nota'">
        @include('admin.reclamaciones._gestion-errores', ['mensajes' => $erroresFormulario('nota')])
        <form action="{{ $accionGestion }}" method="POST" class="space-y-5">
            @csrf
            <input type="hidden" name="operacion" value="nota">
            <div>
                <label for="gestion-nota-texto" class="admin-label">Nota <span class="text-red-600">*</span></label>
                <textarea id="gestion-nota-texto" name="nota" rows="5" maxlength="2000" required class="admin-textarea">{{ $operacionPrevia === 'nota' ? OldInput::value('nota') : '' }}</textarea>
                <p class="mt-1 text-xs text-[#64748B]">Uso interno: el consumidor no la ve. Queda en el historial y no se puede borrar.</p>
            </div>
            <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Registrar nota</button>
            </div>
        </form>
    </x-admin.form-modal>

    @if ($abierto && $reclamacion->tipo === 'reclamo' && ! $propuestaPendiente)
        <x-admin.form-modal id="gestion-propuesta" title="Propuesta de solución" :auto-open="$operacionPrevia === 'propuesta'">
            @include('admin.reclamaciones._gestion-errores', ['mensajes' => $erroresFormulario('propuesta')])
            <form action="{{ $accionGestion }}" method="POST" class="space-y-5">
                @csrf
                <input type="hidden" name="operacion" value="propuesta">
                <p class="text-sm text-[#475569]">Suspende el plazo legal hasta {{ (int) config('reclamaciones.suspension_dias_habiles', 5) }} días hábiles mientras el consumidor responde.</p>
                @include('admin.reclamaciones._gestion-aviso-correo', ['prefijo' => 'gestion-propuesta', 'operacion' => 'propuesta'])
                <div>
                    <label for="gestion-propuesta-asunto" class="admin-label">Asunto <span class="text-red-600">*</span></label>
                    <input id="gestion-propuesta-asunto" name="asunto" type="text" maxlength="200" required class="admin-input"
                           value="{{ $operacionPrevia === 'propuesta' ? OldInput::value('asunto') : 'Propuesta de solución a su reclamación N° '.$reclamacion->numero }}">
                </div>
                <div>
                    <label for="gestion-propuesta-cuerpo" class="admin-label">Texto de la propuesta <span class="text-red-600">*</span></label>
                    <textarea id="gestion-propuesta-cuerpo" name="cuerpo" rows="8" maxlength="10000" required class="admin-textarea">{{ $operacionPrevia === 'propuesta' ? OldInput::value('cuerpo') : '' }}</textarea>
                </div>
                <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                    <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Registrar propuesta</button>
                </div>
            </form>
        </x-admin.form-modal>
    @endif

    @if ($abierto && $propuestaPendiente)
        <x-admin.form-modal id="gestion-rechazo" title="Rechazo de la propuesta" :auto-open="$operacionPrevia === 'rechazo'">
            @include('admin.reclamaciones._gestion-errores', ['mensajes' => $erroresFormulario('rechazo')])
            <form action="{{ $accionGestion }}" method="POST" class="space-y-5">
                @csrf
                <input type="hidden" name="operacion" value="rechazo">
                <p class="text-sm text-[#475569]">El plazo legal se reanuda y la fecha límite se desplaza por los días hábiles suspendidos.</p>
                <div>
                    <label for="gestion-rechazo-fecha" class="admin-label">Fecha en que se recibió el rechazo <span class="text-red-600">*</span></label>
                    <input id="gestion-rechazo-fecha" name="fecha_recepcion" type="date" required class="admin-input"
                           min="{{ $reclamacion->suspension_desde->toDateString() }}" max="{{ $reclamacion->suspension_hasta->toDateString() }}"
                           value="{{ $operacionPrevia === 'rechazo' ? OldInput::value('fecha_recepcion') : $fechaPorDefecto }}">
                </div>
                <div>
                    <label for="gestion-rechazo-nota" class="admin-label">Nota (opcional)</label>
                    <textarea id="gestion-rechazo-nota" name="nota" rows="3" maxlength="2000" class="admin-textarea">{{ $operacionPrevia === 'rechazo' ? OldInput::value('nota') : '' }}</textarea>
                </div>
                <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                    <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Registrar rechazo</button>
                </div>
            </form>
        </x-admin.form-modal>
    @endif
@endif

@if ($puedeResponder && $abierto && $propuestaPendiente)
    <x-admin.form-modal id="gestion-aceptacion" title="Aceptación de la propuesta" :auto-open="$operacionPrevia === 'aceptacion'">
        @include('admin.reclamaciones._gestion-errores', ['mensajes' => $erroresFormulario('aceptacion')])
        <form action="{{ $accionGestion }}" method="POST" class="space-y-5" data-confirm="El caso concluirá por acuerdo y no podrá reabrirse. ¿Continuar?">
            @csrf
            <input type="hidden" name="operacion" value="aceptacion">
            <div>
                <label for="gestion-aceptacion-fecha" class="admin-label">Fecha en que se recibió la aceptación <span class="text-red-600">*</span></label>
                <input id="gestion-aceptacion-fecha" name="fecha_recepcion" type="date" required class="admin-input"
                       min="{{ $reclamacion->suspension_desde->toDateString() }}" max="{{ $reclamacion->suspension_hasta->toDateString() }}"
                       value="{{ $operacionPrevia === 'aceptacion' ? OldInput::value('fecha_recepcion') : $fechaPorDefecto }}">
            </div>
            <div>
                <label for="gestion-aceptacion-medio" class="admin-label">Medio <span class="text-red-600">*</span></label>
                @php($medioPrevio = $operacionPrevia === 'aceptacion' ? OldInput::value('medio', 'correo') : 'correo')
                <select id="gestion-aceptacion-medio" name="medio" class="admin-select">
                    <option value="correo" @selected($medioPrevio === 'correo')>Correo electrónico</option>
                    <option value="otro" @selected($medioPrevio === 'otro')>Otro medio</option>
                </select>
            </div>
            <div>
                <label for="gestion-aceptacion-constancia" class="admin-label">Constancia de la aceptación <span class="text-red-600">*</span></label>
                <textarea id="gestion-aceptacion-constancia" name="constancia" rows="4" maxlength="2000" required class="admin-textarea">{{ $operacionPrevia === 'aceptacion' ? OldInput::value('constancia') : '' }}</textarea>
                <p class="mt-1 text-xs text-[#64748B]">Pega el texto del correo o describe cómo se recibió la aceptación expresa.</p>
            </div>
            <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Registrar aceptación</button>
            </div>
        </form>
    </x-admin.form-modal>
@endif

@if ($puedeResponder && $abierto && ! $propuestaPendiente)
    <x-admin.form-modal id="gestion-respuesta" title="Respuesta formal" :auto-open="$operacionPrevia === 'respuesta'">
        @include('admin.reclamaciones._gestion-errores', ['mensajes' => $erroresFormulario('respuesta')])
        <form action="{{ $accionGestion }}" method="POST" class="space-y-5">
            @csrf
            <input type="hidden" name="operacion" value="respuesta">
            <p class="text-sm text-[#475569]">
                Canal preferido por el consumidor: <strong>{{ ucfirst($reclamacion->canal_respuesta) }}</strong>.
                Por carta, el caso queda respondido al registrar la constancia; por correo, cuando el correo se envía.
            </p>
            @php($canalPrevio = $operacionPrevia === 'respuesta' ? OldInput::value('canal', 'correo') : ($reclamacion->canal_respuesta === 'carta' ? 'carta' : 'correo'))
            <fieldset>
                <legend class="admin-label">Canal <span class="text-red-600">*</span></legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm text-[#172B3A]">
                        <input type="radio" name="canal" value="correo" class="admin-checkbox" @checked($canalPrevio === 'correo')> Correo electrónico
                    </label>
                    <label class="flex items-center gap-2 text-sm text-[#172B3A]">
                        <input type="radio" name="canal" value="carta" class="admin-checkbox" @checked($canalPrevio === 'carta')> Carta
                    </label>
                </div>
            </fieldset>
            @include('admin.reclamaciones._gestion-aviso-correo', ['prefijo' => 'gestion-respuesta', 'operacion' => 'respuesta', 'soloCorreo' => true])
            <div>
                <label for="gestion-respuesta-texto" class="admin-label">Texto de la respuesta <span class="text-red-600">*</span></label>
                <textarea id="gestion-respuesta-texto" name="respuesta" rows="10" maxlength="10000" required class="admin-textarea">{{ $operacionPrevia === 'respuesta' ? OldInput::value('respuesta') : '' }}</textarea>
            </div>
            <div>
                <label for="gestion-respuesta-constancia" class="admin-label">Constancia de la carta (obligatoria si es por carta)</label>
                <input id="gestion-respuesta-constancia" name="constancia" type="text" maxlength="255" class="admin-input"
                       placeholder="Fecha, medio y número de cargo o guía"
                       value="{{ $operacionPrevia === 'respuesta' ? OldInput::value('constancia') : '' }}">
            </div>
            <div class="flex justify-end border-t border-[#E2E8F0] pt-4">
                <button type="submit" class="admin-btn-primary"><i class="fa-solid fa-reply" aria-hidden="true"></i> Registrar respuesta</button>
            </div>
        </form>
    </x-admin.form-modal>
@endif
