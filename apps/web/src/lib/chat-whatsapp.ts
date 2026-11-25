/**
 * Mensaje que aparece ya escrito al abrir WhatsApp desde el chat, y presentación del enlace en el panel. Sin imports de
 * servidor, para probarlo con `node --test`. Los textos son fijos y, como mucho, llevan el nombre público de un curso:
 * nunca lo que escribió el visitante. La persona puede cambiar el mensaje antes de enviarlo.
 */

export const WHATSAPP_BASE = 'https://wa.me/51972021374';

/** Frases de las respuestas oficiales (las mismas que usa Laravel en `SenalesAsistente`; una prueba lo comprueba). */
export const FRASES = {
  reclamos: ['Libro de Reclamaciones virtual'],
  matricula: ['Para matricularte sigue estos pasos', 'Para consultar la matrícula', 'coordinar tu matrícula'],
} as const;

const SALUDO = 'Hola Horus Group, consulté el asistente web y';

export function mensajeWhatsapp(respuesta: string, curso: string | null): string {
  const incluye = (frases: readonly string[]) => frases.some((frase) => respuesta.includes(frase));
  // El reclamo va primero: aunque haya un curso en foco, no se debe escribir un mensaje de matrícula.
  if (incluye(FRASES.reclamos)) return `${SALUDO} necesito ayuda con un reclamo.`;
  if (incluye(FRASES.matricula)) {
    const cual = curso ? ` en el curso «${curso}»` : '';
    return `${SALUDO} deseo matricularme${cual}. ¿Me confirman vacante, tarifa e inicio?`;
  }
  return `${SALUDO} deseo más información${curso ? ` sobre el curso «${curso}»` : ''}.`;
}

export function urlWhatsappChat(respuesta: string, curso: string | null): string {
  return `${WHATSAPP_BASE}?text=${encodeURIComponent(mensajeWhatsapp(respuesta, curso))}`;
}

/** El enlace con su mensaje codificado es largo e ilegible en el panel; la puntuación que le sigue se conserva. */
export function ocultarEnlaceWhatsapp(texto: string): string {
  return texto.replace(/https:\/\/wa\.me\/[^\s…]*[^\s….,;:)]/g, 'enlace de WhatsApp');
}
