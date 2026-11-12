import 'server-only';

import { cookies, headers } from 'next/headers';
import { redirect } from 'next/navigation';
import { COOKIE_SESION, llamarLaravel } from './laravel';
import { ipDelCliente } from './red';
import { urlLogin } from './redireccion';

export type ResultadoConsulta<T> =
  | { estado: 'ok'; datos: T }
  | { estado: 'prohibido' }
  | { estado: 'no_encontrado' }
  | { estado: 'error'; mensaje: string };

/**
 * Lectura de la API del panel desde Server Components. Laravel comprueba sesión y permisos en cada llamada;
 * aquí solo se traduce el resultado: sin sesión, al acceso (volviendo a `volverA`); sin permiso o inexistente,
 * un estado que la página muestra. Lee cookies: debe ejecutarse dentro de un `<Suspense>`.
 */
export async function consultarPanel<T>(ruta: string, volverA: string): Promise<ResultadoConsulta<T>> {
  const cookieSesion = (await cookies()).get(COOKIE_SESION)?.value;
  if (!cookieSesion) redirect(urlLogin(volverA));

  const respuesta = await llamarLaravel<T>(ruta, { cookieSesion, ipCliente: ipDelCliente(await headers()) });
  if (respuesta.ok) return { estado: 'ok', datos: respuesta.datos };

  switch (respuesta.estado) {
    case 401:
      redirect(urlLogin(volverA));
    case 403:
      return { estado: 'prohibido' };
    case 404:
      return { estado: 'no_encontrado' };
    default:
      return { estado: 'error', mensaje: respuesta.error.message };
  }
}

/** Construye la cadena de consulta con solo los parámetros permitidos y no vacíos. */
export function consulta(parametros: Record<string, string | string[] | undefined>, permitidos: readonly string[]): string {
  const busqueda = new URLSearchParams();
  for (const nombre of permitidos) {
    const valor = parametros[nombre];
    const texto = Array.isArray(valor) ? valor[0] : valor;
    if (typeof texto === 'string' && texto.trim() !== '') busqueda.set(nombre, texto.trim().slice(0, 200));
  }
  const texto = busqueda.toString();
  return texto === '' ? '' : `?${texto}`;
}
