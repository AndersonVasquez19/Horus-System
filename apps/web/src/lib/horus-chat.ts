import 'server-only';
import { createHmac } from 'node:crypto';
import { configuracionApi, urlPublicaImagen } from '@/lib/horus-api';
import { rutaNext, rutaPermitida } from '@/lib/chat-rutas';
import { sanearSugerencias, type ClaveSugerencia } from '@/lib/chat-sugerencias';

/**
 * Cliente servidor a servidor del chat (`POST /api/public/v1/chat` de Laravel, que a su vez consulta Horus AI).
 * Next nunca habla directamente con Python: Laravel mantiene la barrera de privacidad, la contingencia institucional
 * y el registro de metadatos. La IA solo orienta; aquí no hay ninguna operación sobre datos empresariales.
 */

export const MAX_MENSAJE = 2000;
export const MAX_CUERPO_BYTES = 8 * 1024;
const TIMEOUT_MS = 8000;

export interface TarjetaChat {
  id: string;
  tipo: 'curso' | 'servicio';
  titulo: string;
  descripcion: string;
  url: string;
  imagen: string | null;
  accion: string;
}

export interface RespuestaChat {
  respuesta: string;
  fuente: string;
  tarjetas?: TarjetaChat[];
  /** Rutas de secciones del sitio (lista blanca), ya validadas en Laravel y aquí. */
  enlaces?: string[];
  /** Siguientes pasos (claves cerradas) que propone el asistente; el widget los traduce a botones. */
  sugerencias?: ClaveSugerencia[];
}

export type ResultadoChat =
  | { ok: true; data: RespuestaChat }
  | { ok: false; estado: 429 | 503; reintentarEn?: number };

/** Identificador opaco del visitante: HMAC de su IP con el token de servicio. La IP en claro nunca sale de Next. */
export function identificadorVisitante(ip: string): string | null {
  const cfg = configuracionApi();
  return cfg === null ? null : createHmac('sha256', cfg.token).update(`visitante|${ip}`).digest('hex');
}

function texto(valor: unknown, max: number): string | null {
  return typeof valor === 'string' && valor.trim() !== '' && valor.length <= max ? valor : null;
}

/** Valida y reduce la respuesta de Laravel: solo texto acotado, rutas de la lista blanca e imágenes de Laravel. */
export function sanearRespuesta(bruto: unknown): RespuestaChat | null {
  if (typeof bruto !== 'object' || bruto === null) return null;
  const r = bruto as Record<string, unknown>;
  const respuesta = texto(r.respuesta, 6000);
  const fuente = texto(r.fuente, 20);
  if (respuesta === null || fuente === null) return null;

  const salida: RespuestaChat = { respuesta, fuente };
  if (Array.isArray(r.tarjetas)) {
    const tarjetas: TarjetaChat[] = [];
    for (const t of r.tarjetas.slice(0, 5)) {
      if (typeof t !== 'object' || t === null) continue;
      const c = t as Record<string, unknown>;
      const id = texto(c.id, 64);
      const titulo = texto(c.titulo, 120);
      const descripcion = texto(c.descripcion, 500);
      const accion = texto(c.accion, 60);
      const url = rutaNext(c.url);
      if (id === null || titulo === null || descripcion === null || accion === null || url === null) continue;
      if (c.tipo !== 'curso' && c.tipo !== 'servicio') continue;
      tarjetas.push({
        id,
        tipo: c.tipo,
        titulo,
        descripcion,
        url,
        imagen: urlPublicaImagen(typeof c.imagen === 'string' ? c.imagen : null),
        accion,
      });
    }
    if (tarjetas.length > 0) salida.tarjetas = tarjetas;
  }
  if (Array.isArray(r.enlaces)) {
    const enlaces = r.enlaces
      .slice(0, 3)
      .map((ruta) => rutaPermitida(ruta))
      .filter((ruta): ruta is string => ruta !== null);
    if (enlaces.length > 0) salida.enlaces = [...new Set(enlaces)];
  }
  const sugerencias = sanearSugerencias(r.sugerencias);
  if (sugerencias.length > 0) salida.sugerencias = sugerencias;
  return salida;
}

export async function enviarMensajeChat(mensaje: string, sessionId: string, visitante: string): Promise<ResultadoChat> {
  const cfg = configuracionApi();
  if (cfg === null) return { ok: false, estado: 503 };
  try {
    const respuesta = await fetch(`${cfg.base}/api/public/v1/chat`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Horus-Web-Token': cfg.token,
        'X-Horus-Visitante': visitante,
      },
      body: JSON.stringify({ mensaje, session_id: sessionId }),
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (respuesta.status === 429) {
      const espera = Number(respuesta.headers.get('retry-after'));
      return { ok: false, estado: 429, reintentarEn: Number.isFinite(espera) && espera > 0 ? Math.min(espera, 120) : undefined };
    }
    if (!respuesta.ok) return { ok: false, estado: 503 };
    const data = sanearRespuesta(await respuesta.json());
    return data === null ? { ok: false, estado: 503 } : { ok: true, data };
  } catch {
    return { ok: false, estado: 503 };
  }
}
