/** Contratos de `/api/panel/v1/academico/*` (ver packages/contracts/openapi/horus-api.yaml). */

export interface ResumenAcademico {
  indicadores: {
    estudiantes_activos?: number;
    cursos_activos?: number;
    cupos_disponibles?: number;
    cupos_maximos?: number;
    matriculas_mes?: number;
    matriculas_pendientes?: number;
    /** Solo para el rol admin. */
    ingresos_mes?: string;
  };
  bloques: {
    matriculas_por_mes?: { periodo: string; etiqueta: string; total: number }[];
    ultimas_matriculas?: { id: number; estudiante: string | null; curso: string | null; estado: string; fecha: string | null }[];
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
