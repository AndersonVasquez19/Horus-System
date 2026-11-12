// Avisos (toasts) del panel. Los de éxito se cierran solos tras un tiempo que se pausa con el puntero o el foco;
// los de error permanecen hasta que se cierran. La región viva nace vacía y recibe el texto en el siguiente
// ciclo, para que los lectores de pantalla lo anuncien.

export const DURACION_EXITO_MS = 6000;

export function duracionAviso(tipo) {
  return tipo === 'success' ? DURACION_EXITO_MS : null;
}

export function avisoPanel(dependencias = {}) {
  const programar = dependencias.setTimeout || ((...args) => globalThis.setTimeout(...args));
  const cancelar = dependencias.clearTimeout || ((id) => globalThis.clearTimeout(id));
  const ahora = dependencias.now || (() => Date.now());

  return {
    visible: true,
    mensaje: '',
    restante: null,
    temporizador: null,
    inicio: 0,

    init() {
      const texto = this.$el.dataset.mensaje || '';
      this.restante = duracionAviso(this.$el.dataset.tipo);
      this.$nextTick(() => {
        this.mensaje = texto;
        this.reanudar();
      });
    },

    reanudar() {
      if (this.restante === null || this.temporizador !== null || !this.visible) return;
      this.inicio = ahora();
      this.temporizador = programar(() => this.cerrar(), this.restante);
    },

    pausar() {
      if (this.temporizador === null) return;
      cancelar(this.temporizador);
      this.temporizador = null;
      this.restante = Math.max(0, this.restante - (ahora() - this.inicio));
    },

    cerrar() {
      if (this.temporizador !== null) cancelar(this.temporizador);
      this.temporizador = null;
      this.visible = false;
    },
  };
}
