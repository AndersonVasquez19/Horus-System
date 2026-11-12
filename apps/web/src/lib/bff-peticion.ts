import 'server-only';
import { createHmac } from 'node:crypto';
import { NextResponse, type NextRequest } from 'next/server';
import { configuracionApi } from '@/lib/horus-api';

/**
 * Utilidades comunes de los route handlers que hacen de BFF (Fase 2c): comprobación de origen, identificador opaco del
 * visitante, límite en memoria y respuestas de error genéricas. El navegador nunca recibe detalles internos.
 */

/** Solo se acepta una petición cuyo `Origin` sea el mismo host que atiende Next (protección CSRF del BFF). */
export function mismoOrigen(request: NextRequest): boolean {
  const origen = request.headers.get('origin');
  const host = request.headers.get('x-forwarded-host') ?? request.headers.get('host');
  if (!origen || !host) return false;
  try {
    return new URL(origen).host === host;
  } catch {
    return false;
  }
}

/**
 * Identificador opaco del visitante: HMAC-SHA256 de su IP con el token de servicio, calculado SOLO en el servidor.
 * La IP en claro no sale de Next ni se registra; el HMAC no es una anonimización irreversible (quien conozca el token
 * puede comprobar una IP concreta). `X-Forwarded-For` solo es fiable si el proxy de despliegue lo sobrescribe.
 */
export function visitanteDe(request: NextRequest): string | null {
  const cfg = configuracionApi();
  if (cfg === null) return null;
  const ip = (request.headers.get('x-forwarded-for')?.split(',')[0] ?? request.headers.get('x-real-ip') ?? 'desconocida').trim().slice(0, 64);
  return createHmac('sha256', cfg.token).update(`visitante|${ip}`).digest('hex');
}

export function errorJson(estado: number, code: string, message: string, extra?: Record<string, unknown>, cabeceras?: Record<string, string>) {
  return NextResponse.json({ error: { code, message }, ...extra }, { status: estado, headers: { 'Cache-Control': 'no-store', ...cabeceras } });
}

/** Limitador de ventana deslizante en memoria (mejor esfuerzo, por proceso). Devuelve segundos de espera o null. */
export function crearLimitador(ventanaMs: number, maximo: number, maxClaves = 5000) {
  const visitas = new Map<string, number[]>();
  return (clave: string): number | null => {
    const ahora = Date.now();
    if (visitas.size > maxClaves) {
      for (const [k, marcas] of visitas) if (marcas.every((m) => ahora - m > ventanaMs)) visitas.delete(k);
      if (visitas.size > maxClaves) visitas.clear();
    }
    const recientes = (visitas.get(clave) ?? []).filter((m) => ahora - m < ventanaMs);
    if (recientes.length >= maximo) {
      visitas.set(clave, recientes);
      return Math.max(1, Math.ceil((ventanaMs - (ahora - recientes[0])) / 1000));
    }
    recientes.push(ahora);
    visitas.set(clave, recientes);
    return null;
  };
}

/** Lee un cuerpo JSON acotado en bytes. Devuelve el objeto o una respuesta de error lista para devolver. */
export async function leerJsonAcotado(request: NextRequest, maxBytes: number): Promise<Record<string, unknown> | NextResponse> {
  if (!(request.headers.get('content-type') ?? '').toLowerCase().startsWith('application/json')) {
    return errorJson(415, 'formato_no_soportado', 'Formato no soportado.');
  }
  const declarado = Number(request.headers.get('content-length') ?? '0');
  if (Number.isFinite(declarado) && declarado > maxBytes) return errorJson(413, 'cuerpo_demasiado_grande', 'La solicitud es demasiado grande.');
  let bruto: string;
  try {
    bruto = await request.text();
  } catch {
    return errorJson(400, 'solicitud_invalida', 'Solicitud no válida.');
  }
  if (Buffer.byteLength(bruto) > maxBytes) return errorJson(413, 'cuerpo_demasiado_grande', 'La solicitud es demasiado grande.');
  try {
    const datos: unknown = JSON.parse(bruto);
    return typeof datos === 'object' && datos !== null && !Array.isArray(datos) ? (datos as Record<string, unknown>) : errorJson(400, 'solicitud_invalida', 'Solicitud no válida.');
  } catch {
    return errorJson(400, 'solicitud_invalida', 'Solicitud no válida.');
  }
}
