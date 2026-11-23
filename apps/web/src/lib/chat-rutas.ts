/**
 * Enlaces permitidos del chat. Laravel devuelve rutas del sitio anterior (`/preguntas-frecuentes`,
 * `/politicas-devolucion`…); aquí se traducen a las rutas de Next y se descarta cualquier otra. Es la única lista
 * blanca: ni el texto del modelo ni el navegador pueden introducir destinos nuevos.
 */

import { TECNOLOGIAS_VISIBLES } from '@/lib/funciones';

const RUTAS_NEXT: Record<string, string> = {
  '/educacion/cursos': '/educacion/cursos',
  '/educacion/capacitaciones': '/educacion/capacitaciones',
  '/educacion/asesoramiento': '/educacion/asesoramiento',
  '/contactos': '/contactos',
  '/quienes-somos': '/quienes-somos',
  '/galeria': '/galeria',
  '/preguntas-frecuentes': '/legales/preguntas-frecuentes',
  '/politicas-devolucion': '/legales/politicas-devolucion',
  '/politicas-privacidad': '/legales/politicas-privacidad',
  '/politicas-cookies': '/legales/politicas-cookies',
  '/libro-reclamaciones': '/legales/libro-reclamaciones',
  '/legales/preguntas-frecuentes': '/legales/preguntas-frecuentes',
  '/legales/politicas-devolucion': '/legales/politicas-devolucion',
  '/legales/politicas-privacidad': '/legales/politicas-privacidad',
  '/legales/politicas-cookies': '/legales/politicas-cookies',
  '/legales/libro-reclamaciones': '/legales/libro-reclamaciones',
  // Rutas de «Tecnologías»: solo se permiten si el área está visible (`@/lib/funciones`).
  ...(TECNOLOGIAS_VISIBLES
    ? {
        '/solicitar-cotizacion': '/tecnologias/solicitar-cotizacion',
        '/tecnologias/camaras-seguridad': '/tecnologias/camaras-seguridad',
        '/tecnologias/cableado-estructurado': '/tecnologias/cableado-estructurado',
        '/tecnologias/soporte-mantenimiento': '/tecnologias/soporte-mantenimiento',
      }
    : {}),
};

const ETIQUETAS: Record<string, string> = {
  '/contactos': 'Contactar con Horus',
  '/educacion/cursos': 'Ver cursos',
  '/educacion/capacitaciones': 'Ver capacitaciones',
  '/educacion/asesoramiento': 'Ver asesoramiento',
  '/galeria': 'Ver galería',
  '/preguntas-frecuentes': 'Preguntas frecuentes',
  '/legales/preguntas-frecuentes': 'Preguntas frecuentes',
  '/legales/politicas-devolucion': 'Política de devoluciones',
  '/legales/politicas-privacidad': 'Política de privacidad',
  '/legales/politicas-cookies': 'Política de cookies',
  '/legales/libro-reclamaciones': 'Libro de Reclamaciones',
  '/quienes-somos': 'Quiénes somos',
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

/** Ruta de Next si ya es una ruta permitida del sitio actual o una ruta del sitio anterior de la lista blanca; si no, `null`. */
export function rutaPermitida(ruta: unknown): string | null {
  if (typeof ruta !== 'string') return null;
  if (Object.values(RUTAS_NEXT).includes(ruta)) return ruta;
  return rutaNext(ruta);
}

/**
 * Botones de la respuesta: las secciones del sitio vienen de datos estructurados validados en el servidor (nunca de rutas
 * escritas en el texto) y el de WhatsApp aparece si la respuesta menciona el canal oficial.
 */
export function enlacesDeRespuesta(texto: string, rutas: readonly unknown[] = []): EnlaceChat[] {
  const enlaces: EnlaceChat[] = [];
  const vistas = new Set<string>();
  for (const ruta of rutas.slice(0, 3)) {
    const url = rutaPermitida(ruta);
    if (url === null || vistas.has(url)) continue;
    vistas.add(url);
    enlaces.push({ url, label: ETIQUETAS[url] ?? 'Ver más información', whatsapp: false });
  }
  if (/(?:^|[^\d+])\+51[\s-]*972[\s-]*021[\s-]*374(?!\d)/.test(texto) || texto.includes(WHATSAPP)) {
    enlaces.push({ url: WHATSAPP, label: 'Contactar por WhatsApp', whatsapp: true });
  }
  return enlaces;
}
