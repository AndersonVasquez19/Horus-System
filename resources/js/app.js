import Alpine from 'alpinejs';
import '@fortawesome/fontawesome-free/css/all.min.css';
import '@fontsource/poppins/latin-300.css';
import '@fontsource/poppins/latin-400.css';
import '@fontsource/poppins/latin-600.css';
import '@fontsource/poppins/latin-700.css';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
  Alpine.data('carousel', () => ({
    current: 0,
    total: 5,
    init() {
      setInterval(() => {
        this.current = (this.current + 1) % this.total;
      }, 5000);
    },
    next() {
      this.current = (this.current + 1) % this.total;
    },
    prev() {
      this.current = (this.current - 1 + this.total) % this.total;
    },
  }));

  Alpine.data('convenios', (items = []) => ({
    active: null,
    items,
    open(id) {
      this.active = this.items.find((item) => item.id === id) || null;
    },
    close() {
      this.active = null;
    },
  }));

  Alpine.data('contactForm', () => ({
    send(event) {
      const datos = new FormData(event.currentTarget);
      const valor = (campo) => String(datos.get(campo) || '').trim();
      const asunto = event.currentTarget.elements.namedItem('asunto');
      const cuerpo = [
        `Nombre Completo: ${valor('nombre')}`,
        `Correo Electrónico: ${valor('email')}`,
        `Teléfono: ${valor('telefono')}`,
        `Asunto: ${asunto.options[asunto.selectedIndex].text}`,
        '',
        valor('mensaje'),
      ].join('\n');
      const consulta = new URLSearchParams({
        subject: `Consulta: ${asunto.options[asunto.selectedIndex].text}`,
        body: cuerpo,
      }).toString().replace(/\+/g, '%20');

      window.location.href = `mailto:horusgroupcajamarca@gmail.com?${consulta}`;
    },
  }));

  Alpine.data('nav', () => ({
    open: false,
    subOpen: null,
    toggleMenu() {
      this.open = !this.open;
    },
    toggleSub(key) {
      this.subOpen = this.subOpen === key ? null : key;
    },
  }));

  Alpine.data('chatClient', () => ({
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
    init() {
      this.endpoint = this.$el.dataset.endpoint;
      this.csrfToken = this.$el.dataset.csrfToken;
      const sessionKey = `horus-chat-session:${this.endpoint}`;
      this.sessionId = sessionStorage.getItem(sessionKey);

      if (!this.sessionId) {
        this.sessionId = globalThis.crypto?.randomUUID?.()
          || `chat-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        sessionStorage.setItem(sessionKey, this.sessionId);
      }
    },
    async sendMessage() {
      const message = this.draft.trim();
      if (!message || this.sending) {
        return;
      }

      this.messages.push({ role: 'user', text: message });
      this.draft = '';
      this.sending = true;
      const startedAt = Date.now();
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 25000);

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
          throw new Error(`El servidor respondió con el estado ${response.status}.`);
        }

        const data = await response.json();
        if (typeof data.respuesta !== 'string' || data.respuesta.length === 0) {
          throw new Error('El servidor devolvió una respuesta inválida.');
        }

        this.messages.push({ role: 'bot', text: data.respuesta });
      } catch (error) {
        console.error('No se pudo completar la consulta al asistente.', error);
        if (!this.draft.trim()) {
          this.draft = message;
        }
        this.messages.push({
          role: 'bot',
          text: 'No pude conectar con el asistente. Tu mensaje sigue en el campo de texto para que puedas intentarlo de nuevo.',
        });
      } finally {
        clearTimeout(timeout);
        const espera = Math.max(0, 500 - (Date.now() - startedAt));
        if (espera > 0) {
          await new Promise((resolve) => setTimeout(resolve, espera));
        }
        this.sending = false;
        this.$nextTick(() => {
          this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight;
        });
      }
    },
  }));
});

Alpine.start();
