/**
 * Llamadas del navegador al BFF (`/api/bff/*`). Siempre JSON y mismo origen; nunca a Laravel directamente.
 */
import type { ErrorPanel } from './tipos';

export type ResultadoBff<T> = { ok: true; estado: number; datos: T } | { ok: false; estado: number; error: ErrorPanel };

const SIN_CONEXION: ErrorPanel = {
  message: 'No se pudo conectar. Revisa tu conexión e inténtalo de nuevo.',
  code: 'sin_conexion',
};

export async function llamarBff<T>(
  ruta: `/api/bff/${string}`,
  opciones: { metodo?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'; cuerpo?: unknown; csrf?: string } = {},
): Promise<ResultadoBff<T>> {
  const metodo = opciones.metodo ?? (opciones.cuerpo === undefined ? 'GET' : 'POST');
  const cabeceras: Record<string, string> = { Accept: 'application/json' };
  if (metodo !== 'GET') cabeceras['Content-Type'] = 'application/json';
  if (opciones.csrf) cabeceras['X-CSRF-TOKEN'] = opciones.csrf;

  let respuesta: Response;
  try {
    respuesta = await fetch(ruta, {
      method: metodo,
      headers: cabeceras,
      body: metodo === 'GET' ? undefined : JSON.stringify(opciones.cuerpo ?? {}),
      credentials: 'same-origin',
      cache: 'no-store',
    });
  } catch {
    return { ok: false, estado: 0, error: SIN_CONEXION };
  }

  let cuerpo: unknown = null;
  try {
    cuerpo = await respuesta.json();
  } catch {
    cuerpo = null;
  }

  if (respuesta.ok) return { ok: true, estado: respuesta.status, datos: cuerpo as T };

  const error = typeof cuerpo === 'object' && cuerpo !== null && typeof (cuerpo as ErrorPanel).message === 'string'
    ? (cuerpo as ErrorPanel)
    : { message: 'No se pudo completar la solicitud.', code: 'error' };
  return { ok: false, estado: respuesta.status, error };
}

/** Primer mensaje de validación de un campo, si lo hay. */
export function errorDeCampo(error: ErrorPanel | null, campo: string): string | undefined {
  return error?.errors?.[campo]?.[0];
}
