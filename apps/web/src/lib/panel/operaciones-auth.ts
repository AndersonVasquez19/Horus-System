import 'server-only';

import type { NextRequest, NextResponse } from 'next/server';
import { aplicarCookieSesion, errorJson, jsonSinCache } from './bff';
import { COOKIE_NAVEGADOR, llamarLaravel, type CookieSesionLaravel } from './laravel';
import { ipDelCliente } from './red';

/**
 * Llama a una operación de `auth/*` que exige token CSRF: obtiene primero el token de la sesión (anónima o no)
 * y después ejecuta la operación con la misma cookie. La protección frente a otros sitios la aplica antes el BFF
 * (origen + JSON); el token de Laravel sigue validándose en el servidor.
 */
export async function operacionConCsrf(
  request: NextRequest,
  ruta: string,
  cuerpo: Record<string, string>,
): Promise<NextResponse> {
  const ipCliente = ipDelCliente(request.headers);
  let cookieSesion = request.cookies.get(COOKIE_NAVEGADOR)?.value ?? null;

  const csrf = await llamarLaravel<{ csrf_token: string }>('auth/csrf', { cookieSesion, ipCliente });
  if (!csrf.ok) return errorJson(csrf.estado, csrf.error);

  let ultimaCookie: CookieSesionLaravel | null = csrf.cookie;
  if (csrf.cookie !== null && csrf.cookie.maxAge !== 0) cookieSesion = csrf.cookie.valor;

  const resultado = await llamarLaravel<Record<string, unknown>>(ruta, {
    metodo: 'POST',
    cuerpo,
    cookieSesion,
    csrf: csrf.datos.csrf_token,
    ipCliente,
  });
  if (resultado.cookie !== null) ultimaCookie = resultado.cookie;

  const respuesta = resultado.ok
    ? jsonSinCache(resultado.datos, resultado.estado)
    : errorJson(resultado.estado, resultado.error);
  if (!resultado.ok && resultado.estado === 429) {
    respuesta.headers.set('Retry-After', '60');
  }

  return aplicarCookieSesion(respuesta, ultimaCookie);
}
