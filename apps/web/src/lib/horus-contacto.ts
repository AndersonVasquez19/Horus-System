import 'server-only';
import { configuracionApi } from '@/lib/horus-api';

/**
 * Cliente servidor a servidor del formulario «Contáctanos» (`POST /api/public/v1/contactos` de Laravel). Laravel valida,
 * guarda el mensaje y avisa al equipo; aquí solo se filtra lo que viaja y se reduce lo que vuelve. No existe ninguna
 * llamada para consultar mensajes.
 */

export const MAX_CUERPO_BYTES = 6 * 1024;
const TIMEOUT_MS = 10_000;

const TEXTOS: Record<string, number> = { nombre: 120, telefono: 30, correo: 160, asunto: 40, mensaje: 1500, sitio_web: 200 };

export type ResultadoContacto =
  | { ok: true }
  | { ok: false; estado: 422; errores: Record<string, string[]> }
  | { ok: false; estado: 429; reintentarEn?: number }
  | { ok: false; estado: 503 };

/** Lista cerrada de campos de texto; devuelve null si algo tiene un tipo o un tamaño imposible. */
export function filtrarCampos(entrada: Record<string, unknown>): Record<string, string> | null {
  const salida: Record<string, string> = {};
  for (const [campo, max] of Object.entries(TEXTOS)) {
    const v = entrada[campo];
    if (v === undefined || v === null || v === '') continue;
    if (typeof v !== 'string' || v.length > max) return null;
    salida[campo] = v;
  }
  return salida;
}

function sanearErrores(bruto: unknown): Record<string, string[]> {
  const salida: Record<string, string[]> = {};
  const errores = typeof bruto === 'object' && bruto !== null ? (bruto as Record<string, unknown>).errors : null;
  if (typeof errores !== 'object' || errores === null) return salida;
  for (const [campo, mensajes] of Object.entries(errores as Record<string, unknown>).slice(0, 10)) {
    if (!/^[a-z_]{2,20}$/.test(campo) || !Array.isArray(mensajes)) continue;
    const textos = mensajes.filter((m): m is string => typeof m === 'string' && m.length <= 200).slice(0, 2);
    if (textos.length > 0) salida[campo] = textos;
  }
  return salida;
}

export async function enviarMensaje(campos: Record<string, string>, visitante: string): Promise<ResultadoContacto> {
  const cfg = configuracionApi();
  if (cfg === null) return { ok: false, estado: 503 };
  try {
    const r = await fetch(`${cfg.base}/api/public/v1/contactos`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Horus-Web-Token': cfg.token, 'X-Horus-Visitante': visitante },
      body: JSON.stringify(campos),
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (r.status === 422) return { ok: false, estado: 422, errores: sanearErrores(await r.json().catch(() => null)) };
    if (r.status === 429) {
      const s = Number(r.headers.get('retry-after'));
      return { ok: false, estado: 429, reintentarEn: Number.isFinite(s) && s > 0 ? Math.min(s, 3600) : undefined };
    }
    return r.status === 201 ? { ok: true } : { ok: false, estado: 503 };
  } catch {
    return { ok: false, estado: 503 };
  }
}
