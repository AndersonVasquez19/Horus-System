import 'server-only';

/**
 * Cliente servidor a servidor de la API de los paneles (`/api/panel/v1/*` de Laravel).
 *
 * - Solo se usa desde route handlers del BFF y Server Components (`server-only`).
 * - Base y ruta fijas: el navegador nunca elige el destino (sin proxy genérico ni SSRF).
 * - Reenvía únicamente la cookie de sesión de Laravel; nunca cabeceras `Authorization` ni otras cookies.
 * - El token de servicio y la cookie de sesión nunca se registran.
 */

const TIMEOUT_MS = 8000;

/** Nombre de la cookie de sesión de Laravel (SESSION_COOKIE). Por defecto, el de Laravel con APP_NAME=Laravel. */
export const COOKIE_SESION = process.env.HORUS_SESSION_COOKIE?.trim() || 'laravel-session';

export interface ErrorApi {
  message: string;
  code: string;
  errors?: Record<string, string[]>;
}

export interface CookieSesionLaravel {
  valor: string;
  /** Segundos de vida (Max-Age); 0 indica que Laravel la eliminó. */
  maxAge: number | undefined;
  /**
   * Instante de caducidad (Expires) en milisegundos. Se convierte a segundos solo al reescribir la cookie en un
   * route handler: leer la hora actual durante el render de un Server Component no está permitido.
   */
  expiraEn: number | undefined;
}

export type RespuestaLaravel<T> =
  | { ok: true; estado: number; datos: T; cookie: CookieSesionLaravel | null }
  | { ok: false; estado: number; error: ErrorApi; cookie: CookieSesionLaravel | null };

export interface OpcionesLlamada {
  metodo?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  cuerpo?: unknown;
  /** Cuerpo multipart ya validado por el BFF (excluye `cuerpo`). */
  formulario?: FormData;
  cookieSesion?: string | null;
  csrf?: string | null;
  ipCliente?: string | null;
}

const NO_DISPONIBLE: ErrorApi = {
  message: 'El servicio no está disponible en este momento. Inténtalo más tarde.',
  code: 'no_disponible',
};

function configuracion(): { base: string; token: string } | null {
  const base = (process.env.HORUS_API_URL ?? '').replace(/\/+$/, '');
  const token = process.env.HORUS_WEB_SERVICE_TOKEN ?? '';
  if (!/^https?:\/\/[^\s/]+/i.test(base) || token.length < 32) return null;
  return { base, token };
}

/** Extrae la cookie de sesión de las cabeceras Set-Cookie de Laravel. */
function leerCookieSesion(respuesta: Response): CookieSesionLaravel | null {
  for (const linea of respuesta.headers.getSetCookie()) {
    const [par, ...atributos] = linea.split(';');
    const separador = par.indexOf('=');
    if (separador < 0 || par.slice(0, separador).trim() !== COOKIE_SESION) continue;

    const valor = par.slice(separador + 1).trim();
    let maxAge: number | undefined;
    let expiraEn: number | undefined;
    for (const atributo of atributos) {
      const [clave, ...resto] = atributo.trim().split('=');
      const dato = resto.join('=');
      if (clave.toLowerCase() === 'max-age') maxAge = Math.max(0, Number.parseInt(dato, 10) || 0);
      if (clave.toLowerCase() === 'expires') {
        const fecha = Date.parse(dato);
        if (!Number.isNaN(fecha)) expiraEn = fecha;
      }
    }
    if (valor === '' || valor === 'deleted') maxAge = 0;
    return { valor, maxAge, expiraEn };
  }
  return null;
}

/** La ruta debe ser relativa a /api/panel/v1, sin esquema, host, `..` ni barras dobles. */
function rutaSegura(ruta: string): string {
  if (!/^[a-z0-9][a-z0-9/_-]*(\?[a-zA-Z0-9_\-\[\]=&%.,:+]*)?$/.test(ruta) || ruta.includes('//') || ruta.includes('..')) {
    throw new Error('Ruta de la API del panel no válida.');
  }
  return ruta;
}

/** Petición común: base y token del servidor, ruta validada, sin seguir redirecciones. `null` = no disponible. */
async function peticion(ruta: string, opciones: OpcionesLlamada, timeout: number): Promise<Response | null> {
  const cfg = configuracion();
  if (cfg === null) return null;

  const cabeceras: Record<string, string> = {
    Accept: 'application/json',
    'X-Horus-Web-Token': cfg.token,
  };
  // Con `formulario`, fetch fija el Content-Type multipart con su límite (boundary).
  if (opciones.cuerpo !== undefined && opciones.formulario === undefined) cabeceras['Content-Type'] = 'application/json';
  if (opciones.cookieSesion) cabeceras.Cookie = `${COOKIE_SESION}=${opciones.cookieSesion}`;
  if (opciones.csrf) cabeceras['X-CSRF-TOKEN'] = opciones.csrf;
  if (opciones.ipCliente) cabeceras['X-Horus-Client-Ip'] = opciones.ipCliente;

  try {
    return await fetch(`${cfg.base}/api/panel/v1/${rutaSegura(ruta)}`, {
      method: opciones.metodo ?? 'GET',
      headers: cabeceras,
      body: opciones.formulario ?? (opciones.cuerpo === undefined ? undefined : JSON.stringify(opciones.cuerpo)),
      cache: 'no-store',
      redirect: 'manual',
      signal: AbortSignal.timeout(timeout),
    });
  } catch {
    return null;
  }
}

export type ArchivoLaravel =
  | { ok: true; contenido: ArrayBuffer; tipo: string; nombre: string; cookie: CookieSesionLaravel | null }
  | { ok: false; estado: number; error: ErrorApi; cookie: CookieSesionLaravel | null };

/**
 * Descarga de un archivo generado por Laravel (exportaciones). Solo se aceptan los tipos indicados; el nombre se
 * reduce a caracteres seguros para la cabecera Content-Disposition que escribirá el BFF.
 */
export async function descargarDeLaravel(ruta: string, tiposPermitidos: readonly string[], opciones: OpcionesLlamada = {}): Promise<ArchivoLaravel> {
  const respuesta = await peticion(ruta, opciones, 30_000);
  if (respuesta === null) return { ok: false, estado: 503, error: NO_DISPONIBLE, cookie: null };

  const cookie = leerCookieSesion(respuesta);
  const tipo = (respuesta.headers.get('content-type') ?? '').split(';')[0].trim().toLowerCase();

  if (!respuesta.ok || !tiposPermitidos.includes(tipo)) {
    let cuerpo: unknown = null;
    try {
      cuerpo = await respuesta.json();
    } catch {
      cuerpo = null;
    }
    const estado = respuesta.ok || (respuesta.status >= 300 && respuesta.status < 400) ? 502 : respuesta.status;
    return { ok: false, estado, error: esErrorApi(cuerpo) ? cuerpo : { ...NO_DISPONIBLE, code: 'error' }, cookie };
  }

  const disposicion = respuesta.headers.get('content-disposition') ?? '';
  const nombre = /filename="?([A-Za-z0-9._-]{1,100})"?/.exec(disposicion)?.[1] ?? 'exportacion';
  return { ok: true, contenido: await respuesta.arrayBuffer(), tipo, nombre, cookie };
}

export async function llamarLaravel<T>(ruta: string, opciones: OpcionesLlamada = {}): Promise<RespuestaLaravel<T>> {
  const respuesta = await peticion(ruta, opciones, opciones.formulario ? 30_000 : TIMEOUT_MS);
  if (respuesta === null) return { ok: false, estado: 503, error: NO_DISPONIBLE, cookie: null };

  const cookie = leerCookieSesion(respuesta);
  let cuerpo: unknown = null;
  try {
    cuerpo = await respuesta.json();
  } catch {
    cuerpo = null;
  }

  if (respuesta.ok) return { ok: true, estado: respuesta.status, datos: cuerpo as T, cookie };

  const error = esErrorApi(cuerpo) ? cuerpo : { ...NO_DISPONIBLE, code: respuesta.status >= 500 ? 'error_interno' : 'error' };
  // Una respuesta 3xx o sin JSON es un fallo de configuración del backend, no un dato para el navegador.
  return { ok: false, estado: respuesta.status >= 300 && respuesta.status < 400 ? 502 : respuesta.status, error, cookie };
}

function esErrorApi(valor: unknown): valor is ErrorApi {
  return typeof valor === 'object' && valor !== null
    && typeof (valor as ErrorApi).message === 'string' && typeof (valor as ErrorApi).code === 'string';
}
