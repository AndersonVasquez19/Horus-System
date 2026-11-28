/** Contratos de `/api/panel/v1/academico/*` (ver packages/contracts/openapi/horus-api.yaml). */

export interface ResumenAcademico {
  indicadores: {
    estudiantes_activos?: number;
    cursos_activos?: number;
    cupos_disponibles?: number;
    cupos_maximos?: number;
    matriculas_mes?: number;
    matriculas_pendientes?: number;
    /** Matrículas del mes anterior (para la tendencia). */
    matriculas_mes_anterior?: number;
    /** Solo para el rol admin. */
    ingresos_mes?: string;
  };
  bloques: {
    matriculas_por_mes?: { periodo: string; etiqueta: string; total: number }[];
    ultimas_matriculas?: { id: number; estudiante: string | null; curso: string | null; estado: string; fecha: string | null }[];
  };
  /** Cada bloque llega solo si la persona tiene el permiso de su módulo (el servidor no envía lo que no puede consultar). */
  acciones?: {
    pagos_por_confirmar?: { cantidad: number; monto: string };
    matriculas_con_saldo?: { cantidad: number; monto: string };
    reclamaciones?: { vencidas: number; vencen_hoy: number; por_vencer: number };
    cuotas?: { vencidas: number; monto_vencido: string; por_vencer: number; monto_por_vencer: string };
    ediciones?: { por_iniciar: number; por_cerrar: number; lista_por_cerrar: { identificador: string; codigo: string; curso: string | null }[] };
  };
  negocio?: { cobrado_mes?: string; saldo_por_cobrar?: string };
  academico?: {
    satisfaccion?: { emitidas: number; respondidas: number; tasa_respuesta: number | null; promedio: number | null; felices_pct: number | null };
    cupos?: { identificador: string; codigo: string; curso: string | null; maximo: number; ocupados: number; libres: number }[];
  };
  /** Pendientes y actividad reciente; cada bloque llega solo con su permiso (`aprobaciones.ver`, `auditoria.ver`). */
  seguimiento?: {
    aprobaciones_pendientes?: number;
    notificaciones_sin_leer?: number;
    auditoria_reciente?: { id: number; fecha_local: string; usuario: string | null; accion: string; accion_etiqueta?: string }[];
  };
  salud_grupo?: {
    cuotas_vencidas?: { obligacion: string; estudiante: string; telefono: string | null; concepto: string; vence: string; saldo: string }[];
  };
}

/** Resultado de `GET academico/busqueda`: cada grupo solo si la persona tiene el permiso de su módulo. */
export interface ResultadoBusqueda {
  consulta: string;
  grupos: {
    estudiantes?: { id: number; texto: string; detalle: string }[];
    matriculas?: { id: number; texto: string; detalle: string }[];
    ediciones?: { identificador: string; texto: string; detalle: string }[];
    pagos?: { identificador: string; texto: string; detalle: string }[];
  };
}

export interface CursoResumen {
  id: number;
  codigo: string;
  nombre: string;
  categoria: string;
  modalidad: string;
  duracion_horas: number;
  precio: string;
  estado: string;
  cupos_maximos: number;
  cupos_disponibles: number;
  publicado: boolean;
  enlace_grupo_whatsapp: string | null;
  eliminado: boolean;
  creado_en: string | null;
}

/** Evento del historial de un estudiante o un curso (auditoría minimizada: nombres de campo, nunca valores). */
export interface EventoHistorialBasico {
  id: number;
  accion: string;
  fecha: string;
  usuario: string | null;
  campos: string[];
  estado_anterior: string | null;
  estado_nuevo: string | null;
}

export interface FichaPublica {
  titulo: string;
  resumen: string;
  imagen_url?: string | null;
  modalidad: string;
  horario: string | null;
  fecha_inicio?: string | null;
  duracion?: string | null;
  certificacion?: string | null;
  temario?: string | null;
  inscripcion: string | null;
  vigente_desde: string;
  vigente_hasta: string;
  publicada: boolean;
  autorizado_en: string | null;
}

/** `academico/cursos/{id}/publicacion`: la revisión se devuelve en cada lectura y debe enviarse al escribir. */
export interface PublicacionCurso {
  revision: string;
  publicable: boolean;
  curso: { id: number; codigo: string; nombre: string; modalidad: string };
  ficha: FichaPublica | null;
}

export interface CursoDetalle extends CursoResumen {
  historial: EventoHistorialBasico[];
  descripcion: string | null;
  horario: string | null;
  actualizado_en: string | null;
  matriculas_activas: number;
  estudiantes: { id: number; matricula_id: number; nombre: string; estado_matricula: string; fecha_matricula: string | null }[];
}

export interface EstudianteResumen {
  id: number;
  dni: string;
  nombres: string;
  apellidos: string;
  email: string | null;
  telefono: string | null;
  estado: 'activo' | 'inactivo';
  eliminado: boolean;
  creado_en: string | null;
}

export interface EstudianteDetalle extends EstudianteResumen {
  fecha_nacimiento: string | null;
  direccion: string | null;
  actualizado_en: string | null;
  historial: EventoHistorialBasico[];
  /** Solo con permiso `matriculas.ver`. */
  matriculas?: {
    id: number;
    curso: { id: number; codigo: string; nombre: string; enlace_grupo_whatsapp?: string | null } | null;
    estado: string;
    fecha: string | null;
  }[];
}

export interface MatriculaResumen {
  id: number;
  estudiante: { id: number; dni: string; nombre: string; email?: string | null; telefono?: string | null; eliminado: boolean } | null;
  curso: { id: number; codigo: string; nombre: string; precio?: string; enlace_grupo_whatsapp?: string | null; eliminado: boolean } | null;
  /** Convocatoria de la que sale el cupo; sin ella, el cupo es el del curso. */
  convocatoria: { identificador: string; codigo: string } | null;
  estado: 'pendiente' | 'pagada' | 'anulada';
  etiqueta?: 'factura' | 'becado' | 'seguimiento' | 'empresa' | null;
  estado_cobro?: 'pendiente' | 'parcial' | 'pagada' | 'anulada' | 'sin_obligacion';
  obligacion_identificador?: string | null;
  saldo_cobro?: string | null;
  fecha: string;
  monto_pagado: string | null;
  eliminado: boolean;
}

export interface EventoHistorial {
  id: number;
  accion: string;
  fecha: string;
  usuario: string | null;
  efecto_cupo: 'ocupa' | 'libera' | 'traslada' | 'ninguno' | null;
  campos: string[];
  estado_anterior: string | null;
  estado_nuevo: string | null;
}

export interface MatriculaDetalle extends MatriculaResumen {
  observaciones: string | null;
  motivo_anulacion?: string | null;
  curso_cupos_disponibles: number | null;
  creado_en: string | null;
  actualizado_en: string | null;
  historial?: EventoHistorial[];
}

/** Opción de los selectores de estudiante y curso (`matriculas/opciones/*`). */
export interface OpcionSelector {
  id: number;
  texto: string;
  eliminado: boolean;
}

export type EstadoConvocatoria = 'borrador' | 'abierta' | 'en_curso' | 'cerrada' | 'cancelada';

export interface ConvocatoriaResumen {
  identificador: string;
  codigo: string;
  /** Nombre propio o, si no tiene, el del curso. */
  nombre: string | null;
  nombre_propio: string | null;
  curso: { id: number; codigo: string; nombre: string; enlace_grupo_whatsapp?: string | null } | null;
  modalidad: string;
  estado: EstadoConvocatoria;
  fecha_inicio: string;
  fecha_fin: string;
  cupos: { maximos: number; disponibles: number; ocupados: number };
}

export interface HorarioConvocatoria {
  /** 1 = lunes … 7 = domingo. */
  dia_semana: number;
  hora_inicio: string;
  hora_fin: string;
}

export interface MatriculadoEdicion {
  id: number;
  fecha: string;
  estado: 'pendiente' | 'pagada' | 'anulada';
  estudiante: { id: number; dni: string; nombre: string; email: string | null; telefono: string | null } | null;
  obligacion: { identificador: string; estado: EstadoCobro; monto_total: string; pagado: string; saldo: string } | null;
}

export interface ConvocatoriaDetalle extends ConvocatoriaResumen {
  horarios: HorarioConvocatoria[];
  creado_en: string | null;
  revision: string;
  matriculados?: MatriculadoEdicion[];
}

/** Estados a los que se puede pasar (espejo de `Convocatoria::TRANSICIONES`; Laravel es la autoridad). */
export const TRANSICIONES_CONVOCATORIA: Record<EstadoConvocatoria, readonly EstadoConvocatoria[]> = {
  borrador: ['abierta', 'cancelada'],
  abierta: ['en_curso', 'cancelada'],
  en_curso: ['cerrada', 'cancelada'],
  cerrada: [],
  cancelada: [],
};

export type EstadoActivo = 'activo' | 'inactivo';

/** Respuesta de la consulta de DNI (JSON.pe vía Laravel). Solo consulta: no registra a nadie. */
export interface PersonaDni {
  dni: string;
  nombres: string;
  apellido_paterno: string;
  apellido_materno: string;
  apellidos: string;
  ya_registrado_como_estudiante?: boolean;
}

export interface MatriculaMasivaAnalisis {
  puede_crear_estudiantes: boolean;
  cupos_disponibles: number;
  resumen: { matricular: number; crear_y_matricular: number; omitir: number; sin_cupo: number; error: number };
  filas: { fila: number; dni: string; nombre: string; accion: string; motivo: string | null }[];
}

export interface MatriculaMasivaResultado {
  resumen: { matriculadas: number; estudiantes_creados: number; omitir: number; sin_cupo: number; error: number };
  filas: { fila: number; dni: string; nombre: string; accion: string; motivo: string | null }[];
}

export interface ListaEsperaVista {
  cupos_disponibles: number;
  hay_cupo: boolean;
  admite_matriculas: boolean;
  total: number;
  items: { identificador: string; posicion: number; estudiante: { id: number; nombre: string; dni: string; telefono: string | null } | null; nota: string | null; desde: string | null }[];
}

export type EstadoCobro = 'pendiente' | 'parcial' | 'pagada' | 'anulada';

export interface ObligacionResumen {
  identificador: string;
  matricula_id: number;
  estudiante: { id: number; dni: string; nombre: string; telefono: string | null; email: string | null };
  edicion: string | null;
  concepto: string;
  plan: 'completo' | 'fraccionado';
  estado: EstadoCobro;
  moneda: 'PEN';
  monto_total: string;
  pagado: string;
  pendiente_confirmar: string;
  saldo: string;
  proxima_cuota?: { numero: number; vence: string; saldo: string; vencida: boolean } | null;
}

/** La misma captura ya respalda otro pago vigente (de esta matrícula u otra): solo un aviso para quien confirma. */
export interface ImagenRepetida {
  matricula_id: number;
  fecha_pago: string;
}

/** Fila de la vista principal de Pagos: un pago con su comprobante y lo que aún debe la matrícula (`pagos/comprobantes`). */
export interface ComprobanteFila {
  identificador: string;
  obligacion: string;
  matricula_id: number;
  estudiante: { id: number; dni: string; nombre: string };
  edicion: string | null;
  monto: string;
  metodo: string;
  fecha_pago: string;
  referencia: string | null;
  estado: 'pendiente' | 'confirmada' | 'rechazada';
  tiene_comprobante: boolean;
  imagen_repetida: ImagenRepetida | null;
  tipo_comprobante: 'imagen' | 'pdf' | null;
  revertida: boolean;
  total: string;
  pagado: string;
  por_confirmar: string;
  saldo: string;
}

export interface TransaccionPagoVista {
  identificador: string;
  tipo: 'pago' | 'reverso';
  estado: 'pendiente' | 'confirmada' | 'rechazada';
  monto: string;
  metodo: string;
  fecha_pago: string;
  referencia: string | null;
  motivo: string | null;
  reversa_de: string | null;
  revertida: boolean;
  /** Solo en pagos cuyo reverso exige aprobación de Gerencia (desde el umbral). Puede faltar en respuestas anteriores. */
  reverso_aprobacion?: 'ninguna' | 'pendiente' | 'aprobado' | 'rechazado' | null;
  /** Hay una captura de comprobante adjunta (la imagen se pide aparte, con permiso). */
  tiene_comprobante: boolean;
  /** La misma captura ya respalda otro pago vigente. Puede faltar en respuestas anteriores a este campo. */
  imagen_repetida?: ImagenRepetida | null;
  /** `pdf` solo se descarga (nunca se muestra en la página); `imagen` se ve en un diálogo. */
  tipo_comprobante?: 'imagen' | 'pdf' | null;
}

export interface ObligacionDetalle extends ObligacionResumen {
  motivo_anulacion: string | null;
  cuotas: { numero: number; monto: string; vence: string; pagado: string; saldo: string }[];
  transacciones: TransaccionPagoVista[];
}

export interface CertificadoResumen {
  identificador: string;
  codigo: string;
  estado: 'emitido' | 'anulado';
  estado_etiqueta: string;
  matricula_id: number;
  estudiante: { id: number | null; dni: string; nombre: string };
  curso: { id: number | null; codigo: string; nombre: string; duracion_horas: number | null };
  edicion: { identificador: string; codigo: string; nombre: string | null } | null;
  porcentaje_asistencia: number | null;
  nota_final: number | null;
  emitido_en: string;
  emitido_por: string | null;
  motivo_excepcion: string | null;
  anulado_en: string | null;
  anulado_por: string | null;
  motivo_anulacion: string | null;
}

// ---- Estudiante 360° ----

export interface EventoLineaTiempo {
  tipo: 'matricula' | 'pago' | 'cobranza' | 'certificado' | 'nota_interna';
  fecha: string;
  titulo: string;
  detalle: string | null;
  enlace: string | null;
  autor: string | null;
}

export interface LineaTiempoEstudiante {
  eventos: EventoLineaTiempo[];
  truncado: boolean;
}
