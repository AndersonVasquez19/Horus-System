/**
 * Siguientes pasos que propone el asistente (Horus AI → Laravel → BFF → widget). Viajan como claves cerradas, nunca
 * como texto: aquí se validan y se traducen a botones. Las consultas no llevan el título del curso porque el curso en
 * foco lo conserva el estado firmado del servidor. Sin imports de servidor, para probarlo con `node --test`.
 */

export const CLAVES_SUGERENCIA = ['schedule', 'modality', 'enrollment', 'details', 'compare', 'price'] as const;
export type ClaveSugerencia = (typeof CLAVES_SUGERENCIA)[number];

export interface BotonSugerido {
  label: string;
  query: string;
}

const BOTONES: Record<ClaveSugerencia, BotonSugerido> = {
  schedule: { label: 'Ver horario', query: '¿Cuál es el horario?' },
  modality: { label: 'Ver modalidad', query: '¿Cuál es la modalidad?' },
  enrollment: { label: 'Cómo matricularme', query: '¿Cómo puedo matricularme?' },
  details: { label: 'Más detalles', query: 'Dame más detalles' },
  compare: { label: 'Comparar cursos', query: 'Compárame los cursos disponibles' },
  price: { label: 'Consultar precio', query: '¿Cuánto cuesta?' },
};

const MAX_SUGERENCIAS = 4;

/** Lista cerrada, sin repetidos y de hasta 4 claves; cualquier otra cosa se descarta entera (nunca se corrige). */
export function sanearSugerencias(valor: unknown): ClaveSugerencia[] {
  if (!Array.isArray(valor) || valor.length === 0 || valor.length > MAX_SUGERENCIAS) return [];
  const claves = new Set<ClaveSugerencia>();
  for (const item of valor) {
    if (typeof item !== 'string' || !(CLAVES_SUGERENCIA as readonly string[]).includes(item)) return [];
    claves.add(item as ClaveSugerencia);
  }
  return claves.size === valor.length ? [...claves] : [];
}

/** Frase común de las respuestas de Horus AI cuando el curso pedido no está publicado. */
const CURSO_NO_PUBLICADO = 'un curso o capacitación sobre ese tema';

/** Tras «no tengo ese curso» los siguientes pasos no son su modalidad ni su matrícula: se ofrece ver los que sí hay. */
export function botonesDeCursoNoPublicado(respuesta: string): BotonSugerido[] | null {
  return respuesta.includes(CURSO_NO_PUBLICADO) ? [{ label: 'Ver cursos disponibles', query: '¿Qué cursos ofrecen?' }] : null;
}

export function botonesDeSugerencias(claves: readonly ClaveSugerencia[]): BotonSugerido[] {
  return claves.map((clave) => ({ ...BOTONES[clave] }));
}
