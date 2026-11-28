/**
 * Teléfono de los formularios públicos. Laravel exige 9 dígitos: aquí se acepta cómo la gente los escribe (espacios,
 * guiones, prefijo +51) y nunca se recorta un número para que pase, porque guardaría uno equivocado.
 */

/** Deja escribir solo lo que puede formar parte de un teléfono. No recorta. */
export function filtrarTelefono(valor: string): string {
  return valor.replace(/[^\d+\s-]/g, '');
}

/** Los 9 dígitos que se envían, o `null` si lo escrito no es exactamente eso (con o sin +51). */
export function normalizarTelefono(valor: string): string | null {
  const numero = valor.replace(/[\s-]/g, '').replace(/^\+51/, '');
  return /^\d{9}$/.test(numero) ? numero : null;
}

/** Longitud máxima del campo: holgada para el prefijo y los separadores. */
export const MAX_TELEFONO_ESCRITO = 20;

export const ERROR_TELEFONO = 'Escribe un teléfono de 9 dígitos (puedes anteponer +51).';
