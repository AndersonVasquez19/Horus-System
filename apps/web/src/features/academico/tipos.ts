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
    ediciones?: { por_iniciar: number; por_cerrar: number; lista_por_cerrar: { identificador: string; codigo: string; curso: string | null }[] };
  };
  negocio?: { cobrado_mes?: string; saldo_por_cobrar?: string };
  academico?: {
    cupos?: { identificador: string; codigo: string; curso: string | null; maximo: number; ocupados: number; libres: number }[];
    asistencia_minima?: number;
    asistencia?: { identificador: string; codigo: string; curso: string | null; sesiones_realizadas: number; promedio: number | null; bajo_minimo: boolean }[];
  };
  /** Pendientes y actividad reciente; cada bloque llega solo con su permiso (`aprobaciones.ver`, `auditoria.ver`). */
  seguimiento?: {
    aprobaciones_pendientes?: number;
    notificaciones_sin_leer?: number;
    auditoria_reciente?: { id: number; fecha_local: string; usuario: string | null; accion: string; accion_etiqueta?: string }[];
  };
}

/** Resultado de `GET academico/busqueda`: cada grupo solo si la persona tiene el permiso de su módulo. */
export interface ResultadoBusqueda {
  consulta: string;
  grupos: {
    estudiantes?: { id: number; texto: string; detalle: string }[];
    matriculas?: { id: number; texto: string; detalle: string }[];
    ediciones?: { identificador: string; texto: string; detalle: string }[];
  };
}

export interface CursoResumen {
  id: number;
  codigo: string;
  nombre: string;
  categoria: string;
  modalidad: string;
  estado: string;
  cupos_maximos: number;
  cupos_disponibles: number;
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
  modalidad: string;
  horario: string | null;
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
  duracion_horas: number;
  precio: string;
  horario: string | null;
  actualizado_en: string | null;
  matriculas_activas: number;
  estudiantes: { id: number; nombre: string; estado_matricula: string; fecha_matricula: string | null }[];
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
  matriculas?: { id: number; curso: { id: number; codigo: string; nombre: string } | null; estado: string; fecha: string | null }[];
}

export interface MatriculaResumen {
  id: number;
  estudiante: { id: number; dni: string; nombre: string; eliminado: boolean } | null;
  curso: { id: number; codigo: string; nombre: string; eliminado: boolean } | null;
  /** Convocatoria de la que sale el cupo; sin ella, el cupo es el del curso. */
  convocatoria: { identificador: string; codigo: string } | null;
  estado: 'pendiente' | 'pagada' | 'anulada';
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
  curso: { id: number; codigo: string; nombre: string } | null;
  modalidad: string;
  estado: EstadoConvocatoria;
  fecha_inicio: string;
  fecha_fin: string;
  cupos: { maximos: number; disponibles: number; ocupados: number };
}

export interface EvaluacionActa {
  identificador: string;
  nombre: string;
  peso: string;
  fecha: string | null;
  /** Con notas registradas, la evaluación no se elimina (solo se corrige su peso). */
  con_notas: boolean;
}

export interface FilaActa {
  /** Id de la matrícula (ya expuesto por el módulo de matrículas). */
  id: number;
  estudiante: { dni: string | null; nombre: string };
  /** Nota por identificador de evaluación; falta la clave si todavía no se registró. */
  notas: Record<string, string>;
  nota_final: string | null;
  resultado: 'aprobado' | 'desaprobado' | null;
  /** % de asistencia; `null` si no hay sesiones computables o la persona no puede ver la asistencia. */
  asistencia: number | null;
}

/** Evaluaciones y matriz de notas de una convocatoria (`convocatorias/{id}/calificaciones`). */
export interface ActaCalificaciones {
  convocatoria: { identificador: string; codigo: string; curso: string | null; estado: EstadoConvocatoria; revision: string };
  nota_aprobatoria: number;
  peso_total: string;
  /** La persona puede ver la asistencia (`asistencias.ver`). */
  asistencia_visible: boolean;
  evaluaciones: EvaluacionActa[];
  matriculas: FilaActa[];
}

export interface HorarioConvocatoria {
  /** 1 = lunes … 7 = domingo. */
  dia_semana: number;
  hora_inicio: string;
  hora_fin: string;
}

export interface ConvocatoriaDetalle extends ConvocatoriaResumen {
  horarios: HorarioConvocatoria[];
  creado_en: string | null;
  revision: string;
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

export interface SesionClase {
  identificador: string;
  edicion: string | null;
  numero: number;
  fecha: string;
  hora_inicio: string;
  hora_fin: string;
  tema: string | null;
  estado: 'programada' | 'realizada' | 'cancelada';
  motivo_cancelacion: string | null;
  revision: string;
}

export type EstadoAsistencia = 'presente' | 'ausente' | 'tardanza' | 'justificada';

export interface AsistenciaFila {
  matricula_id: number;
  estudiante: { dni: string; nombre: string };
  estado: EstadoAsistencia | null;
  observacion: string | null;
  corregida: boolean;
}

export interface ListaAsistencia {
  sesion: { identificador: string; numero: number; fecha: string; hora_inicio: string; hora_fin: string; estado: string };
  matriculas: AsistenciaFila[];
}

export interface ResumenAsistenciaFila {
  matricula_id: number;
  estudiante: { dni: string; nombre: string };
  presentes: number;
  tardanzas: number;
  ausentes: number;
  justificadas: number;
  sin_registro: number;
  porcentaje: number | null;
}

export type EstadoCobro = 'pendiente' | 'parcial' | 'pagada' | 'anulada';

export interface ObligacionResumen {
  identificador: string;
  matricula_id: number;
  estudiante: { dni: string; nombre: string };
  edicion: string | null;
  concepto: string;
  plan: 'completo' | 'fraccionado';
  estado: EstadoCobro;
  moneda: 'PEN';
  monto_total: string;
  pagado: string;
  pendiente_confirmar: string;
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
  /** Hay una captura de comprobante adjunta (la imagen se pide aparte, con permiso). */
  tiene_comprobante: boolean;
}

export interface ObligacionDetalle extends ObligacionResumen {
  motivo_anulacion: string | null;
  cuotas: { numero: number; monto: string; vence: string; pagado: string; saldo: string }[];
  transacciones: TransaccionPagoVista[];
}
