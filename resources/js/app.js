import { chatClient } from './chat-client.js';
import Alpine from 'alpinejs';
import { iniciarModales, sincronizarModal } from './admin-modals.mjs';
import { iniciarConfirmaciones } from './confirm-forms.mjs';
import '@fortawesome/fontawesome-free/css/all.min.css';
import '@fontsource/poppins/latin-300.css';
import '@fontsource/poppins/latin-400.css';
import '@fontsource/poppins/latin-500.css';
import '@fontsource/poppins/latin-600.css';
import '@fontsource/poppins/latin-700.css';
import '@fontsource/montserrat/latin-400.css';
import '@fontsource/montserrat/latin-500.css';
import '@fontsource/montserrat/latin-600.css';
import '@fontsource/montserrat/latin-700.css';
import '../css/chatbot.css';

window.Alpine = Alpine;
window.HorusModal = { sync: sincronizarModal };

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

  Alpine.data('conveniosCarousel', (total = 1, interval = 6000) => {
    const tick = 100;

    return {
      index: 0,
      pages: 1,
      visible: 1,
      step: 0,
      elapsed: 0,
      hovering: false,
      userPaused: false,
      inView: false,
      reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
      get autoplay() {
        return !this.reduced && this.pages > 1;
      },
      get running() {
        return this.autoplay && !this.userPaused && !this.hovering;
      },
      get range() {
        const last = Math.min(total, this.index + this.visible);
        const first = this.index + 1;
        return `${first === last ? last : `${first}–${last}`} de ${total}`;
      },
      init() {
        this.measure();
        if (this.reduced || !('IntersectionObserver' in window)) {
          this.inView = true;
        } else {
          this.$el.dataset.anim = '1';
          const observer = new IntersectionObserver(([entry]) => {
            if (!entry.isIntersecting) return;
            this.measure();
            this.inView = true;
            observer.disconnect();
          }, { threshold: 0.2 });
          observer.observe(this.$el);
        }
        setInterval(() => {
          if (!this.running || !this.inView || document.hidden) return;
          this.elapsed += tick;
          if (this.elapsed >= interval) this.next();
        }, tick);
      },
      measure() {
        const slides = this.$refs.track ? [...this.$refs.track.children] : [];
        if (!slides.length) return;
        const step = slides.length > 1 ? slides[1].offsetLeft - slides[0].offsetLeft : slides[0].offsetWidth;
        if (!step) return;
        this.step = step;
        this.visible = Math.max(1, Math.min(total, Math.round(this.$refs.track.clientWidth / step)));
        this.pages = Math.max(1, total - this.visible + 1);
        this.index = Math.min(this.index, this.pages - 1);
      },
      inWindow(i) {
        return this.pages === 1 || (i >= this.index && i < this.index + this.visible);
      },
      onScroll() {
        if (!this.step) this.measure();
        if (!this.step) return;
        this.index = Math.max(0, Math.min(this.pages - 1, Math.round(this.$refs.track.scrollLeft / this.step)));
      },
      go(i) {
        if (!this.step) this.measure();
        if (!this.step) return;
        this.index = Math.max(0, Math.min(this.pages - 1, i));
        this.elapsed = 0;
        this.$refs.track.scrollTo({ left: this.index * this.step, behavior: this.reduced ? 'auto' : 'smooth' });
      },
      next() {
        this.go(this.index >= this.pages - 1 ? 0 : this.index + 1);
      },
      prev() {
        this.go(this.index <= 0 ? this.pages - 1 : this.index - 1);
      },
      toggleAutoplay() {
        this.userPaused = !this.userPaused;
        this.elapsed = 0;
      },
    };
  });

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

iniciarConfirmaciones();

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciarModales, { once: true });
else iniciarModales();
