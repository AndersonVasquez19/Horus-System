import 'server-only';

import { cookies, headers } from 'next/headers';
import { cache } from 'react';
import { COOKIE_NAVEGADOR, llamarLaravel } from './laravel';
import { ipDelCliente } from './red';
import type { SesionPanel } from './tipos';

export type EstadoSesion =
  | { estado: 'autenticado'; sesion: SesionPanel }
  | { estado: 'anonimo' }
  | { estado: 'no_disponible' };

/**
 * Capa de acceso a la sesión (lado servidor). Laravel valida la cookie en cada lectura: Next nunca decide la
 * autorización por sí mismo. Lee `cookies()`, así que debe llamarse dentro de un `<Suspense>` (Cache Components).
 * Sin caché: los permisos cambian en cuanto un administrador los modifica.
 */
export const obtenerSesion = cache(async (): Promise<EstadoSesion> => {
  const almacen = await cookies();
  const cookieSesion = almacen.get(COOKIE_NAVEGADOR)?.value;
  if (!cookieSesion) return { estado: 'anonimo' };

  const respuesta = await llamarLaravel<{ data: SesionPanel }>('auth/sesion', {
    cookieSesion,
    ipCliente: ipDelCliente(await headers()),
  });

  if (respuesta.ok) return { estado: 'autenticado', sesion: respuesta.datos.data };
  if (respuesta.estado === 401) return { estado: 'anonimo' };
  return { estado: 'no_disponible' };
});

/** Lee la cookie de sesión en un route handler. */
export async function cookieSesionActual(): Promise<string | null> {
  return (await cookies()).get(COOKIE_NAVEGADOR)?.value ?? null;
}
