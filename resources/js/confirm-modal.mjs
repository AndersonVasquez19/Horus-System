// Confirmación de acciones destructivas del panel con <dialog> nativo: showModal() deja el fondo inerte y
// mantiene el foco dentro; Escape cierra. Al cerrar, el foco vuelve al control que abrió la confirmación.
// Se abre con el evento `open-confirm-modal` (detalle: title, message, action, method, confirmText).

export const VALORES_CONFIRMACION = Object.freeze({
  title: '¿Estás seguro?',
  message: '',
  action: '',
  method: 'DELETE',
  confirmText: 'Sí, continuar',
});

export function normalizarConfirmacion(detalle = {}) {
  const valores = {};
  for (const [clave, predeterminado] of Object.entries(VALORES_CONFIRMACION)) {
    const valor = detalle?.[clave];
    valores[clave] = typeof valor === 'string' && valor !== '' ? valor : predeterminado;
  }
  return valores;
}

export function confirmModal(dependencias = {}) {
  const documento = dependencias.document || globalThis.document;

  return {
    ...VALORES_CONFIRMACION,
    origen: null,

    abrir(detalle) {
      Object.assign(this, normalizarConfirmacion(detalle));
      this.origen = documento?.activeElement || null;
      const dialogo = this.$el;
      if (typeof dialogo.showModal === 'function') {
        if (!dialogo.open) dialogo.showModal();
      } else {
        dialogo.setAttribute('open', '');
      }
      this.$nextTick(() => this.$refs.cancelBtn?.focus());
    },

    cerrar() {
      const dialogo = this.$el;
      if (typeof dialogo.close === 'function') dialogo.close();
      else {
        dialogo.removeAttribute('open');
        this.alCerrar();
      }
    },

    alCerrar() {
      const origen = this.origen;
      this.origen = null;
      if (origen && typeof origen.focus === 'function' && origen.isConnected !== false) origen.focus();
    },

    clicFondo(evento) {
      if (evento.target === this.$el) this.cerrar();
    },
  };
}
