// Estructura del panel: menú lateral en móvil. Cerrado en móvil queda `inert` (fuera del orden de tabulación y
// del árbol de accesibilidad); en escritorio siempre está visible. Al abrirlo el foco pasa al primer enlace y al
// cerrarlo vuelve al botón que lo abrió.

export const CONSULTA_ESCRITORIO = '(min-width: 768px)';

export function debeEstarInerte(abierto, escritorio) {
  return !abierto && !escritorio;
}

export function adminShell(dependencias = {}) {
  const coincidir = dependencias.matchMedia || ((consulta) => globalThis.matchMedia?.(consulta));

  return {
    sidebarOpen: false,
    esEscritorio: true,

    init() {
      const consulta = coincidir(CONSULTA_ESCRITORIO);
      this.esEscritorio = consulta ? consulta.matches : true;
      consulta?.addEventListener?.('change', (evento) => {
        this.esEscritorio = evento.matches;
        if (evento.matches) this.sidebarOpen = false;
      });
    },

    get menuInerte() {
      return debeEstarInerte(this.sidebarOpen, this.esEscritorio);
    },

    abrirMenu() {
      this.sidebarOpen = true;
      this.$nextTick(() => this.$refs.sidebar?.querySelector('a[href], button:not([disabled])')?.focus());
    },

    cerrarMenu() {
      if (!this.sidebarOpen) return;
      this.sidebarOpen = false;
      if (!this.esEscritorio) this.$nextTick(() => this.$refs.botonMenu?.focus());
    },

    alternarMenu() {
      if (this.sidebarOpen) this.cerrarMenu();
      else this.abrirMenu();
    },
  };
}
