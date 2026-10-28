import { chatClient } from './chat-client.js';
import Alpine from 'alpinejs';
import '@fortawesome/fontawesome-free/css/all.min.css';
import '@fontsource/poppins/latin-300.css';
import '@fontsource/poppins/latin-400.css';
import '@fontsource/poppins/latin-600.css';
import '@fontsource/poppins/latin-700.css';
import '../css/chatbot.css';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
  Alpine.data('carousel', (total = 1, interval = 6000) => ({
    current: 0,
    total,
    paused: false,
    init() {
      if (total < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      setInterval(() => {
        if (!this.paused && !document.hidden) this.next();
      }, interval);
    },
    next() {
      this.current = (this.current + 1) % this.total;
    },
    prev() {
      this.current = (this.current - 1 + this.total) % this.total;
    },
    go(index) {
      this.current = index;
    },
    pause() {
      this.paused = true;
    },
    resume() {
      this.paused = false;
    },
  }));

  Alpine.data('convenios', (items = []) => ({
    active: null,
    items,
    open(id) {
      this.active = this.items.find((item) => item.id === id) || null;
      if (this.active && window.matchMedia('(max-width: 1023px)').matches) {
        this.$nextTick(() => this.$refs.panel?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
      }
    },
    toggle(id) {
      if (this.active && this.active.id === id) {
        this.close();
        return;
      }
      this.open(id);
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

  Alpine.data('chatClient', chatClient);
});

Alpine.start();
