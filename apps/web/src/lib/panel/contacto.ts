/**
 * Utilidades de contacto directo (WhatsApp, correo y normalización telefónica para Perú) usadas en listados y fichas
 * del panel administrativo.
 */

export function normalizarTelefonoPeru(telefono: string | null | undefined): string | null {
  const digitos = (telefono ?? '').replace(/\D/g, '');
  if (digitos.length === 0) return null;

  const sinPrefijoCeros = digitos.startsWith('00') ? digitos.slice(2) : digitos;
  if (sinPrefijoCeros.length === 9 && sinPrefijoCeros.startsWith('9')) {
    return `51${sinPrefijoCeros}`;
  }
  if (sinPrefijoCeros.length === 11 && sinPrefijoCeros.startsWith('51')) {
    return sinPrefijoCeros;
  }
  // Solo móviles peruanos (9 dígitos que empiezan con 9, con o sin 51): un número ajeno no debe abrir un chat de WhatsApp.
  return null;
}

export function urlWhatsApp(telefono: string | null | undefined, mensaje?: string): string | null {
  const numero = normalizarTelefonoPeru(telefono);
  if (numero === null) return null;
  const texto = (mensaje ?? '').trim();
  return texto === '' ? `https://wa.me/${numero}` : `https://wa.me/${numero}?text=${encodeURIComponent(texto)}`;
}

const CORREO_SEGURO = /^[^\s@,;?&#<>"'()[\]\\]+@[^\s@,;?&#<>"'()[\]\\]+\.[^\s@,;?&#<>"'()[\]\\]+$/;

export function urlMailto(email: string | null | undefined, asunto?: string, cuerpo?: string): string | null {
  const correo = (email ?? '').trim();
  // Solo una dirección simple: nada de «?», «&», comas ni espacios que permitan añadir destinatarios o cabeceras (?bcc=…).
  if (!CORREO_SEGURO.test(correo)) return null;
  const partes: string[] = [];
  if (asunto && asunto.trim() !== '') partes.push(`subject=${encodeURIComponent(asunto.trim())}`);
  if (cuerpo && cuerpo.trim() !== '') partes.push(`body=${encodeURIComponent(cuerpo.trim())}`);
  return partes.length === 0 ? `mailto:${correo}` : `mailto:${correo}?${partes.join('&')}`;
}

export function mensajeEstudiante({ nombre }: { nombre: string }): string {
  return `Hola ${nombre}, te saludamos de Horus Group.`;
}

export function esEnlaceGrupoWhatsApp(url: string | null | undefined): boolean {
  const limpio = (url ?? '').trim();
  return /^https:\/\/chat\.whatsapp\.com\/[A-Za-z0-9]{10,120}$/.test(limpio);
}

export function mensajeBienvenida({
  nombre,
  curso,
  edicion,
  enlaceGrupo,
}: {
  nombre: string;
  curso: string;
  edicion?: string | null;
  enlaceGrupo?: string | null;
}): string {
  const sufijoEdicion = edicion ? ` (${edicion})` : '';
  const sufijoGrupo = esEnlaceGrupoWhatsApp(enlaceGrupo) ? ` Enlace al grupo de WhatsApp: ${enlaceGrupo!.trim()}` : '';
  return `Hola ${nombre}, bienvenido(a) a Horus Group. Confirmamos tu matrícula en ${curso}${sufijoEdicion}. Quedamos atentos ante cualquier consulta.${sufijoGrupo}`;
}

export function mensajeCobro({ nombre, concepto, saldo, edicion }: { nombre: string; concepto: string; saldo: string; edicion?: string | null }): string {
  const sufijoEdicion = edicion ? ` (${edicion})` : '';
  return `Hola ${nombre}, te saludamos de Horus Group respecto a «${concepto}»${sufijoEdicion}. Tienes un saldo pendiente de S/ ${saldo}. Por favor compártenos tu comprobante al realizar el pago.`;
}

export function mensajeRespuestaReclamacion({ nombre, numero }: { nombre: string; numero: string }): string {
  return `Hola ${nombre}, nos comunicamos de Horus Group en atención a tu hoja de reclamación N.º ${numero}.`;
}

export function mensajeRespuestaContacto({ nombre, asunto }: { nombre: string; asunto: string }): string {
  return `Hola ${nombre}, te saludamos de Horus Group en respuesta a tu mensaje sobre «${asunto}».`;
}
