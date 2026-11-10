// Selector con búsqueda en el servidor (combobox accesible) para estudiante y curso del panel de matrículas.
// Sustituye a los <select> que cargaban todos los registros en la página. Cada consulta devuelve como máximo 20
// opciones; la validación de escritura sigue siendo la del servidor.
// El texto se pinta siempre con x-text, nunca con x-html. El valor viaja en un <input type="hidden"> con el
// nombre original del campo, así que el contrato del formulario no cambia.

export const RETARDO_MS = 250;

export function selectorAsincrono(dependencias = {}) {
  const solicitar = dependencias.fetch || ((...args) => globalThis.fetch(...args));
  const programar = dependencias.setTimeout || ((...args) => globalThis.setTimeout(...args));
  const cancelar = dependencias.clearTimeout || ((...args) => globalThis.clearTimeout(...args));
  const ControladorAborto = dependencias.AbortController || globalThis.AbortController;
  const documento = dependencias.document || globalThis.document;

  return {
    abierto: false,
    cargando: false,
    cargado: false,
    error: '',
    anuncio: '',
    opciones: [],
    activo: -1,
    valor: '',
    texto: '',
    textoElegido: '',
    temporizador: null,
    controlador: null,
    secuencia: 0,
    // Elemento raíz del componente: dentro de un manejador (@focus, @input…) `$el` es el elemento que lo dispara.
    raiz: null,

    init() {
      this.raiz = this.$el;
      this.valor = this.raiz.dataset.valor || '';
      this.texto = this.raiz.dataset.texto || '';
      this.textoElegido = this.texto;
    },

    get idLista() {
      return `${this.raiz.dataset.id}-lista`;
    },

    idOpcion(indice) {
      return `${this.raiz.dataset.id}-opcion-${indice}`;
    },

    get idActivo() {
      return this.activo >= 0 && this.activo < this.opciones.length ? this.idOpcion(this.activo) : '';
    },

    get sinResultados() {
      return this.cargado && !this.cargando && !this.error && this.opciones.length === 0;
    },

    /** El usuario teclea: se espera una pausa antes de consultar. El valor elegido no cambia hasta elegir otra opción. */
    escribir() {
      this.abierto = true;
      this.cancelarPendiente();
      this.temporizador = programar(() => {
        this.temporizador = null;
        this.buscar(this.texto);
      }, RETARDO_MS);
    },

    async abrir() {
      if (this.abierto && this.cargado) return;
      this.abierto = true;
      if (!this.cargado) await this.buscar(this.texto === this.textoElegido ? '' : this.texto);
    },

    cancelarPendiente() {
      if (this.temporizador !== null) {
        cancelar(this.temporizador);
        this.temporizador = null;
      }
      if (this.controlador) {
        this.controlador.abort();
        this.controlador = null;
      }
      // Invalida cualquier respuesta que siga en vuelo.
      this.secuencia += 1;
      this.cargando = false;
    },

    async buscar(texto) {
      this.cancelarPendiente();
      const turno = this.secuencia;
      const controlador = ControladorAborto ? new ControladorAborto() : null;
      this.controlador = controlador;
      this.cargando = true;
      this.error = '';

      try {
        const parametros = new URLSearchParams({ q: String(texto || '').trim(), contexto: this.raiz.dataset.contexto || '' });
        const respuesta = await solicitar(`${this.raiz.dataset.url}?${parametros.toString()}`, {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          signal: controlador?.signal,
        });
        if (turno !== this.secuencia) return;
        if (!respuesta.ok) throw new Error(String(respuesta.status));
        const datos = await respuesta.json();
        if (turno !== this.secuencia) return;

        this.opciones = Array.isArray(datos.opciones) ? datos.opciones : [];
        this.activo = -1;
        this.cargado = true;
        this.anuncio = this.opciones.length === 0
          ? 'Sin resultados.'
          : (this.opciones.length === 1 ? '1 resultado disponible.' : `${this.opciones.length} resultados disponibles.`);
      } catch (fallo) {
        if (fallo?.name === 'AbortError' || turno !== this.secuencia) return;
        this.opciones = [];
        this.error = 'No se pudieron cargar las opciones. Inténtalo de nuevo.';
        this.anuncio = this.error;
      } finally {
        if (turno === this.secuencia) {
          this.cargando = false;
          this.controlador = null;
        }
      }
    },

    elegir(opcion) {
      if (!opcion) return;
      this.valor = String(opcion.id);
      this.texto = String(opcion.texto);
      this.textoElegido = this.texto;
      this.anuncio = `Seleccionado: ${this.texto}`;
      this.cerrar();
    },

    cerrar() {
      this.cancelarPendiente();
      this.abierto = false;
      this.activo = -1;
      this.restaurar();
    },

    /** Al salir, un texto vacío limpia la selección; un texto escrito sin elegir vuelve a la última opción elegida. */
    restaurar() {
      if (this.texto.trim() === '') {
        this.valor = '';
        this.textoElegido = '';
        this.texto = '';
      } else {
        this.texto = this.textoElegido;
      }
    },

    salir(evento) {
      if (evento?.relatedTarget && this.raiz.contains(evento.relatedTarget)) return;
      this.cerrar();
    },

    mover(indice) {
      if (this.opciones.length === 0) return;
      this.activo = Math.max(0, Math.min(this.opciones.length - 1, indice));
      this.$nextTick?.(() => documento?.getElementById?.(this.idActivo)?.scrollIntoView?.({ block: 'nearest' }));
    },

    teclado(evento) {
      switch (evento.key) {
        case 'ArrowDown':
          evento.preventDefault();
          if (!this.abierto) this.abrir();
          else this.mover(this.activo + 1);
          break;
        case 'ArrowUp':
          if (!this.abierto) return;
          evento.preventDefault();
          this.mover(this.activo <= 0 ? 0 : this.activo - 1);
          break;
        case 'Home':
          if (!this.abierto || this.opciones.length === 0) return;
          evento.preventDefault();
          this.mover(0);
          break;
        case 'End':
          if (!this.abierto || this.opciones.length === 0) return;
          evento.preventDefault();
          this.mover(this.opciones.length - 1);
          break;
        case 'Enter':
          // Con la lista abierta, Enter elige y no envía el formulario.
          if (!this.abierto) return;
          evento.preventDefault();
          if (this.activo >= 0) this.elegir(this.opciones[this.activo]);
          break;
        case 'Escape':
          // Cierra solo la lista; el diálogo del formulario no debe cerrarse con este Escape.
          if (!this.abierto) return;
          evento.preventDefault();
          evento.stopPropagation();
          this.cerrar();
          break;
        case 'Tab':
          this.cerrar();
          break;
        default:
      }
    },
  };
}
