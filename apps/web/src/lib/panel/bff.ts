import 'server-only';

import { NextResponse, type NextRequest } from 'next/server';
import { COOKIE_SESION, type CookieSesionLaravel, type ErrorApi } from './laravel';

/**
 * Utilidades de los route handlers de `/api/bff/*`.
 *
 * Protección CSRF del BFF (capa 1; la capa 2 es el token CSRF de la sesión de Laravel):
 *  - Mutaciones solo desde el mismo origen: `Sec-Fetch-Site: same-origin` o, si el navegador no lo envía,
 *    `Origin` igual al origen de la petición. Sin ninguna de las dos, se rechaza.
 *  - Solo `application/json`: un formulario de otro sitio no puede enviar ese tipo sin preflight CORS, y el BFF
 *    no responde a preflights.
 */

const MAX_CUERPO = 16 * 1024;

export function errorJson(estado: number, error: ErrorApi): NextResponse {
  return NextResponse.json(error, { status: estado, headers: { 'Cache-Control': 'no-store, private' } });
}

/**
 * Orígenes propios. En producción se fija con HORUS_WEB_ORIGIN (lista separada por comas, p. ej.
 * `https://horus.example`). Sin ella se deriva del host al que se dirigió el navegador: `nextUrl.origin` no
 * sirve porque Next lo calcula con su propio host de escucha. Una página ajena no puede falsificar `Origin`
 * ni `Host` en el navegador de la víctima, así que derivarlo no debilita la comprobación.
 */
function origenesPropios(request: NextRequest): Set<string> {
  const configurados = (process.env.HORUS_WEB_ORIGIN ?? '')
    .split(',')
    .map((valor) => valor.trim().replace(/\/+$/, ''))
    .filter(Boolean);
  if (configurados.length > 0) return new Set(configurados);

  const host = request.headers.get('host');
  const protocolo = request.headers.get('x-forwarded-proto')?.split(',')[0]?.trim() || request.nextUrl.protocol.replace(/:$/, '');
  return new Set(host ? [`${protocolo}://${host}`, request.nextUrl.origin] : [request.nextUrl.origin]);
}

export function origenPermitido(request: NextRequest): boolean {
  const origen = request.headers.get('origin');
  const sitio = request.headers.get('sec-fetch-site');

  // Si el navegador envía Origin, debe ser uno propio aunque Sec-Fetch-Site diga same-origin.
  if (origen !== null) return origenesPropios(request).has(origen);
  return sitio === 'same-origin';
}

/** Valida origen y tipo y devuelve el cuerpo JSON (objeto plano) o una respuesta de error. */
export async function leerMutacion(request: NextRequest): Promise<Record<string, unknown> | NextResponse> {
  if (!origenPermitido(request)) {
    return errorJson(403, { message: 'Solicitud no permitida.', code: 'origen_no_permitido' });
  }
  if (!(request.headers.get('content-type') ?? '').toLowerCase().startsWith('application/json')) {
    return errorJson(415, { message: 'Tipo de contenido no admitido.', code: 'tipo_no_admitido' });
  }

  const texto = await request.text();
  if (texto.length > MAX_CUERPO) {
    return errorJson(413, { message: 'El contenido enviado es demasiado grande.', code: 'contenido_demasiado_grande' });
  }
  if (texto.trim() === '') return {};
  try {
    const cuerpo: unknown = JSON.parse(texto);
    if (typeof cuerpo === 'object' && cuerpo !== null && !Array.isArray(cuerpo)) return cuerpo as Record<string, unknown>;
  } catch {
    // cae al error común
  }
  return errorJson(400, { message: 'La solicitud no es válida.', code: 'solicitud_invalida' });
}

/** Solo campos de texto esperados: el BFF no reenvía nada que no conozca. */
export function campos(cuerpo: Record<string, unknown>, nombres: readonly string[]): Record<string, string> {
  const resultado: Record<string, string> = {};
  for (const nombre of nombres) {
    const valor = cuerpo[nombre];
    if (typeof valor === 'string') resultado[nombre] = valor;
  }
  return resultado;
}

/**
 * Traslada al navegador la cookie de sesión emitida por Laravel con atributos fijados por el BFF: HttpOnly,
 * SameSite=Lax, Path=/, sin Domain (host-only) y Secure en producción, sea cual sea la configuración de Laravel.
 */
export function aplicarCookieSesion(respuesta: NextResponse, cookie: CookieSesionLaravel | null): NextResponse {
  if (cookie === null) return respuesta;
  const maxAge = cookie.maxAge ?? (cookie.expiraEn === undefined ? undefined : Math.max(0, Math.floor((cookie.expiraEn - Date.now()) / 1000)));
  if (maxAge === 0) return borrarCookieSesion(respuesta);

  respuesta.cookies.set({
    name: COOKIE_SESION,
    value: cookie.valor,
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    secure: process.env.NODE_ENV === 'production',
    ...(maxAge === undefined ? {} : { maxAge }),
  });
  return respuesta;
}

export function borrarCookieSesion(respuesta: NextResponse): NextResponse {
  respuesta.cookies.set({
    name: COOKIE_SESION,
    value: '',
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    secure: process.env.NODE_ENV === 'production',
    maxAge: 0,
  });
  return respuesta;
}

export function jsonSinCache(datos: unknown, estado = 200): NextResponse {
  return NextResponse.json(datos, { status: estado, headers: { 'Cache-Control': 'no-store, private' } });
}
