@props(['endpoint', 'public' => false])

@if (! $public)
<div
    x-data="chatClient"
    data-endpoint="{{ $endpoint }}"
    data-csrf-token="{{ csrf_token() }}"
    class="flex h-full min-h-0 flex-col overflow-hidden"
>
    <div x-ref="messages" @scroll.passive="trackScroll" class="flex-1 space-y-4 overflow-y-auto bg-[#F4F7FB] p-4 sm:p-5" aria-live="polite">
        <template x-for="(message, index) in messages" :key="index">
            <div class="flex items-end gap-2.5" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
                <span x-show="message.role === 'bot'" class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-[#19A7A1]/30 bg-[#0A2540] p-0.5 shadow-sm" aria-hidden="true">
                    <img src="{{ asset('assets/images/index/stiker/stiker.png') }}" alt="" width="32" height="32" class="h-full w-full object-contain" draggable="false">
                </span>
                <p
                    x-text="message.text"
                    :class="message.role === 'user' ? 'rounded-2xl rounded-br-md bg-[#176B9A] text-white' : 'rounded-2xl rounded-bl-md border border-[#E2E8F0] bg-white text-[#172B3A]'"
                    class="max-w-[85%] whitespace-pre-line px-4 py-3 text-sm leading-6 shadow-[0_2px_10px_rgba(10,37,64,0.05)]"
                ></p>
            </div>
        </template>
        <div x-show="sending" class="flex items-end gap-2.5" aria-label="El asistente está escribiendo">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-[#19A7A1]/30 bg-[#0A2540] p-0.5 shadow-sm" aria-hidden="true">
                <img src="{{ asset('assets/images/index/stiker/stiker.png') }}" alt="" width="32" height="32" class="h-full w-full object-contain" draggable="false">
            </span>
            <div class="rounded-2xl rounded-bl-md border border-[#E2E8F0] bg-white px-4 py-3 text-sm text-[#64748B] shadow-sm">
                <span class="mr-2 inline-flex h-2 w-2 animate-pulse rounded-full bg-[#19A7A1]"></span>
                Escribiendo...
            </div>
        </div>
    </div>

    <form @submit.prevent="sendMessage" class="flex items-center gap-2.5 border-t border-[#E2E8F0] bg-white p-3 sm:p-4">
        <label for="mensaje-chat" class="sr-only">Escribe tu mensaje</label>
        <input
            id="mensaje-chat"
            x-ref="question"
            x-model="draft"
            type="text"
            maxlength="2000"
            autocomplete="off"
            placeholder="Escribe tu consulta..."
            class="min-w-0 flex-1 rounded-full border border-[#E2E8F0] bg-[#F4F7FB] px-4 py-3 text-sm text-[#172B3A] outline-none transition placeholder:text-[#64748B] focus:border-[#176B9A] focus:bg-white focus:ring-2 focus:ring-[#176B9A]/15"
        >
        <button
            type="submit"
            :disabled="sending || !draft.trim()"
            aria-label="Enviar mensaje"
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#176B9A] text-white shadow-sm shadow-[#176B9A]/20 transition hover:bg-[#0A2540] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#176B9A] disabled:cursor-not-allowed disabled:opacity-50"
        >
            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
        </button>
    </form>
</div>
@else

<div
    x-data="chatClient"
    data-endpoint="{{ $endpoint }}"
    data-csrf-token="{{ csrf_token() }}"
    class="hc-conversation"
>
    <div x-ref="messages" @scroll.passive="trackScroll" class="hc-log" role="log" tabindex="0" aria-label="Conversación con Horus" aria-live="polite" aria-relevant="additions" :aria-busy="sending.toString()">
        <template x-for="(message, index) in messages" :key="index">
            <article class="hc-message" :class="[message.role === 'user' ? 'hc-user' : 'hc-assistant', (message.cards || []).length ? 'hc-message-with-cards' : '']">
                <strong>
                    <x-public.robot-mascot x-show="message.role === 'bot'" :portrait="true" class="hc-message-avatar" />
                    <span x-text="message.role === 'user' ? 'Tú' : 'Horus'"></span>
                </strong>
                <p x-text="message.text"></p>
                <div class="hc-cards" x-show="(message.cards || []).length" aria-label="Contenidos publicados relacionados">
                    <template x-for="card in (message.cards || [])" :key="card.id">
                        <section class="hc-card" :aria-label="card.titulo">
                            <div class="hc-card-cover" :class="card.tipo === 'servicio' ? 'hc-card-cover-service' : ''">
                                <img x-show="card.imagen" :src="card.imagen || null" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" x-on:error="card.imagen = null">
                                <span class="hc-card-placeholder" x-show="!card.imagen" aria-hidden="true"><i :class="card.tipo === 'servicio' ? 'fa-solid fa-laptop-code' : 'fa-solid fa-book-open'"></i></span>
                                <span class="hc-card-cover-label" x-show="card.imagen">Imagen de referencia</span>
                            </div>
                            <div class="hc-card-body">
                                <span class="hc-card-kind" x-text="card.tipo === 'servicio' ? 'Servicio tecnológico' : 'Curso y capacitación'"></span>
                                <h3 x-text="card.titulo"></h3>
                                <p x-text="card.descripcion"></p>
                                <a x-show="card.url" :href="card.url || null" class="hc-card-action" :aria-label="(card.accion || 'Ver información') + ': ' + card.titulo"><span x-text="card.accion || 'Ver información'"></span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                                <span class="hc-card-source"><i class="fa-solid fa-check" aria-hidden="true"></i>Catálogo público de Horus</span>
                            </div>
                        </section>
                    </template>
                </div>
                <template x-for="link in (message.links || [])" :key="link.url">
                    <a
                        class="hc-action"
                        :class="link.whatsapp ? 'hc-action-whatsapp' : ''"
                        :href="link.url"
                        :target="link.whatsapp ? '_blank' : null"
                        :rel="link.whatsapp ? 'noopener noreferrer' : null"
                    >
                        <i x-show="link.whatsapp" class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                        <span x-text="link.label"></span>
                    </a>
                </template>
            </article>
        </template>

        <div x-show="messages.length === 1 && messages[0].role === 'bot'" class="hc-suggestions" aria-label="Preguntas sugeridas">
            <p>Puedes empezar por aquí</p>
            <div>
                <button type="button" @click="draft = '¿Qué capacitaciones ofrecen?'; $nextTick(() => $refs.question.focus())"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><span>Explorar capacitaciones</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                <button type="button" @click="draft = '¿Qué servicios tecnológicos brindan?'; $nextTick(() => $refs.question.focus())"><i class="fa-solid fa-laptop-code" aria-hidden="true"></i><span>Servicios tecnológicos</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                <button type="button" @click="draft = '¿Cómo puedo solicitar una cotización?'; $nextTick(() => $refs.question.focus())"><i class="fa-regular fa-comment-dots" aria-hidden="true"></i><span>Solicitar una cotización</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                <button type="button" @click="draft = '¿Cómo puedo matricularme?'; $nextTick(() => $refs.question.focus())"><i class="fa-solid fa-book-open" aria-hidden="true"></i><span>Consultar matrícula</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </div>
        </div>

        <div x-show="sending" class="hc-thinking" role="status">
            <span class="hc-thinking-dots" aria-hidden="true"><i></i><i></i><i></i></span>
            <span>Consultando información publicada…</span>
        </div>
    </div>

    <div class="hc-compose">
        <div class="hc-compose-heading">
            <label for="mensaje-chat">Escribe tu consulta</label>
            <span><span x-text="draft.length">0</span> / 2000</span>
        </div>
        <form @submit.prevent="sendMessage">
            <label for="mensaje-chat" class="sr-only">Escribe tu mensaje</label>
            <textarea
                id="mensaje-chat"
                x-ref="question"
                x-model="draft"
                maxlength="2000"
                rows="1"
                autocomplete="off"
                placeholder="¿Qué te gustaría saber?"
                :disabled="sending"
                @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); if (draft.trim() && !sending) sendMessage(); }"
                aria-describedby="hc-question-help"
            ></textarea>
            <button type="submit" :disabled="sending || !draft.trim()" aria-label="Enviar consulta">
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
            </button>
        </form>
        <p class="hc-compose-help" id="hc-question-help">Enter para enviar <span>· Shift + Enter para otra línea</span></p>
        <p class="hc-privacy-hint"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>No compartas datos personales en el chat.</p>
    </div>
</div>
@endif
