/**
 * Reglas de ayuda en el navegador para importes y comprobantes de pago. Laravel vuelve a validar todo (tipo real
 * del archivo, tamaño, importe): esto solo evita viajes inútiles y da mensajes claros.
 *
 * Módulo puro (sin React): lo comparten «Subir comprobante», «Registrar pago» y «Matricular con pago».
 */

/** Importe en soles: hasta 8 enteros y 2 decimales, con punto. */
export const IMPORTE = /^(0|[1-9]\d{0,7})(\.\d{1,2})?$/;

export const MAX_CAPTURA = 3 * 1024 * 1024;
export const TIPOS_CAPTURA = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'] as const;
/** Valor para el atributo `accept` del campo de archivo. */
export const ACEPTA_CAPTURA = TIPOS_CAPTURA.join(',');

/**
 * Acepta la coma decimal que se escribe en español (`25,50`) y la convierte en punto antes de validar.
 * Quita espacios en los extremos. No intenta interpretar separadores de miles: `1,250.50` seguirá siendo inválido.
 */
export function normalizarImporte(texto: string): string {
  return texto.trim().replace(',', '.');
}

/** `true` si el texto, ya normalizado, es un importe válido y mayor que cero. */
export function importeValido(texto: string): boolean {
  const normalizado = normalizarImporte(texto);
  return IMPORTE.test(normalizado) && Number(normalizado) > 0;
}

/**
 * Valida el archivo del comprobante. `obligatorio` (por omisión) exige que exista; si no, un archivo ausente es válido.
 * Devuelve el mensaje de error o `null`.
 */
export function validarCaptura(archivo: { type: string; size: number } | null, obligatorio = true): string | null {
  if (archivo === null) return obligatorio ? 'Adjunta el comprobante de pago.' : null;
  if (!(TIPOS_CAPTURA as readonly string[]).includes(archivo.type)) return 'Sube una captura en JPG, PNG o WebP, o un PDF.';
  if (archivo.size > MAX_CAPTURA) return 'El comprobante no puede pasar de 3 MB.';
  return null;
}

/** «1,5 MB», «820 KB»: tamaño legible de un archivo. */
export function tamanoLegible(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
  return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
