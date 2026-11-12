/**
 * Enlaces permitidos del chat. Laravel devuelve rutas del sitio anterior (`/preguntas-frecuentes`,
 * `/politicas-devolucion`…); aquí se traducen a las rutas de Next y se descarta cualquier otra. Es la única lista
 * blanca: ni el texto del modelo ni el navegador pueden introducir destinos nuevos.
 */

const RUTAS_NEXT: Record<string, string> = {
  '/educacion/cursos': '/educacion/cursos',
  '/educacion/capacitaciones': '/educacion/capacitaciones',
  '/contactos': '/contactos',
  '/quienes-somos': '/quienes-somos',
  '/preguntas-frecuentes': '/legales/preguntas-frecuentes',
  '/politicas-devolucion': '/legales/politicas-devolucion',
  '/tecnologias/camaras-seguridad': '/tecnologias/camaras-seguridad',
  '/tecnologias/cableado-estructurado': '/tecnologias/cableado-estructurado',
  '/tecnologias/soporte-mantenimiento': '/tecnologias/soporte-mantenimiento',
};

const ETIQUETAS: Record<string, string> = {
  '/contactos': 'Contactar con Horus',
  '/educacion/cursos': 'Ver cursos',
  '/educacion/capacitaciones': 'Ver capacitaciones',
  '/preguntas-frecuentes': 'Preguntas frecuentes',
};

const WHATSAPP = 'https://wa.me/51972021374';

export interface EnlaceChat {
  url: string;
  label: string;
  whatsapp: boolean;
}

/** Ruta de Next para una ruta del sitio anterior, o `null` si no está en la lista blanca. */
export function rutaNext(ruta: unknown): string | null {
  return typeof ruta === 'string' && Object.hasOwn(RUTAS_NEXT, ruta) ? RUTAS_NEXT[ruta] : null;
}

/** Enlaces seguros que se pueden ofrecer según el texto de la respuesta (mismo criterio que el chat de Blade). */
export function enlacesDeTexto(texto: string): EnlaceChat[] {
  const enlaces: EnlaceChat[] = Object.keys(RUTAS_NEXT)
    .filter((ruta) => texto.includes(ruta))
    .map((ruta) => ({
      url: RUTAS_NEXT[ruta],
      label: ETIQUETAS[ruta] ?? 'Ver información del servicio',
      whatsapp: false,
    }));

  if (/(?:^|[^\d+])\+51[\s-]*972[\s-]*021[\s-]*374(?!\d)/.test(texto) || texto.includes(WHATSAPP)) {
    enlaces.push({ url: WHATSAPP, label: 'Contactar por WhatsApp', whatsapp: true });
  }
  return enlaces;
}
