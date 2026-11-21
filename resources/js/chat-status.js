// Solo estados públicos de Laravel. El navegador nunca consulta Python ni recibe credenciales.
export function chatStatus() {
  return {
    endpointEstado: '',
    estadoServicio: 'comprobando',
    _estadoAbierto: false,
    _estadoGeneracion: 0,
    _estadoTimer: null,
    _estadoController: null,
    _estadoVisibilidad: null,
    puedeEnviar() {
      return !this.endpointEstado || this.estadoServicio === 'listo';
    },
    textoEstado() {
      return ({
        comprobando: 'Comprobando disponibilidad…',
        cargando: 'Cargando el asistente…',
        listo: 'Listo para conversar',
        no_disponible: 'Asistente no disponible. Inténtalo de nuevo en un momento.',
      })[this.estadoServicio] || 'Comprobando disponibilidad…';
    },
    iniciarEstado() {
      this.endpointEstado = this.$el.dataset.estadoEndpoint === '/chat/estado' ? '/chat/estado' : '';
      if (!this.endpointEstado) return;
      this._estadoVisibilidad = () => this.cambiarVisibilidadEstado(this._estadoAbierto);
      document.addEventListener('visibilitychange', this._estadoVisibilidad);
      this.$watch('abierto', (abierto) => this.cambiarVisibilidadEstado(abierto));
      this.cambiarVisibilidadEstado(Boolean(this.abierto));
    },
    cancelarEstado() {
      this._estadoGeneracion++;
      clearTimeout(this._estadoTimer);
      this._estadoTimer = null;
      this._estadoController?.abort();
      this._estadoController = null;
    },
    cambiarVisibilidadEstado(abierto) {
      this.cancelarEstado();
      this._estadoAbierto = Boolean(abierto);
      this.estadoServicio = 'comprobando';
      if (this._estadoAbierto && !document.hidden) void this.comprobarEstado();
    },
    reintentarEstado() {
      this.cambiarVisibilidadEstado(this._estadoAbierto);
    },
    async comprobarEstado() {
      if (!this.endpointEstado || !this._estadoAbierto || document.hidden) return;
      const generacion = this._estadoGeneracion;
      const controller = new AbortController();
      this._estadoController = controller;
      const timeout = setTimeout(() => controller.abort(), 4000);
      let siguiente = 5000;
      try {
        const response = await fetch(this.endpointEstado, {
          method: 'GET', headers: { Accept: 'application/json' }, cache: 'no-store',
          credentials: 'same-origin', redirect: 'error', signal: controller.signal,
        });
        if (response.status === 429) siguiente = 30000;
        if (!response.ok) throw new Error('Estado no disponible');
        const data = await response.json();
        if (!['cargando', 'listo', 'no_disponible'].includes(data?.estado)) throw new Error('Estado inválido');
        if (generacion === this._estadoGeneracion) this.estadoServicio = data.estado;
      } catch {
        if (generacion === this._estadoGeneracion) this.estadoServicio = 'no_disponible';
      } finally {
        clearTimeout(timeout);
        if (generacion === this._estadoGeneracion && this._estadoAbierto && !document.hidden) {
          this._estadoController = null;
          this._estadoTimer = setTimeout(
            () => { this._estadoTimer = null; void this.comprobarEstado(); },
            this.estadoServicio === 'listo' ? 15000 : siguiente,
          );
        }
      }
    },
    destruirEstado() {
      this.cancelarEstado();
      if (this._estadoVisibilidad) document.removeEventListener('visibilitychange', this._estadoVisibilidad);
    },
  };
}
