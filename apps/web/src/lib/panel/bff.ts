import 'server-only';

import { NextResponse, type NextRequest } from 'next/server';
import { COOKIE_NAVEGADOR, COOKIE_SESION, type CookieSesionLaravel, type ErrorApi } from './laravel';
import { MAX_IMAGEN, MAX_PLANILLA, type Operacion } from './operaciones-panel';

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

const MAX_TEXTO = 4000;
/** Imagen + campos de texto + márgenes de la codificación multipart. */
const MAX_MULTIPART = MAX_IMAGEN + 64 * 1024;
const MAX_MULTIPART_PLANILLA = MAX_PLANILLA + 64 * 1024;

/** Tipo real de la imagen por sus primeros bytes (JPEG, PNG o WebP); `null` si no es ninguno. */
export function tipoDeImagen(bytes: Uint8Array): { tipo: string; extension: string } | null {
  const empieza = (...firma: number[]) => firma.every((valor, indice) => bytes[indice] === valor);
  if (empieza(0xff, 0xd8, 0xff)) return { tipo: 'image/jpeg', extension: 'jpg' };
  if (empieza(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a)) return { tipo: 'image/png', extension: 'png' };
  if (empieza(0x52, 0x49, 0x46, 0x46) && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50) return { tipo: 'image/webp', extension: 'webp' };
  return null;
}

/** ¿Empieza con la firma de un PDF (`%PDF-`)? Solo se usa donde la operación admite PDF; Laravel revisa el contenido. */
export function esPdf(bytes: Uint8Array): boolean {
  return [0x25, 0x50, 0x44, 0x46, 0x2d].every((valor, indice) => bytes[indice] === valor);
}

/** ¿Empieza con la firma de un ZIP (bytes 50 4B 03 04), contenedor de todo .xlsx? Laravel revisa la estructura y el contenido. */
export function esZip(bytes: Uint8Array): boolean {
  return [0x50, 0x4b, 0x03, 0x04].every((valor, indice) => bytes[indice] === valor);
}

/** Lee el cuerpo con un tope duro de bytes, sin fiarse de `Content-Length`. `null` = se excedió el tope. */
async function leerAcotado(request: NextRequest, maximo: number): Promise<Uint8Array | null> {
  const lector = request.body?.getReader();
  if (!lector) return new Uint8Array();
  const trozos: Uint8Array[] = [];
  let total = 0;
  for (;;) {
    const { done, value } = await lector.read();
    if (done) break;
    total += value.byteLength;
    if (total > maximo) {
      await lector.cancel();
      return null;
    }
    trozos.push(value);
  }
  const unido = new Uint8Array(total);
  let posicion = 0;
  for (const trozo of trozos) {
    unido.set(trozo, posicion);
    posicion += trozo.byteLength;
  }
  return unido;
}

/**
 * Valida y reconstruye un `multipart/form-data` para Laravel. El navegador solo puede enviar mismo origen, el tipo
 * multipart, un cuerpo de tamaño acotado, los campos de texto declarados por la operación y, como mucho, una imagen
 * llamada `imagen` cuyo contenido real (firma de bytes) sea JPEG, PNG o WebP. La imagen se reenvía con un nombre
 * fijo: el nombre original del cliente nunca llega a Laravel. Laravel sigue siendo la autoridad (tipo, dimensiones,
 * tamaño, permisos y revisión); esta capa evita transportar lo que no puede ser válido.
 */
export async function leerMultipart(request: NextRequest, operacion: Operacion): Promise<FormData | NextResponse> {
  if (!origenPermitido(request)) {
    return errorJson(403, { message: 'Solicitud no permitida.', code: 'origen_no_permitido' });
  }
  const tipoContenido = request.headers.get('content-type') ?? '';
  if (!tipoContenido.toLowerCase().startsWith('multipart/form-data')) {
    return errorJson(415, { message: 'Tipo de contenido no admitido.', code: 'tipo_no_admitido' });
  }
  const longitud = Number(request.headers.get('content-length'));
  if (!Number.isFinite(longitud) || longitud <= 0) {
    return errorJson(411, { message: 'Indica el tamaño del contenido.', code: 'longitud_requerida' });
  }
  const planilla = operacion.planilla === true;
  const tope = planilla ? MAX_MULTIPART_PLANILLA : MAX_MULTIPART;
  const demasiado = () => errorJson(413, { message: planilla ? 'La planilla supera el tamaño máximo de 1 MB.' : 'La imagen supera el tamaño máximo de 3 MB.', code: 'contenido_demasiado_grande' });
  if (longitud > tope) return demasiado();

  const bytes = await leerAcotado(request, tope);
  if (bytes === null) return demasiado();

  let entrada: FormData;
  try {
    entrada = await new Response(bytes as BodyInit, { headers: { 'content-type': tipoContenido } }).formData();
  } catch {
    return errorJson(400, { message: 'La solicitud no es válida.', code: 'solicitud_invalida' });
  }

  const salida = new FormData();
  for (const nombre of operacion.multipart ?? []) {
    const valor = entrada.get(nombre);
    if (typeof valor === 'string') salida.append(nombre, valor.slice(0, MAX_TEXTO));
  }

  if (planilla) {
    const subidos = entrada.getAll('archivo');
    const unico = subidos.length === 1 ? subidos[0] : null;
    if (unico === null || typeof unico === 'string') {
      return errorJson(422, { message: 'Adjunta la planilla Excel.', code: 'validacion', errors: { archivo: ['Adjunta una sola planilla Excel (.xlsx).'] } });
    }
    if (unico.size > MAX_PLANILLA) return demasiado();
    if (!esZip(new Uint8Array(await unico.slice(0, 4).arrayBuffer()))) {
      return errorJson(415, { message: 'Formato de archivo no permitido.', code: 'tipo_no_admitido', errors: { archivo: ['Solo se admite una planilla Excel (.xlsx).'] } });
    }
    salida.append('archivo', new Blob([await unico.arrayBuffer()], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), 'planilla.xlsx');
    return salida;
  }

  const archivos = entrada.getAll('imagen');
  if (archivos.length > 1) {
    return errorJson(422, { message: 'Envía una sola imagen.', code: 'validacion', errors: { imagen: ['Envía una sola imagen.'] } });
  }
  if (archivos.length === 1) {
    const archivo = archivos[0];
    if (typeof archivo === 'string') {
      return errorJson(422, { message: 'La imagen no es válida.', code: 'validacion', errors: { imagen: ['El archivo de imagen no es válido.'] } });
    }
    if (archivo.size > MAX_IMAGEN) {
      return errorJson(413, { message: 'La imagen supera el tamaño máximo de 3 MB.', code: 'contenido_demasiado_grande', errors: { imagen: ['La imagen no puede superar los 3 MB.'] } });
    }
    const primeros = new Uint8Array(await archivo.slice(0, 16).arrayBuffer());
    const detectado = tipoDeImagen(primeros) ?? (operacion.pdf === true && esPdf(primeros) ? { tipo: 'application/pdf', extension: 'pdf' } : null);
    if (detectado === null) {
      const permitido = operacion.pdf === true ? 'Solo se admiten imágenes JPG, PNG o WebP, o un PDF.' : 'Solo se admiten imágenes JPG, PNG o WebP.';
      return errorJson(415, { message: 'Formato de archivo no permitido.', code: 'tipo_no_admitido', errors: { imagen: [permitido] } });
    }
    salida.append('imagen', new Blob([await archivo.arrayBuffer()], { type: detectado.tipo }), `imagen.${detectado.extension}`);
  }
  return salida;
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
/** `Secure` en producción y siempre con el prefijo `__Host-` (que el navegador solo acepta con `Secure`). */
const COOKIE_SEGURA = process.env.NODE_ENV === 'production' || COOKIE_NAVEGADOR.startsWith('__Host-');

export function aplicarCookieSesion(respuesta: NextResponse, cookie: CookieSesionLaravel | null): NextResponse {
  if (cookie === null) return respuesta;
  const maxAge = cookie.maxAge ?? (cookie.expiraEn === undefined ? undefined : Math.max(0, Math.floor((cookie.expiraEn - Date.now()) / 1000)));
  if (maxAge === 0) return borrarCookieSesion(respuesta);

  respuesta.cookies.set({
    name: COOKIE_NAVEGADOR,
    value: cookie.valor,
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    secure: COOKIE_SEGURA,
    ...(maxAge === undefined ? {} : { maxAge }),
  });
  return respuesta;
}

export function borrarCookieSesion(respuesta: NextResponse): NextResponse {
  // Sesiones anteriores al prefijo `__Host-` guardaban la cookie con el nombre de Laravel: se retira también.
  if (COOKIE_SESION !== COOKIE_NAVEGADOR) respuesta.cookies.set({ name: COOKIE_SESION, value: '', httpOnly: true, sameSite: 'lax', path: '/', maxAge: 0 });
  respuesta.cookies.set({
    name: COOKIE_NAVEGADOR,
    value: '',
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    secure: COOKIE_SEGURA,
    maxAge: 0,
  });
  return respuesta;
}

export function jsonSinCache(datos: unknown, estado = 200): NextResponse {
  return NextResponse.json(datos, { status: estado, headers: { 'Cache-Control': 'no-store, private' } });
}
