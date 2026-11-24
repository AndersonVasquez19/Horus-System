/**
 * Utilidades puras de presentación del chat (sin imports de servidor, para probarlas con `node --test`).
 */

export interface RespuestaRapida {
  label: string;
  query: string;
}

const ETIQUETA_PEGADA = /([^\s.!?:;,])\s+(?=(?:Modalidad|Horario|Duración|Inicio|Certificación|Requisitos|Consultas):)/g;

/**
 * El texto de una ficha llega como «Título: resumen Modalidad: presencial. Duración: 2 semanas.»: el resumen no siempre
 * termina en punto y la primera etiqueta queda pegada («…lograr objetivos Modalidad:»). Se inserta el punto que falta
 * solo en la presentación; el texto verificado del servidor no se toca.
 */
export function separarEtiquetas(texto: string): string {
  return texto.replace(ETIQUETA_PEGADA, '$1. ');
}

const MAX_RAPIDAS = 6;
const MAX_ETIQUETA = 80;
const MAX_CONSULTA = 300;

/** Botones guardados en la pestaña: pueden haber sido alterados, así que solo se aceptan textos acotados. */
export function restaurarRespuestasRapidas(valor: unknown): RespuestaRapida[] {
  if (!Array.isArray(valor)) return [];
  const salida: RespuestaRapida[] = [];
  for (const item of valor.slice(0, MAX_RAPIDAS)) {
    if (typeof item !== 'object' || item === null) continue;
    const { label, query } = item as Record<string, unknown>;
    if (typeof label !== 'string' || typeof query !== 'string') continue;
    const etiqueta = label.trim().slice(0, MAX_ETIQUETA);
    const consulta = query.trim().slice(0, MAX_CONSULTA);
    if (etiqueta === '' || consulta === '') continue;
    salida.push({ label: etiqueta, query: consulta });
  }
  return salida;
}
