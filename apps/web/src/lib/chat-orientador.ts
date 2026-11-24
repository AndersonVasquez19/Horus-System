/**
 * Menú del orientador del chat («Ayúdame a elegir un curso»). Se arma siempre con los cursos que Laravel tiene
 * publicados: nunca con nombres escritos a mano. Sin imports de servidor para poder probarlo con `node --test`.
 */

export interface CursoOrientador {
  titulo: string;
  modalidad: string;
  vigente_hasta?: string | null;
}

export interface OpcionOrientador {
  label: string;
  query: string;
}

export interface MenuOrientador {
  texto: string;
  opciones: OpcionOrientador[];
  /** true cuando no hay cursos que ofrecer o no se pudo consultar: el chat añade el enlace de WhatsApp. */
  conWhatsapp: boolean;
}

export const MAX_CURSOS_EN_MENU = 4;

const ETIQUETA_MODALIDAD: Record<string, string> = {
  presencial: 'Presencial',
  semipresencial: 'Semipresencial',
  virtual: 'Virtual',
  hibrido: 'Híbrido',
};

const ASESORAMIENTO: OpcionOrientador = {
  label: 'Asesoramiento académico y de tesis',
  query: '¿En qué consiste el servicio de asesoramiento y tesis?',
};

const COMPARAR: OpcionOrientador = {
  label: 'Comparar todos los cursos y horarios',
  query: 'Compárame los cursos disponibles',
};

export function etiquetaModalidad(modalidad: string): string | null {
  return ETIQUETA_MODALIDAD[modalidad.trim().toLowerCase()] ?? null;
}

function etiquetaCurso(curso: CursoOrientador): string {
  const modalidad = etiquetaModalidad(curso.modalidad);
  return modalidad === null ? curso.titulo : `${curso.titulo} (${modalidad})`;
}

/** Normaliza lo que llega del navegador/servidor: descarta entradas sin título y recorta espacios. */
export function cursosValidos(entrada: unknown): CursoOrientador[] {
  if (!Array.isArray(entrada)) return [];
  const vistos = new Set<string>();
  const cursos: CursoOrientador[] = [];
  for (const item of entrada) {
    if (typeof item !== 'object' || item === null) continue;
    const { titulo, modalidad, vigente_hasta: hasta } = item as Record<string, unknown>;
    if (typeof titulo !== 'string' || titulo.trim() === '') continue;
    const limpio = titulo.trim().slice(0, 120);
    if (vistos.has(limpio.toLowerCase())) continue;
    vistos.add(limpio.toLowerCase());
    cursos.push({
      titulo: limpio,
      modalidad: typeof modalidad === 'string' ? modalidad : '',
      vigente_hasta: typeof hasta === 'string' ? hasta : null,
    });
  }
  return cursos;
}

/**
 * `cursos === null` significa «no se pudo consultar el catálogo»; un arreglo vacío, «no hay cursos publicados».
 */
export function armarMenuOrientador(cursos: CursoOrientador[] | null): MenuOrientador {
  if (cursos === null) {
    return {
      texto:
        'No pude consultar la lista de cursos en este momento. Puedes pedirme la comparación en un momento o escribirnos por WhatsApp.',
      opciones: [COMPARAR, ASESORAMIENTO],
      conWhatsapp: true,
    };
  }
  if (cursos.length === 0) {
    return {
      texto: 'Por ahora no hay cursos publicados. Puedo contarte sobre el servicio de asesoramiento, o puedes escribirnos por WhatsApp.',
      opciones: [ASESORAMIENTO],
      conWhatsapp: true,
    };
  }

  // Primero los que vencen antes: son los que conviene decidir pronto. Sin fecha, al final y sin alterar el orden.
  const ordenados = cursos
    .map((curso, indice) => ({ curso, indice }))
    .sort((a, b) => {
      const fa = a.curso.vigente_hasta ?? '';
      const fb = b.curso.vigente_hasta ?? '';
      if (fa === fb) return a.indice - b.indice;
      if (fa === '') return 1;
      if (fb === '') return -1;
      return fa < fb ? -1 : 1;
    })
    .map((x) => x.curso);

  const opciones: OpcionOrientador[] = ordenados.slice(0, MAX_CURSOS_EN_MENU).map((curso) => ({
    label: etiquetaCurso(curso),
    query: `Quiero información del curso ${curso.titulo}`,
  }));
  if (cursos.length >= 2) opciones.push(COMPARAR);
  opciones.push(ASESORAMIENTO);

  const hayMas = cursos.length > MAX_CURSOS_EN_MENU;
  return {
    texto:
      cursos.length === 1
        ? '¡Con gusto te ayudo! Este es el curso disponible por ahora:'
        : `¡Con gusto te ayudo a elegir! ${hayMas ? 'Estos son algunos de los cursos disponibles; también puedo compararlos todos.' : 'Selecciona el curso que te interesa:'}`,
    opciones,
    conWhatsapp: false,
  };
}
