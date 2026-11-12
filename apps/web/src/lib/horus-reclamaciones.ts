import 'server-only';
import { configuracionApi } from '@/lib/horus-api';

/**
 * Cliente servidor a servidor del Libro de Reclamaciones (`/api/public/v1/reclamaciones*` de Laravel, Fase 2c.1).
 * Laravel es la autoridad: valida, numera, registra, audita y limita. Aquí solo se filtra lo que viaja (lista cerrada
 * de campos) y se reduce lo que vuelve. No existe ninguna llamada para consultar una reclamación: el envío es la única
 * operación pública. Horus AI no interviene en este flujo.
 */

export const MAX_CUERPO_BYTES = 16 * 1024;
const TIMEOUT_MS = 10_000;

const TEXTOS = [
  'nombres', 'apellidos', 'numero_documento', 'domicilio', 'telefono', 'email',
  'representante_nombre', 'representante_domicilio', 'representante_telefono', 'representante_email',
  'bien_descripcion', 'monto_bien', 'detalle', 'pedido', 'marca', 'sitio_web',
] as const;
const ENUMS: Record<string, readonly string[]> = {
  tipo: ['reclamo', 'queja'],
  tipo_documento: ['dni', 'ce', 'pasaporte', 'ruc'],
  bien_tipo: ['producto', 'servicio'],
};
const BOOLEANOS = ['es_menor', 'monto_no_aplica', 'acepta_privacidad'] as const;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const MAX_TEXTO = 3000;

export interface FormularioLibro {
  habilitado: boolean;
  marca: string | null;
  token_envio: string | null;
  correo_activo: boolean;
  plazo_dias_habiles: number;
  honeypot: string;
}

export interface ConfirmacionLibro {
  numero: string;
  tipo: 'reclamo' | 'queja';
  fecha_limite: string;
  repetida: boolean;
  correo_activo: boolean;
}

export type ResultadoFormulario = { ok: true; data: FormularioLibro } | { ok: false };

export type ResultadoEnvio =
  | { ok: true; data: ConfirmacionLibro }
  | { ok: false; estado: 422; errores: Record<string, string[]> }
  | { ok: false; estado: 429; reintentarEn?: number }
  | { ok: false; estado: 404 | 503 };

/**
 * Reduce el cuerpo del navegador a la lista cerrada de campos que Laravel acepta; descarta lo demás. No valida reglas
 * de negocio (eso es de Laravel): solo tipos y longitudes máximas para no reenviar basura.
 */
export function filtrarCampos(entrada: Record<string, unknown>): Record<string, unknown> | null {
  const salida: Record<string, unknown> = {};
  for (const campo of TEXTOS) {
    const v = entrada[campo];
    if (v === undefined || v === null || v === '') continue;
    if (typeof v !== 'string' || v.length > MAX_TEXTO) return null;
    salida[campo] = v;
  }
  for (const [campo, validos] of Object.entries(ENUMS)) {
    const v = entrada[campo];
    if (v === undefined || v === null || v === '') continue;
    if (typeof v !== 'string' || !validos.includes(v)) return null;
    salida[campo] = v;
  }
  for (const campo of BOOLEANOS) {
    const v = entrada[campo];
    if (v === undefined) continue;
    if (typeof v !== 'boolean') return null;
    salida[campo] = v;
  }
  const token = entrada.token_envio;
  if (typeof token !== 'string' || !UUID.test(token)) return null;
  salida.token_envio = token;
  return salida;
}

function cabeceras(visitante: string, token: string, json: boolean): Record<string, string> {
  return {
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
    'X-Horus-Web-Token': token,
    'X-Horus-Visitante': visitante,
  };
}

function espera(respuesta: Response): number | undefined {
  const s = Number(respuesta.headers.get('retry-after'));
  return Number.isFinite(s) && s > 0 ? Math.min(s, 3600) : undefined;
}

export async function obtenerFormulario(visitante: string): Promise<ResultadoFormulario> {
  const cfg = configuracionApi();
  if (cfg === null) return { ok: false };
  try {
    const r = await fetch(`${cfg.base}/api/public/v1/reclamaciones/formulario`, {
      headers: cabeceras(visitante, cfg.token, false),
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (!r.ok) return { ok: false };
    const b: unknown = await r.json();
    if (typeof b !== 'object' || b === null) return { ok: false };
    const f = b as Record<string, unknown>;
    if (typeof f.habilitado !== 'boolean') return { ok: false };
    const marca = typeof f.marca === 'string' && f.marca.length <= 100 ? f.marca : null;
    const tokenEnvio = typeof f.token_envio === 'string' && UUID.test(f.token_envio) ? f.token_envio : null;
    const habilitado = f.habilitado && marca !== null && tokenEnvio !== null;
    return {
      ok: true,
      data: {
        habilitado,
        marca: habilitado ? marca : null,
        token_envio: habilitado ? tokenEnvio : null,
        correo_activo: habilitado && f.correo_activo === true,
        plazo_dias_habiles: typeof f.plazo_dias_habiles === 'number' && f.plazo_dias_habiles > 0 && f.plazo_dias_habiles < 100 ? f.plazo_dias_habiles : 15,
        honeypot: typeof f.honeypot === 'string' && /^[a-z_]{3,30}$/.test(f.honeypot) ? f.honeypot : 'sitio_web',
      },
    };
  } catch {
    return { ok: false };
  }
}

/** Errores de campo de Laravel (422), reducidos a clave conocida → primeros mensajes de texto corto. */
function sanearErrores(bruto: unknown): Record<string, string[]> {
  const salida: Record<string, string[]> = {};
  const errores = typeof bruto === 'object' && bruto !== null ? (bruto as Record<string, unknown>).errors : null;
  if (typeof errores !== 'object' || errores === null) return salida;
  for (const [campo, mensajes] of Object.entries(errores as Record<string, unknown>).slice(0, 30)) {
    if (!/^[a-z_]{2,40}$/.test(campo) || !Array.isArray(mensajes)) continue;
    const textos = mensajes.filter((m): m is string => typeof m === 'string' && m.length <= 200).slice(0, 2);
    if (textos.length > 0) salida[campo] = textos;
  }
  return salida;
}

export async function enviarReclamacion(campos: Record<string, unknown>, visitante: string): Promise<ResultadoEnvio> {
  const cfg = configuracionApi();
  if (cfg === null) return { ok: false, estado: 503 };
  try {
    const r = await fetch(`${cfg.base}/api/public/v1/reclamaciones`, {
      method: 'POST',
      headers: cabeceras(visitante, cfg.token, true),
      body: JSON.stringify(campos),
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (r.status === 422) return { ok: false, estado: 422, errores: sanearErrores(await r.json().catch(() => null)) };
    if (r.status === 429) return { ok: false, estado: 429, reintentarEn: espera(r) };
    if (r.status === 404) return { ok: false, estado: 404 };
    if (r.status !== 200 && r.status !== 201) return { ok: false, estado: 503 };
    const b: unknown = await r.json();
    if (typeof b !== 'object' || b === null) return { ok: false, estado: 503 };
    const c = b as Record<string, unknown>;
    const valido =
      typeof c.numero === 'string' && /^[0-9A-Za-z-]{3,30}$/.test(c.numero) &&
      (c.tipo === 'reclamo' || c.tipo === 'queja') &&
      typeof c.fecha_limite === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(c.fecha_limite) &&
      typeof c.repetida === 'boolean';
    if (!valido) return { ok: false, estado: 503 };
    return {
      ok: true,
      data: {
        numero: c.numero as string,
        tipo: c.tipo as 'reclamo' | 'queja',
        fecha_limite: c.fecha_limite as string,
        repetida: c.repetida as boolean,
        correo_activo: c.correo_activo === true,
      },
    };
  } catch {
    return { ok: false, estado: 503 };
  }
}
