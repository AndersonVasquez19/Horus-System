@props(['endpoint', 'public' => false])

@if (! $public)
<div
    x-data="chatClient"
    data-endpoint="{{ $endpoint }}"
    data-csrf-token="{{ csrf_token() }}"
    class="flex h-full min-h-0 flex-col overflow-hidden"
>
    <div x-ref="messages" @scroll.passive="trackScroll" class="flex-1 space-y-4 overflow-y-auto bg-[#F7F8F6] p-4 sm:p-5" aria-live="polite">
        <template x-for="(message, index) in messages" :key="index">
            <div class="flex items-end gap-2" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
                <span x-show="message.role === 'bot'" class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-[#DCC98F] bg-[#F2E8CA]" aria-hidden="true">
                    <img src="{{ asset('assets/images/index/stiker/stiker.png') }}" alt="" width="32" height="32" class="h-full w-full object-contain" draggable="false">
                </span>
                <p
                    x-text="message.text"
                    :class="message.role === 'user' ? 'rounded-2xl rounded-br-md bg-[#0A2540] text-white' : 'rounded-2xl rounded-bl-md border border-[#DFE7E7] bg-white text-[#193247]'"
                    class="max-w-[85%] whitespace-pre-line px-4 py-3 text-sm leading-6 shadow-[0_3px_12px_rgba(10,37,64,0.06)]"
                ></p>
            </div>
        </template>
        <div x-show="sending" class="flex items-end gap-2" aria-label="El asistente está escribiendo">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-[#DCC98F] bg-[#F2E8CA]" aria-hidden="true">
                <img src="{{ asset('assets/images/index/stiker/stiker.png') }}" alt="" width="32" height="32" class="h-full w-full object-contain" draggable="false">
            </span>
            <div class="rounded-2xl rounded-bl-md border border-[#DFE7E7] bg-white px-4 py-3 text-sm text-[#486075] shadow-sm">
                <span class="mr-2 inline-flex h-2 w-2 animate-pulse rounded-full bg-[#B8956A]"></span>
                Escribiendo...
            </div>
        </div>
    </div>

    <form @submit.prevent="sendMessage" class="flex items-center gap-2 border-t border-[#E5EBEF] bg-white p-3 sm:p-4">
        <label for="mensaje-chat" class="sr-only">Escribe tu mensaje</label>
        <input
            id="mensaje-chat"
            x-ref="question"
            x-model="draft"
            type="text"
            maxlength="2000"
            autocomplete="off"
            placeholder="Escribe tu consulta..."
            class="min-w-0 flex-1 rounded-full border border-[#CFDCE0] bg-[#F8FAFA] px-4 py-3 text-sm text-[#0A2540] outline-none transition placeholder:text-[#64748B] focus:border-[#B8956A] focus:bg-white focus:ring-2 focus:ring-[#C9A961]/20"
        >
        <button
            type="submit"
            :disabled="sending || !draft.trim()"
            aria-label="Enviar mensaje"
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#C9A961] text-[#0A2540] shadow-sm transition hover:bg-[#E2C77F] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#B8956A] disabled:cursor-not-allowed disabled:opacity-50"
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
            <article class="hc-message" :class="message.role === 'user' ? 'hc-user' : 'hc-assistant'">
                <strong>
                    <x-public.robot-mascot x-show="message.role === 'bot'" :portrait="true" class="hc-message-avatar" />
                    <span x-text="message.role === 'user' ? 'Tú' : 'Horus'"></span>
                </strong>
                <p x-text="message.text"></p>
                <template x-for="link in (message.links || [])" :key="link.url">
                    <a class="hc-action" :href="link.url" x-text="link.label"></a>
                </template>
            </article>
        </template>

        <div x-show="messages.length === 1 && messages[0].role === 'bot'" class="hc-suggestions" aria-label="Preguntas sugeridas">
            <p>Puedes empezar por aquí</p>
            <div>
                <button type="button" @click="draft = '¿Qué capacitaciones ofrecen?'; $nextTick(() => $refs.question.focus())">¿Qué capacitaciones ofrecen?</button>
                <button type="button" @click="draft = '¿Qué servicios tecnológicos brindan?'; $nextTick(() => $refs.question.focus())">¿Qué servicios tecnológicos brindan?</button>
                <button type="button" @click="draft = '¿Cómo puedo solicitar una cotización?'; $nextTick(() => $refs.question.focus())">¿Cómo puedo solicitar una cotización?</button>
                <button type="button" @click="draft = '¿Cómo puedo matricularme?'; $nextTick(() => $refs.question.focus())">¿Cómo puedo matricularme?</button>
            </div>
        </div>

        <div x-show="sending" class="hc-thinking" role="status">
            <span class="hc-thinking-dots" aria-hidden="true"><i></i><i></i><i></i></span>
            Consultando información…
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
    </div>
</div>
@endif
