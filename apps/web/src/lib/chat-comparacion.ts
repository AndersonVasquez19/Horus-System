/**
 * Convierte el listado detallado de cursos («Título: resumen Modalidad: … Duración: …», una línea por curso) en una
 * comparación: lo que todos comparten y lo que cambia en cada uno. Solo reordena datos que ya vienen en la respuesta
 * verificada; ante cualquier duda devuelve `null` y el chat muestra el texto tal como llegó. Sin imports, para probarlo
 * con `node --test`.
 */

const ETIQUETAS = ['Modalidad', 'Duración', 'Inicio', 'Horario', 'Requisitos', 'Certificación', 'Precio público', 'Consultas'] as const;
const PATRON_ETIQUETA = new RegExp(`(?:^|\\s)(${ETIQUETAS.join('|')}):\\s`, 'g');

export interface DatoCurso {
  etiqueta: string;
  /** `null`: ese curso no tiene el dato publicado. */
  valor: string | null;
}

export interface Comparacion {
  comunes: { etiqueta: string; valor: string }[];
  cursos: { titulo: string; diferencias: DatoCurso[] }[];
}

/** El punto final lo pone la frase, no el dato; el de «p. m.» sí es parte del dato. */
function limpiarValor(valor: string): string {
  const sinPunto = valor.trim().replace(/[.\s]+$/, '');
  return /\b[ap]\.\s?m$/i.test(sinPunto) ? `${sinPunto}.` : sinPunto;
}

/** Para comparar: sin mayúsculas, tildes, espacios repetidos ni punto final. Una errata sí cuenta como diferencia. */
function normalizar(valor: string): string {
  return valor.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').replace(/[.\s]+$/, '');
}

function datosDeFicha(texto: string): Map<string, string> | null {
  const marcas = [...texto.matchAll(PATRON_ETIQUETA)];
  const datos = new Map<string, string>();
  for (const [i, marca] of marcas.entries()) {
    const valor = limpiarValor(texto.slice(marca.index + marca[0].length, marcas[i + 1]?.index ?? texto.length));
    if (valor === '' || datos.has(marca[1])) return null;
    datos.set(marca[1], valor);
  }
  return datos.size > 0 ? datos : null;
}

export function compararCursos(texto: string, tarjetas: readonly { tipo: string; titulo: string }[] | undefined): Comparacion | null {
  if (!tarjetas || tarjetas.length < 2 || tarjetas.some((t) => t.tipo !== 'curso')) return null;
  const lineas = texto.split('\n').map((l) => l.trim()).filter((l) => l !== '');
  // Cada línea debe ser la ficha de una tarjeta y viceversa: así no se descarta ningún texto.
  if (lineas.length !== tarjetas.length) return null;

  const fichas: { titulo: string; datos: Map<string, string> }[] = [];
  for (const { titulo } of tarjetas) {
    const propias = lineas.filter((l) => l.startsWith(`${titulo}: `));
    if (propias.length !== 1) return null;
    const datos = datosDeFicha(propias[0].slice(titulo.length + 2));
    if (datos === null) return null;
    fichas.push({ titulo, datos });
  }

  const presentes = ETIQUETAS.filter((e) => fichas.some((f) => f.datos.has(e)));
  const esComun = (etiqueta: string) => {
    const valores = fichas.map((f) => f.datos.get(etiqueta));
    return valores.every((v) => v !== undefined && normalizar(v) === normalizar(valores[0] ?? ''));
  };

  return {
    comunes: presentes.filter(esComun).map((etiqueta) => ({ etiqueta, valor: fichas[0].datos.get(etiqueta) as string })),
    cursos: fichas.map(({ titulo, datos }) => ({
      titulo,
      diferencias: presentes.filter((e) => !esComun(e)).map((etiqueta) => ({ etiqueta, valor: datos.get(etiqueta) ?? null })),
    })),
  };
}
