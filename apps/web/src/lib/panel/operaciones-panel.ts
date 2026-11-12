import 'server-only';

/**
 * Operaciones del navegador que el BFF reenvía a `/api/panel/v1/*` (`app/api/bff/panel/[...ruta]`).
 *
 * Es una lista cerrada, no un proxy: cada entrada fija método, forma de la ruta, campos del cuerpo y parámetros de
 * consulta que se reenvían. Todo lo demás se descarta o se rechaza con 404. Laravel sigue siendo la autoridad de
 * sesión, CSRF, permisos y reglas; el BFF solo impide que el navegador elija destinos o envíe datos inesperados.
 */

export type Metodo = 'GET' | 'POST' | 'PUT' | 'DELETE';

export interface Operacion {
  metodo: Metodo;
  /** Ruta relativa a /api/panel/v1, completa (de principio a fin). */
  patron: RegExp;
  /** Campos del cuerpo JSON que se reenvían (solo cadenas, números, booleanos o null). */
  campos?: readonly string[];
  /** Parámetros de consulta que se reenvían. */
  consulta?: readonly string[];
  /** La respuesta es un archivo con alguno de estos tipos MIME. */
  archivo?: readonly string[];
}

const ID = '[1-9][0-9]{0,17}';

const CAMPOS_ESTUDIANTE = ['dni', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion', 'estado'] as const;
const CAMPOS_CURSO = ['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'precio', 'modalidad', 'horario', 'cupos_maximos', 'estado'] as const;
const CAMPOS_FICHA = ['titulo', 'resumen', 'modalidad', 'horario', 'inscripcion', 'vigente_desde', 'vigente_hasta', 'confirmacion_publica', '_revision'] as const;
const FILTROS_REPORTE = ['busqueda', 'estado', 'eliminados', 'lote'] as const;
const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const CAMPOS_MATRICULA = ['estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado', 'observaciones'] as const;
const FILTROS_MATRICULAS = ['busqueda', 'estado', 'curso_id', 'eliminados'] as const;

export const OPERACIONES: readonly Operacion[] = [
  // Estudiantes
  { metodo: 'POST', patron: /^academico\/estudiantes$/, campos: CAMPOS_ESTUDIANTE },
  { metodo: 'PUT', patron: new RegExp(`^academico/estudiantes/${ID}$`), campos: CAMPOS_ESTUDIANTE },
  { metodo: 'DELETE', patron: new RegExp(`^academico/estudiantes/${ID}$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/estudiantes/${ID}/restaurar$`) },

  // Cursos y ficha pública (la revisión viaja en el cuerpo: `_revision`)
  { metodo: 'POST', patron: /^academico\/cursos$/, campos: CAMPOS_CURSO },
  { metodo: 'PUT', patron: new RegExp(`^academico/cursos/${ID}$`), campos: CAMPOS_CURSO },
  { metodo: 'DELETE', patron: new RegExp(`^academico/cursos/${ID}$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/restaurar$`) },
  { metodo: 'GET', patron: new RegExp(`^academico/cursos/${ID}/publicacion$`) },
  { metodo: 'PUT', patron: new RegExp(`^academico/cursos/${ID}/publicacion$`), campos: CAMPOS_FICHA },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/publicacion/retirar$`), campos: ['_revision'] },

  // Reportes de matrículas (Excel y PDF se generan en Laravel)
  { metodo: 'GET', patron: /^academico\/reportes$/, consulta: FILTROS_REPORTE },
  { metodo: 'POST', patron: /^academico\/reportes\/exportar\/excel$/, consulta: FILTROS_REPORTE, archivo: [XLSX] },
  { metodo: 'POST', patron: /^academico\/reportes\/exportar\/pdf$/, consulta: FILTROS_REPORTE, archivo: ['application/pdf'] },

  // Matrículas
  { metodo: 'GET', patron: /^academico\/matriculas\/opciones\/(estudiantes|cursos)$/, consulta: ['q', 'contexto'] },
  { metodo: 'POST', patron: /^academico\/matriculas$/, campos: CAMPOS_MATRICULA },
  { metodo: 'PUT', patron: new RegExp(`^academico/matriculas/${ID}$`), campos: CAMPOS_MATRICULA },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/(anular|restaurar)$`) },
  { metodo: 'DELETE', patron: new RegExp(`^academico/matriculas/${ID}$`) },
  { metodo: 'POST', patron: /^academico\/matriculas\/exportar$/, consulta: FILTROS_MATRICULAS, archivo: ['text/csv'] },
];

export function buscarOperacion(metodo: string, ruta: string): Operacion | null {
  return OPERACIONES.find((operacion) => operacion.metodo === metodo && operacion.patron.test(ruta)) ?? null;
}

/** Cuerpo reenviado: solo los campos declarados y con valores escalares. */
export function cuerpoPermitido(operacion: Operacion, cuerpo: Record<string, unknown>): Record<string, string | number | boolean | null> | undefined {
  if (operacion.metodo === 'GET' || operacion.metodo === 'DELETE') return undefined;
  const resultado: Record<string, string | number | boolean | null> = {};
  for (const campo of operacion.campos ?? []) {
    const valor = cuerpo[campo];
    if (valor === null || typeof valor === 'string' || typeof valor === 'number' || typeof valor === 'boolean') {
      resultado[campo] = typeof valor === 'string' ? valor.slice(0, 4000) : valor;
    }
  }
  return resultado;
}

/** Cadena de consulta con solo los parámetros declarados (texto acotado). */
export function consultaPermitida(operacion: Operacion, parametros: URLSearchParams): string {
  const salida = new URLSearchParams();
  for (const nombre of operacion.consulta ?? []) {
    const valor = parametros.get(nombre);
    if (valor !== null && valor.trim() !== '') salida.set(nombre, valor.trim().slice(0, 200));
  }
  const texto = salida.toString();
  return texto === '' ? '' : `?${texto}`;
}
