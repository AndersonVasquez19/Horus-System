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
  /** Solo se entrega a quien puede consultar clientes; es null si no hay vínculo o no hay permiso. */
  cliente: { identificador: string; nombre: string } | null;
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

export interface ProspectoResumen {
  identificador: string;
  nombre_contacto: string;
  empresa: string | null;
  /** Correo enmascarado. */
  email: string | null;
  origen: 'web' | 'cotizacion' | 'referido' | 'evento' | 'llamada' | 'otro';
  estado: 'nuevo' | 'contactado' | 'calificado' | 'descartado' | 'convertido';
  servicio_interes: string | null;
  responsable: string | null;
  proximo_seguimiento: string | null;
}

export interface ProspectoDetalle {
  identificador: string;
  nombre_contacto: string;
  empresa: string | null;
  email: string | null;
  telefono: string | null;
  origen: ProspectoResumen['origen'];
  estado: ProspectoResumen['estado'];
  motivo_descarte: string | null;
  servicio_interes: { identificador: string; nombre: string } | null;
  responsable: { id: number; nombre: string } | null;
  proximo_seguimiento: string | null;
  /** Solo se entrega a quien puede consultar clientes. */
  cliente: { identificador: string; nombre: string } | null;
  desde_cotizacion: boolean;
  creado_en: string | null;
  revision: string;
}

export type EstadoTicket = 'abierto' | 'en_progreso' | 'en_espera_cliente' | 'resuelto' | 'cerrado';
export type PrioridadTicket = 'baja' | 'media' | 'alta' | 'critica';
export type CanalTicket = 'telefono' | 'correo' | 'presencial' | 'web';

export interface TicketResumen {
  identificador: string;
  codigo: string;
  asunto: string;
  prioridad: PrioridadTicket;
  estado: EstadoTicket;
  canal: CanalTicket;
  /** Solo se entrega a quien puede consultar clientes. */
  cliente: { identificador: string; nombre: string } | null;
  asignado: string | null;
  vence_en: string;
  /** Pasó el límite del SLA y sigue sin resolverse. */
  vencido: boolean;
}

export interface NotaTicket {
  texto: string;
  interna: boolean;
  autor: string | null;
  fecha: string;
}

export interface TicketDetalle extends Omit<TicketResumen, 'asignado'> {
  descripcion: string | null;
  asignado: { id: number; nombre: string } | null;
  /** Solo a quien puede consultar proyectos. */
  proyecto: { identificador: string; codigo: string; nombre: string } | null;
  resuelto_en: string | null;
  cerrado_en: string | null;
  creado_en: string | null;
  notas: NotaTicket[];
  revision: string;
}

/** Estados a los que se puede pasar (espejo de `TicketSoporte::TRANSICIONES`; Laravel es la autoridad y limita la reapertura a 7 días). */
export const TRANSICIONES_TICKET: Record<EstadoTicket, readonly EstadoTicket[]> = {
  abierto: ['en_progreso'],
  en_progreso: ['en_espera_cliente', 'resuelto'],
  en_espera_cliente: ['en_progreso'],
  resuelto: ['en_progreso', 'cerrado'],
  cerrado: [],
};

export type EstadoProyecto = 'planificado' | 'en_curso' | 'pausado' | 'completado' | 'cancelado';

export interface AvanceProyecto {
  total: number;
  completados: number;
  /** Porcentaje calculado; `null` si aún no hay hitos. */
  avance: number | null;
}

export interface ProyectoResumen {
  identificador: string;
  codigo: string;
  nombre: string;
  estado: EstadoProyecto;
  /** Solo se entrega a quien puede consultar clientes. */
  cliente: { identificador: string; nombre: string } | null;
  responsable: string | null;
  fecha_inicio: string | null;
  fecha_fin_estimada: string | null;
  hitos: AvanceProyecto;
}

export interface HitoProyecto {
  identificador: string;
  nombre: string;
  orden: number;
  fecha_objetivo: string | null;
  completado_en: string | null;
}

export interface ProyectoDetalle extends Omit<ProyectoResumen, 'responsable'> {
  descripcion: string | null;
  /** Importe con dos decimales (cadena). */
  presupuesto: string | null;
  moneda: string;
  fecha_fin_real: string | null;
  responsable: { id: number; nombre: string } | null;
  /** Solo a quien puede consultar el pipeline. */
  oportunidad: { identificador: string; titulo: string } | null;
  desde_oportunidad: boolean;
  creado_en: string | null;
  hitos_detalle: HitoProyecto[];
  revision: string;
}

/** Estados a los que se puede pasar (espejo de `Proyecto::TRANSICIONES`; Laravel es la autoridad). */
export const TRANSICIONES_PROYECTO: Record<EstadoProyecto, readonly EstadoProyecto[]> = {
  planificado: ['en_curso', 'cancelado'],
  en_curso: ['completado', 'pausado', 'cancelado'],
  pausado: ['en_curso', 'cancelado'],
  completado: [],
  cancelado: [],
};

export type EtapaOportunidad = 'nueva' | 'calificada' | 'propuesta' | 'negociacion' | 'ganada' | 'perdida';

export interface OportunidadResumen {
  identificador: string;
  titulo: string;
  etapa: EtapaOportunidad;
  /** Importe con dos decimales (cadena). */
  monto_estimado: string;
  moneda: string;
  probabilidad: number;
  fecha_cierre_estimada: string | null;
  /** Solo se entrega a quien puede consultar clientes. */
  cliente: { identificador: string; nombre: string } | null;
  responsable: string | null;
  revision: string;
}

export interface ColumnaTablero {
  etapa: EtapaOportunidad;
  total: number;
  monto: string;
  tarjetas: OportunidadResumen[];
}

export interface OportunidadDetalle extends Omit<OportunidadResumen, 'responsable'> {
  motivo_perdida: string | null;
  servicio: { identificador: string; nombre: string } | null;
  /** Solo a quien puede consultar cotizaciones. */
  cotizacion: string | null;
  desde_prospecto: boolean;
  /** Solo a quien puede consultar proyectos. */
  proyecto: { identificador: string; codigo: string } | null;
  responsable: { id: number; nombre: string } | null;
  cerrada_en: string | null;
  creado_en: string | null;
  historial: { anterior: EtapaOportunidad | null; etapa: EtapaOportunidad; autor: string | null; fecha: string | null }[];
}

/** Etapas a las que se puede pasar con la tarjeta (espejo de `Oportunidad::TRANSICIONES`; Laravel es la autoridad). */
export const TRANSICIONES_OPORTUNIDAD: Record<EtapaOportunidad, readonly EtapaOportunidad[]> = {
  nueva: ['calificada', 'perdida'],
  calificada: ['propuesta', 'perdida'],
  propuesta: ['negociacion', 'perdida'],
  negociacion: ['ganada', 'perdida'],
  ganada: ['nueva'],
  perdida: ['nueva'],
};
