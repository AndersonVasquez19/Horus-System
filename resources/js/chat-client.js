const chatPaths = [
  '/educacion/cursos', '/educacion/capacitaciones', '/contactos', '/preguntas-frecuentes',
  '/tecnologias/camaras-seguridad', '/tecnologias/cableado-estructurado',
  '/tecnologias/soporte-mantenimiento', '/politicas-devolucion', '/quienes-somos',
];
export function safeChatLinks(text) {
  return chatPaths.filter((path) => text.includes(path)).map((url) => ({
    url, label: ({ '/contactos': 'Contactar con Horus', '/educacion/cursos': 'Ver cursos',
      '/educacion/capacitaciones': 'Ver capacitaciones', '/preguntas-frecuentes': 'Preguntas frecuentes' })[url]
      || 'Ver información del servicio',
  }));
}

export function chatClient() {
  return {
    endpoint: '',
    csrfToken: '',
    sessionId: '',
    messages: [
      {
        role: 'bot',
        text: '¡Hola! Soy Horus, tu asistente virtual. ¿En qué te puedo ayudar hoy?',
      },
    ],
    draft: '',
    sending: false,
    followResponse: true,
    init() {
      this.endpoint = this.$el.dataset.endpoint;
      this.csrfToken = this.$el.dataset.csrfToken;
      const sessionKey = `horus-chat-session:${this.endpoint}`;
      try { this.sessionId = sessionStorage.getItem(sessionKey); } catch { /* Storage may be disabled. */ }

      if (!this.sessionId) {
        this.sessionId = globalThis.crypto?.randomUUID?.()
          || `chat-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        try { sessionStorage.setItem(sessionKey, this.sessionId); } catch { /* Keep the in-memory ID. */ }
      }
    },
    trackScroll() {
      const log = this.$refs.messages;
      if (this.sending && log.scrollTop + log.clientHeight < log.scrollHeight - 60) this.followResponse = false;
    },
    async sendMessage() {
      const message = this.draft.trim();
      if (!message || this.sending) {
        return;
      }

      this.followResponse = true;
      this.messages = this.messages.slice(-39);
      this.messages.push({ role: 'user', text: message });
      this.draft = '';
      this.sending = true;
      const startedAt = Date.now();
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 18000);

      try {
        const response = await fetch(this.endpoint, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': this.csrfToken,
          },
          body: JSON.stringify({
            mensaje: message,
            session_id: this.sessionId,
          }),
          signal: controller.signal,
        });

        if (!response.ok) {
          const error = new Error('request_failed');
          error.status = response.status;
          throw error;
        }

        const data = await response.json();
        if (typeof data.respuesta !== 'string' || data.respuesta.length === 0) {
          throw new Error('El servidor devolvió una respuesta inválida.');
        }

        this.messages.push({ role: 'bot', text: data.respuesta, links: safeChatLinks(data.respuesta) });
      } catch (error) {
        if (!this.draft.trim()) {
          this.draft = message;
        }
        this.messages.push({
          role: 'bot',
          text: error.status === 429
            ? 'Has enviado varias consultas. Espera un minuto antes de volver a intentarlo.'
            : error.status === 419
              ? 'La sesión venció. Recarga la página para continuar. Conserva tu consulta antes de recargar.'
              : 'No pude completar la consulta. Tu mensaje sigue en el campo de texto; puedes volver a enviarlo cuando lo decidas.',
        });
      } finally {
        clearTimeout(timeout);
        const espera = Math.max(0, 500 - (Date.now() - startedAt));
        if (espera > 0) {
          await new Promise((resolve) => setTimeout(resolve, espera));
        }
        this.sending = false;
        this.$nextTick(() => {
          const log = this.$refs.messages;
          const last = log.querySelector('article:last-of-type');
          if (this.followResponse) {
            if (last) log.scrollTop += last.getBoundingClientRect().top - log.getBoundingClientRect().top;
            else log.scrollTop = log.scrollHeight;
          }
          if (document.activeElement === document.body && this.$el.offsetParent !== null) this.$refs.question?.focus();
        });
      }
    },
  };
}
