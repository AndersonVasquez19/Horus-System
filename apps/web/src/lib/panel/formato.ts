/** Formatos y etiquetas de presentación del panel (es-PE). Sin lógica de negocio. */

const FECHA = new Intl.DateTimeFormat('es-PE', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'America/Lima' });
const MONEDA = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' });
const NUMERO = new Intl.NumberFormat('es-PE');

export function fecha(valor: string | null | undefined): string {
  if (!valor) return '—';
  // Una fecha sin hora (AAAA-MM-DD) se interpreta en Lima, no en UTC.
  const instante = /^\d{4}-\d{2}-\d{2}$/.test(valor) ? new Date(`${valor}T12:00:00-05:00`) : new Date(valor);
  return Number.isNaN(instante.getTime()) ? '—' : FECHA.format(instante);
}

export function moneda(valor: string | number | null | undefined): string {
  if (valor === null || valor === undefined || valor === '') return '—';
  const numero = typeof valor === 'number' ? valor : Number.parseFloat(valor);
  return Number.isNaN(numero) ? '—' : MONEDA.format(numero);
}

export function numero(valor: number | null | undefined): string {
  return typeof valor === 'number' ? NUMERO.format(valor) : '—';
}

export const ETIQUETAS = {
  estadoCurso: { activo: 'Activo', inactivo: 'Inactivo', archivado: 'Archivado' },
  modalidad: { presencial: 'Presencial', semipresencial: 'Semipresencial', virtual: 'Virtual', hibrido: 'Híbrido' },
  categoria: { tecnologia: 'Tecnología', educacion: 'Educación', salud: 'Salud', gestion: 'Gestión', otros: 'Otros' },
  estadoMatricula: { pendiente: 'Pendiente', pagada: 'Pagada', anulada: 'Anulada' },
  estadoEstudiante: { activo: 'Activo', inactivo: 'Inactivo' },
  accionMatricula: {
    matricula_creada: 'Matrícula registrada',
    matricula_actualizada: 'Matrícula modificada',
    matricula_anulada: 'Matrícula anulada',
    matricula_eliminada: 'Matrícula eliminada',
    matricula_restaurada: 'Matrícula restaurada',
  },
  accionEstudiante: {
    estudiante_creado: 'Estudiante registrado',
    estudiante_actualizado: 'Datos modificados',
    estudiante_eliminado: 'Estudiante desactivado',
    estudiante_restaurado: 'Estudiante restaurado',
  },
  campoEstudiante: {
    dni: 'DNI',
    nombres: 'nombres',
    apellidos: 'apellidos',
    email: 'correo',
    telefono: 'teléfono',
    fecha_nacimiento: 'nacimiento',
    direccion: 'dirección',
    estado: 'estado',
  },
  accionCurso: {
    curso_creado: 'Curso registrado',
    curso_actualizado: 'Datos modificados',
    curso_archivado: 'Curso archivado',
    curso_restaurado: 'Curso restaurado',
    curso_publicado: 'Ficha pública autorizada',
    curso_despublicado: 'Ficha pública retirada',
  },
  campoCurso: {
    codigo: 'código',
    nombre: 'nombre',
    descripcion: 'descripción',
    categoria: 'categoría',
    duracion_horas: 'duración',
    precio: 'precio',
    modalidad: 'modalidad',
    horario: 'horario',
    cupos_maximos: 'cupos',
    cupos_disponibles: 'cupos disponibles',
    estado: 'estado',
  },
  efectoCupo: { ocupa: 'Ocupó un cupo', libera: 'Liberó un cupo', traslada: 'Trasladó el cupo', ninguno: 'Sin efecto en cupos' },
  campoMatricula: {
    estudiante_id: 'estudiante',
    curso_id: 'curso',
    fecha_matricula: 'fecha',
    estado: 'estado',
    monto_pagado: 'monto',
    observaciones: 'observaciones',
  },
  estadoServicio: { activo: 'Activo', inactivo: 'Inactivo', archivado: 'Archivado' },
  estadoPublicacion: { sin_ficha: 'Sin ficha', borrador: 'Borrador', publicada: 'Publicada', retirada: 'Retirada' },
  estadoCotizacion: { recibida: 'Recibida', en_revision: 'En revisión' },
  origenCotizacion: { publico: 'Web', administrativo: 'Interna' },
  estadoEditorial: {
    sin_ficha: 'Sin ficha',
    borrador: 'Borrador',
    publicada: 'Publicada',
    retirada: 'Retirada',
    caducada: 'Caducada',
    programada: 'Programada',
  },
  accionCotizacion: {
    creada: 'Solicitud registrada',
    informacion_actualizada: 'Datos modificados',
    estado_modificado: 'Estado modificado',
    responsable_asignado: 'Responsable asignado',
    seguimiento_registrado: 'Seguimiento registrado',
  },
} as const;

export function etiqueta<T extends keyof typeof ETIQUETAS>(grupo: T, valor: string | null | undefined): string {
  if (!valor) return '—';
  return (ETIQUETAS[grupo] as Record<string, string>)[valor] ?? valor;
}
