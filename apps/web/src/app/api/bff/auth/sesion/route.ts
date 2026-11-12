import type { NextRequest } from 'next/server';
import { aplicarCookieSesion, borrarCookieSesion, errorJson, jsonSinCache } from '@/lib/panel/bff';
import { COOKIE_SESION, llamarLaravel } from '@/lib/panel/laravel';
import { ipDelCliente } from '@/lib/panel/red';
import type { SesionPanel } from '@/lib/panel/tipos';

/**
 * Sesión actual. También renueva la caducidad de la cookie del navegador (Laravel la desliza en cada petición,
 * pero los Server Components no pueden reescribir cookies): el panel la consulta tras actividad del usuario.
 */
export async function GET(request: NextRequest) {
  if (request.headers.get('sec-fetch-site') === 'cross-site') {
    return errorJson(403, { message: 'Solicitud no permitida.', code: 'origen_no_permitido' });
  }

  const cookieSesion = request.cookies.get(COOKIE_SESION)?.value;
  if (!cookieSesion) return errorJson(401, { message: 'Inicia sesión para continuar.', code: 'no_autenticado' });

  const resultado = await llamarLaravel<{ data: SesionPanel }>('auth/sesion', {
    cookieSesion,
    ipCliente: ipDelCliente(request.headers),
  });

  if (!resultado.ok) {
    const respuesta = errorJson(resultado.estado, resultado.error);
    return resultado.estado === 401 ? borrarCookieSesion(respuesta) : respuesta;
  }

  return aplicarCookieSesion(jsonSinCache(resultado.datos), resultado.cookie);
}
