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
  servicio_uuid?: string | null;
  origen?: 'publico' | 'administrativo';
  estado: string;
  responsable?: string | null;
  recibida_en: string | null;
  proximo_seguimiento: string | null;
}

export interface ElementoFicha {
  id: number;
  titulo: string;
  descripcion: string;
  caracteristicas: string[];
  variante_imagen: string | null;
  imagen_alt: string | null;
  activo: boolean;
  orden: number;
  con_imagen: boolean;
}

export interface FichaServicio {
  estado: string;
  titulo: string;
  resumen: string;
  descripcion: string;
  categoria: string | null;
  vigente_desde: string;
  vigente_hasta: string;
  titulo_tarjetas: string | null;
  imagen_principal_alt: string | null;
  con_imagen: boolean;
  tarjetas: number;
  elementos: ElementoFicha[];
  autorizado_en: string | null;
}

/** `servicios/servicios/{id}`: la revisión (ETag) debe devolverse en cada escritura (`_revision`). */
export interface ServicioDetalle {
  id: number;
  codigo: string;
  nombre: string;
  categoria: string | null;
  estado: 'activo' | 'inactivo' | 'archivado';
  identificador_publico: string;
  revision: string;
  estado_editorial: 'sin_ficha' | 'borrador' | 'publicada' | 'retirada' | 'caducada' | 'programada';
  actualizado_en: string | null;
  ficha: FichaServicio | null;
}

export interface EventoCotizacion {
  revision: number;
  evento: string;
  fecha: string | null;
  actor: string | null;
  nota: string | null;
  estado_anterior: string | null;
  estado_nuevo: string | null;
}

export interface CotizacionDetalle {
  identificador: string;
  codigo: string;
  origen: 'publico' | 'administrativo';
  estado: 'recibida' | 'en_revision';
  recibida_en: string | null;
  servicio: string | null;
  servicio_uuid: string | null;
  contacto: { nombre: string; email: string | null; telefono: string | null };
  necesidad: string;
  responsable: { id: number; nombre: string } | null;
  ultimo_seguimiento: string | null;
  proximo_seguimiento: string | null;
  revision: string;
  historial: EventoCotizacion[];
}

export interface ClienteResumen {
  identificador: string;
  tipo: 'persona' | 'empresa';
  nombre: string;
  documento_tipo: 'dni' | 'ruc' | 'ce' | 'otro' | null;
  /** Documento enmascarado: solo los últimos 3 caracteres. */
  documento: string | null;
  /** Correo enmascarado. */
  email: string | null;
  estado: 'activo' | 'inactivo';
  responsable: string | null;
}

export interface ContactoCliente {
  nombre: string;
  cargo: string | null;
  email: string | null;
  telefono: string | null;
  principal: boolean;
}

export interface ClienteDetalle {
  identificador: string;
  tipo: 'persona' | 'empresa';
  nombre: string;
  documento_tipo: 'dni' | 'ruc' | 'ce' | 'otro' | null;
  documento_numero: string | null;
  email: string | null;
  telefono: string | null;
  direccion: string | null;
  estado: 'activo' | 'inactivo';
  responsable: { id: number; nombre: string } | null;
  contactos: ContactoCliente[];
  creado_en: string | null;
  revision: string;
}
