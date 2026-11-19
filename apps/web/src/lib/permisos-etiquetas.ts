/**
 * Etiquetas en lenguaje común de los permisos del panel. Los permisos llegan de Laravel como claves técnicas
 * (`matriculas.ver`); aquí se traducen a «módulo» y «acción» para mostrarlos. La clave técnica nunca se muestra como texto
 * principal. Una clave sin traducción se muestra con un texto legible calculado, de modo que un permiso nuevo no rompe la pantalla.
 */

const MODULOS: Record<string, { etiqueta: string; orden: number }> = {
  panel: { etiqueta: 'Acceso a los paneles', orden: 0 },
  estudiantes: { etiqueta: 'Estudiantes', orden: 1 },
  cursos: { etiqueta: 'Cursos', orden: 2 },
  convocatorias: { etiqueta: 'Ediciones de cursos', orden: 3 },
  matriculas: { etiqueta: 'Matrículas', orden: 4 },
  pagos: { etiqueta: 'Pagos', orden: 5 },
  sesiones: { etiqueta: 'Clases', orden: 7 },
  asistencias: { etiqueta: 'Asistencia', orden: 8 },
  calificaciones: { etiqueta: 'Calificaciones', orden: 9 },
  reportes: { etiqueta: 'Reportes', orden: 11 },
  aprobaciones: { etiqueta: 'Aprobaciones', orden: 12 },
  reclamaciones: { etiqueta: 'Libro de reclamaciones y mensajes', orden: 13 },
  usuarios: { etiqueta: 'Usuarios', orden: 15 },
  roles: { etiqueta: 'Roles y permisos', orden: 16 },
  auditoria: { etiqueta: 'Historial de actividad', orden: 17 },
  respaldo: { etiqueta: 'Exportar datos', orden: 18 },
  asistente: { etiqueta: 'Asistente virtual', orden: 19 },
};

const ACCIONES: Record<string, string> = {
  ver: 'Ver',
  crear: 'Crear',
  editar: 'Editar',
  eliminar: 'Eliminar',
  restaurar: 'Restaurar eliminados',
  publicar: 'Publicar en la web',
  anular: 'Anular',
  exportar: 'Exportar',
  registrar: 'Registrar',
  corregir: 'Corregir registros cerrados',
  confirmar: 'Confirmar o rechazar pagos',
  reversar: 'Reversar pagos',
  planificar: 'Planificar cobros',
  gestionar: 'Gestionar',
  responder: 'Responder',
  cerrar: 'Cerrar',
  cancelar: 'Cancelar',
  acceder: 'Entrar',
  'asignar-roles': 'Asignar roles',
  'exportar.excel': 'Exportar a Excel',
  'exportar.pdf': 'Exportar a PDF',
};

/** Casos cuyo sentido depende del módulo y no se deduce de la acción. */
const ESPECIALES: Record<string, string> = {
  'panel.academico.acceder': 'Entrar al panel de gestión académica',
  'reclamaciones.gestionar': 'Gestionar reclamaciones y mensajes de contacto',
  'reclamaciones.responder': 'Responder reclamaciones',
  'aprobaciones.gestionar': 'Aprobar o rechazar solicitudes',
  'asistente.ver': 'Usar y revisar el asistente virtual',
  'respaldo.ver': 'Ver la página de exportación de datos',
  'respaldo.exportar': 'Descargar los datos exportados',
  'auditoria.ver': 'Ver el historial de actividad',
  'auditoria.exportar': 'Descargar el historial de actividad',
  'matriculas.eliminar': 'Eliminar matrículas',
};

const legible = (texto: string) => {
  const limpio = texto.replace(/[._-]+/g, ' ').trim();
  return limpio.charAt(0).toUpperCase() + limpio.slice(1);
};

export interface PermisoEtiquetado {
  clave: string;
  /** Clave del módulo (`matriculas`). */
  modulo: string;
  moduloEtiqueta: string;
  /** Texto de la casilla o de la lista: «Ver», «Confirmar o rechazar pagos»… */
  etiqueta: string;
}

export function etiquetaPermiso(clave: string): PermisoEtiquetado {
  const [modulo = clave, ...resto] = clave.split('.');
  const accion = resto.join('.');
  const infoModulo = MODULOS[modulo];
  return {
    clave,
    modulo,
    moduloEtiqueta: infoModulo?.etiqueta ?? legible(modulo),
    etiqueta: ESPECIALES[clave] ?? ACCIONES[accion] ?? (accion ? legible(accion) : legible(modulo)),
  };
}

export interface GrupoPermisos {
  modulo: string;
  etiqueta: string;
  permisos: PermisoEtiquetado[];
}

/** Agrupa por módulo en el orden de la pantalla (no en el alfabético de las claves). */
export function agruparPermisos(claves: readonly string[]): GrupoPermisos[] {
  const grupos = new Map<string, GrupoPermisos>();
  for (const clave of claves) {
    const permiso = etiquetaPermiso(clave);
    const grupo = grupos.get(permiso.modulo) ?? { modulo: permiso.modulo, etiqueta: permiso.moduloEtiqueta, permisos: [] };
    grupo.permisos.push(permiso);
    grupos.set(permiso.modulo, grupo);
  }
  return [...grupos.values()].sort((a, b) => (MODULOS[a.modulo]?.orden ?? 99) - (MODULOS[b.modulo]?.orden ?? 99) || a.etiqueta.localeCompare(b.etiqueta, 'es'));
}

/** Claves conocidas (para la prueba que exige que todo permiso del servidor tenga etiqueta). */
export const MODULOS_CONOCIDOS = Object.keys(MODULOS);
export const ACCIONES_CONOCIDAS = Object.keys(ACCIONES);
