// Campana de notificaciones del panel: contador, desplegable con las últimas no leídas (se cargan solo al
// abrir) y «Marcar todas». Sin JavaScript el botón es un enlace normal a la bandeja.
// El texto se pinta siempre con x-text; los vínculos llegan ya filtrados por el servidor (solo rutas internas).

export function textoContador(pendientes) {
  const n = Math.max(0, Number(pendientes) || 0);
  return n > 99 ? '99+' : String(n);
}

export function etiquetaCampana(pendientes) {
  const n = Math.max(0, Number(pendientes) || 0);
  if (n === 0) return 'Notificaciones, ninguna sin leer';
  return n === 1 ? 'Notificaciones, 1 sin leer' : `Notificaciones, ${n} sin leer`;
}

function tokenCsrf(documento) {
  return documento?.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export function campanaNotificaciones(dependencias = {}) {
  const solicitar = dependencias.fetch || ((...args) => globalThis.fetch(...args));
  const documento = dependencias.document || globalThis.document;

  return {
    abierto: false,
    cargando: false,
    ocupado: false,
    error: '',
    anuncio: '',
    pendientes: 0,
    items: [],
    cargado: false,

    init() {
      this.pendientes = Number(this.$el.dataset.pendientes) || 0;
    },

    get contador() {
      return textoContador(this.pendientes);
    },

    get etiqueta() {
      return etiquetaCampana(this.pendientes);
    },

    alternar() {
      if (this.abierto) {
        this.cerrar(true);
        return Promise.resolve();
      }
      return this.abrir();
    },

    async abrir() {
      this.abierto = true;
      await this.cargar();
    },

    cerrar(devolverFoco = false) {
      if (!this.abierto) return;
      this.abierto = false;
      if (devolverFoco) this.$refs?.boton?.focus?.();
    },

    async cargar() {
      if (this.cargando) return;
      this.cargando = true;
      this.error = '';
      try {
        const respuesta = await solicitar(this.$el.dataset.urlRecientes, {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!respuesta.ok) throw new Error(String(respuesta.status));
        const datos = await respuesta.json();
        this.items = Array.isArray(datos.notificaciones) ? datos.notificaciones : [];
        this.pendientes = Number(datos.pendientes) || 0;
        this.cargado = true;
      } catch {
        this.error = 'No se pudieron cargar las notificaciones. Inténtalo de nuevo.';
      } finally {
        this.cargando = false;
      }
    },

    async marcarTodas() {
      if (this.ocupado) return;
      this.ocupado = true;
      this.error = '';
      try {
        const respuesta = await solicitar(this.$el.dataset.urlLeidas, {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': tokenCsrf(documento),
          },
        });
        if (!respuesta.ok) throw new Error(String(respuesta.status));
        this.items = [];
        this.pendientes = 0;
        this.anuncio = 'Todas las notificaciones se marcaron como leídas.';
      } catch {
        this.error = 'No se pudieron marcar las notificaciones. Inténtalo de nuevo.';
      } finally {
        this.ocupado = false;
      }
    },
  };
}
