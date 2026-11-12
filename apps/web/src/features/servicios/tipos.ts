/** Contratos de `/api/panel/v1/servicios/*` (ver packages/contracts/openapi/horus-api.yaml). */

export interface ResumenServicios {
  indicadores: {
    servicios_activos?: number;
    servicios_total?: number;
    servicios_publicados?: number;
    /** Solo rol admin con cotizaciones.ver. */
    cotizaciones_recibidas?: number;
    cotizaciones_en_revision?: number;
    seguimientos_pendientes?: number;
  };
  bloques: {
    ultimas_cotizaciones?: CotizacionResumen[];
  };
}

export interface ServicioResumen {
  id: number;
  codigo: string;
  nombre: string;
  categoria: string | null;
  estado: string;
  publicacion: { estado: string; vigente_desde: string | null; vigente_hasta: string | null } | null;
  actualizado_en: string | null;
}

export interface CotizacionResumen {
  identificador: string;
  codigo: string;
  servicio: string | null;
  estado: string;
  responsable?: string | null;
  recibida_en: string | null;
  proximo_seguimiento: string | null;
}
