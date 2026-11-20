import 'server-only';
import { configuracionApi } from '@/lib/horus-api';

/**
 * Cliente servidor a servidor de la solicitud de cotización (`/api/public/v1/cotizaciones*` de Laravel, Fase 2c.3).
 * Es una SOLICITUD que entra al módulo comercial existente: no es una cotización emitida, no lleva precios ni genera
 * documentos. Laravel valida y vuelve a resolver los servicios; aquí solo se filtra lo que viaja y se reduce lo que
 * vuelve. No existe ninguna llamada para consultar solicitudes.
 */

export const MAX_CUERPO_BYTES = 12 * 1024;
const TIMEOUT_MS = 10_000;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export interface ServicioSolicitable {
  valor: string;
  titulo: string;
  resumen: string | null;
}

export interface FormularioCotizacion {
  habilitado: boolean;
  marca: string | null;
  servicios: ServicioSolicitable[];
  max_servicios: number;
}

export interface ConfirmacionCotizacion {
  codigo: string;
  recibida_at: string;
  repetida: boolean;
}

export type ResultadoFormulario = { ok: true; data: FormularioCotizacion } | { ok: false };

export type ResultadoEnvio =
  | { ok: true; data: ConfirmacionCotizacion }
  | { ok: false; estado: 422; errores: Record<string, string[]> }
  | { ok: false; estado: 429; reintentarEn?: number }
  | { ok: false; estado: 503 };

const TEXTOS: Record<string, number> = {
  nombre: 120, empresa: 120, email: 254, telefono: 30, ubicacion: 120, necesidad: 3000, informacion: 1000, marca: 1024, sitio_web: 200,
};

/** Lista cerrada de campos que viajan a Laravel; devuelve null si algo tiene un tipo o un tamaño imposible. */
export function filtrarCampos(entrada: Record<string, unknown>): Record<string, unknown> | null {
  const salida: Record<string, unknown> = {};
  for (const [campo, max] of Object.entries(TEXTOS)) {
    const v = entrada[campo];
    if (v === undefined || v === null || v === '') continue;
    if (typeof v !== 'string' || v.length > max) return null;
    salida[campo] = v;
  }
  const servicios = entrada.servicios;
  if (!Array.isArray(servicios) || servicios.length > 10 || !servicios.every((s) => typeof s === 'string' && UUID.test(s))) return null;
  salida.servicios = servicios;
  const consentimiento = entrada.consentimiento;
  if (consentimiento !== undefined && typeof consentimiento !== 'boolean') return null;
  if (consentimiento !== undefined) salida.consentimiento = consentimiento;
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

function texto(v: unknown, max: number): string | null {
  return typeof v === 'string' && v.length <= max ? v : null;
}

export async function obtenerFormulario(visitante: string): Promise<ResultadoFormulario> {
  const cfg = configuracionApi();
  if (cfg === null) return { ok: false };
  try {
    const r = await fetch(`${cfg.base}/api/public/v1/cotizaciones/formulario`, {
      headers: cabeceras(visitante, cfg.token, false),
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (!r.ok) return { ok: false };
    const b: unknown = await r.json();
    if (typeof b !== 'object' || b === null) return { ok: false };
    const f = b as Record<string, unknown>;
    if (typeof f.habilitado !== 'boolean') return { ok: false };
    const marca = typeof f.marca === 'string' && f.marca.length <= 1024 ? f.marca : null;
    const servicios: ServicioSolicitable[] = [];
    if (Array.isArray(f.servicios)) {
      for (const s of f.servicios.slice(0, 60)) {
        if (typeof s !== 'object' || s === null) continue;
        const k = s as Record<string, unknown>;
        const titulo = texto(k.titulo, 200);
        if (typeof k.valor !== 'string' || !UUID.test(k.valor) || !titulo) continue;
        servicios.push({ valor: k.valor, titulo, resumen: texto(k.resumen, 500) });
      }
    }
    const habilitado = f.habilitado && marca !== null && servicios.length > 0;
    const max = typeof f.max_servicios === 'number' && f.max_servicios >= 1 && f.max_servicios <= 10 ? f.max_servicios : 5;
    return { ok: true, data: { habilitado, marca: habilitado ? marca : null, servicios: habilitado ? servicios : [], max_servicios: max } };
  } catch {
    return { ok: false };
  }
}

function sanearErrores(bruto: unknown): Record<string, string[]> {
  const salida: Record<string, string[]> = {};
  const errores = typeof bruto === 'object' && bruto !== null ? (bruto as Record<string, unknown>).errors : null;
  if (typeof errores !== 'object' || errores === null) return salida;
  for (const [campo, mensajes] of Object.entries(errores as Record<string, unknown>).slice(0, 30)) {
    if (!/^[a-z_]{2,40}(\.\d{1,2})?$/.test(campo) || !Array.isArray(mensajes)) continue;
    const textos = mensajes.filter((m): m is string => typeof m === 'string' && m.length <= 200).slice(0, 2);
    if (textos.length > 0) salida[campo] = textos;
  }
  return salida;
}

export async function enviarSolicitud(campos: Record<string, unknown>, visitante: string): Promise<ResultadoEnvio> {
  const cfg = configuracionApi();
  if (cfg === null) return { ok: false, estado: 503 };
  try {
    const r = await fetch(`${cfg.base}/api/public/v1/cotizaciones`, {
      method: 'POST',
      headers: cabeceras(visitante, cfg.token, true),
      body: JSON.stringify(campos),
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (r.status === 422) return { ok: false, estado: 422, errores: sanearErrores(await r.json().catch(() => null)) };
    if (r.status === 429) {
      const s = Number(r.headers.get('retry-after'));
      return { ok: false, estado: 429, reintentarEn: Number.isFinite(s) && s > 0 ? Math.min(s, 3600) : undefined };
    }
    if (r.status !== 200 && r.status !== 201) return { ok: false, estado: 503 };
    const b: unknown = await r.json();
    if (typeof b !== 'object' || b === null) return { ok: false, estado: 503 };
    const c = b as Record<string, unknown>;
    if (typeof c.codigo !== 'string' || !/^SOL-[0-9A-F-]{36}$/.test(c.codigo) || typeof c.repetida !== 'boolean') return { ok: false, estado: 503 };
    const recibida = texto(c.recibida_at, 40);
    if (!recibida || Number.isNaN(Date.parse(recibida))) return { ok: false, estado: 503 };
    return { ok: true, data: { codigo: c.codigo, recibida_at: recibida, repetida: c.repetida } };
  } catch {
    return { ok: false, estado: 503 };
  }
}
