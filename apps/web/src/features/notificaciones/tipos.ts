/** Contratos de /api/panel/v1/compartido (notificaciones, perfil y sesiones de la cuenta propia). */

export interface Notificacion {
  id: number;
  titulo: string;
  mensaje: string | null;
  tipo: string | null;
  /** ISO 8601. */
  fecha: string | null;
  /** Fecha y hora en Lima, ya formateada por Laravel (`dd/mm/aaaa hh:mm`). */
  fecha_local: string | null;
  /** Ruta interna segura (o null: Laravel descarta enlaces externos). */
  vinculo: string | null;
  leida: boolean;
  /** Tipo en español («Reclamación», «Aviso»…) y texto del enlace, ya resueltos por Laravel. */
  tipo_etiqueta?: string;
  enlace_texto?: string;
}

export interface PreferenciasNotificacion {
  interno: boolean;
  email: boolean;
  whatsapp: boolean;
  whatsapp_consentimiento: boolean;
  recordatorio_cuotas: boolean;
  recordatorio_sesiones: boolean;
  recordatorio_reclamaciones: boolean;
  alerta_accesos: boolean;
}

export interface PerfilPropio {
  id: number;
  nombre: string;
  email: string;
  telefono?: string | null;
  roles: string[];
  preferencias_notificacion?: PreferenciasNotificacion;
  two_factor_habilitado?: boolean;
  debe_cambiar_clave?: boolean;
}

export interface SesionActiva {
  identificador: string;
  ip: string | null;
  navegador: string | null;
  ultima_actividad: string;
  actual: boolean;
}

export interface RegistroAuditoria {
  id: number;
  fecha: string;
  /** Fecha y hora en Lima (`dd/mm/aaaa hh:mm:ss`), ya formateada por Laravel. */
  fecha_local: string;
  usuario: { nombre: string; email: string } | null;
  accion: string;
  modelo: string | null;
  modelo_id: number | null;
  ip: string | null;
  /** Cambios ya enmascarados por Laravel (secretos y texto de búsqueda). No se muestran crudos: ver `resumen`. */
  cambios: Record<string, unknown> | null;
  /** Texto en español de la acción y de lo que se tocó, y frases con los cambios (sin claves internas ni JSON). */
  accion_etiqueta: string;
  modelo_etiqueta: string | null;
  resumen: string[];
}

export interface OpcionAuditoria {
  clave: string;
  etiqueta: string;
}

export interface UsuarioPanel {
  id: number;
  nombre: string;
  email: string;
  roles: string[];
  verificado: boolean;
  suspendido?: boolean;
  bloqueado_hasta?: string | null;
  motivo_bloqueo?: string | null;
  debe_cambiar_clave?: boolean;
  two_factor_habilitado?: boolean;
  eliminado?: boolean;
  /** ISO 8601. */
  creado_en: string | null;
}

export interface RolPanel {
  id: number;
  nombre: string;
  /** `principal`: el rol admin; `base`: no se renombra ni se elimina; `personalizado`: editable y eliminable. */
  tipo: 'principal' | 'base' | 'personalizado';
  permisos: string[];
  usuarios: number;
}

export type EstadoAprobacion = 'pendiente' | 'aprobado' | 'rechazado';

export interface EventoAprobacion {
  accion: string;
  usuario: string | null;
  fecha_local: string;
  estado_anterior: string | null;
  estado_nuevo: string | null;
  motivo: string | null;
  comentario: string | null;
}

/** Solicitud de aprobación interna. Las banderas `puede_*` son las mismas reglas que Laravel aplica al ejecutar. */
export interface AprobacionFila {
  id: number;
  titulo: string;
  tipo: string;
  estado: EstadoAprobacion;
  descripcion: string | null;
  comentario: string | null;
  referencia: string | null;
  /** `admin` (Gerencia), `coordinador` (Coordinación) o null si el tipo no tiene autoridad asignada. */
  rol_autorizado: string | null;
  solicitante: { id: number; nombre: string } | null;
  aprobador: { id: number; nombre: string } | null;
  creada_local: string;
  actualizada_local: string;
  es_propia: boolean;
  puede_editar?: boolean;
  puede_resolver: boolean;
  puede_reabrir: boolean;
  puede_eliminar: boolean;
  historial: EventoAprobacion[];
}

export interface ReclamacionFila {
  /** UUID público (el id interno no sale de Laravel). */
  id: string;
  numero: string;
  tipo: 'queja' | 'reclamo';
  estado: 'abierto' | 'respondido' | 'concluido_por_acuerdo';
  consumidor: string;
  recibida_local: string;
  /** AAAA-MM-DD. */
  fecha_limite: string;
  plazo_suspendido: boolean;
}

export interface ActuacionReclamacion {
  evento: string;
  etiqueta: string;
  fecha_local: string;
  actor: string;
  transicion: { de: string; a: string } | null;
  detalles: string[];
  nota: string | null;
}

export interface EnvioReclamacion {
  id: number;
  tipo: string;
  canal: string;
  /** Correo ya enmascarado por Laravel. */
  destinatario: string;
  fecha_local: string | null;
  fecha_es_de_envio: boolean;
  estado: string;
  estado_etiqueta: string;
  intentos: number;
  error_codigo: string | null;
  constancia: string | null;
  reintentable: boolean;
}

export interface ExpedienteReclamacion {
  id: string;
  numero: string;
  tipo: 'queja' | 'reclamo';
  estado: 'abierto' | 'respondido' | 'concluido_por_acuerdo';
  canal: string;
  numero_hoja_respaldo: string | null;
  canal_respuesta: string;
  recibida_local: string;
  fecha_limite: string;
  suspension_desde: string | null;
  suspension_hasta: string | null;
  respondida_local: string | null;
  respuesta: string | null;
  responsable: { id: number; nombre: string | null } | null;
  consumidor: {
    nombres: string;
    apellidos: string;
    tipo_documento: string;
    numero_documento: string;
    domicilio: string;
    telefono: string;
    email: string;
    es_menor: boolean;
    representante: { nombre: string | null; domicilio: string | null; telefono: string | null; email: string | null } | null;
  };
  bien: { tipo: string | null; descripcion: string | null; monto: string | null };
  detalle: string;
  pedido: string;
  historial: ActuacionReclamacion[];
  envios: EnvioReclamacion[];
}

export interface MetaExpediente {
  aviso_plazo: 'por_vencer' | 'vence' | null;
  propuesta_vencida: boolean;
  correo_activo: boolean;
  responsables: { id: number; name: string }[];
  puede_gestionar: boolean;
  puede_responder: boolean;
  estudiante_relacionado?: { id: number; nombre: string } | null;
  /** Operaciones que admite el caso para este usuario; Laravel vuelve a comprobar cada una. */
  operaciones: ('responsable' | 'tipo' | 'nota' | 'propuesta' | 'rechazo' | 'aceptacion' | 'respuesta')[];
  canales_respuesta: ('correo' | 'carta')[];
  /** Estado del correo de respuesta mientras el caso sigue abierto. */
  respuesta_correo: 'pendiente' | 'procesando' | 'enviado' | 'fallido' | null;
}

export interface HojaReclamacionDatos {
  id: string;
  numero: string;
  tipo: 'queja' | 'reclamo';
  canal: string;
  canal_etiqueta: string;
  texto_oficial: string;
  respuesta: string | null;
  respondida_local: string | null;
  impresa_local: string;
}
