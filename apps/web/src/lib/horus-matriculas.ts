import 'server-only';
import { configuracionApi } from '@/lib/horus-api';

/**
 * Cliente servidor a servidor de la solicitud de matrícula (`/api/public/v1/matriculas*` de Laravel, Fase 2c.2).
 * Una solicitud pública es un CONTACTO que el personal revisa en el panel: no crea estudiante ni matrícula ni reserva
 * cupo. Laravel valida y vuelve a resolver la disponibilidad del curso; aquí solo se filtra lo que viaja y se reduce lo
 * que vuelve. No existe ninguna llamada para consultar solicitudes.
 */

export const MAX_CUERPO_BYTES = 8 * 1024;
const TIMEOUT_MS = 10_000;

export interface CursoSolicitable {
  valor: string;
  titulo: string;
  resumen: string;
  modalidad: string;
  horario: string | null;
  inscripcion: string | null;
  vigente_hasta: string;
}

export interface FormularioMatricula {
  habilitado: boolean;
  marca: string | null;
  cursos: CursoSolicitable[];
}

export interface ConfirmacionMatricula {
  codigo: string;
  curso: string;
  modalidad: string;
  repetida: boolean;
}

export type ResultadoFormulario = { ok: true; data: FormularioMatricula } | { ok: false };

export type ResultadoEnvio =
  | { ok: true; data: ConfirmacionMatricula }
  | { ok: false; estado: 422; errores: Record<string, string[]> }
  | { ok: false; estado: 429; reintentarEn?: number }
  | { ok: false; estado: 503 };

const TEXTOS: Record<string, number> = {
  curso: 64, nombres: 120, apellidos: 120, email: 254, telefono: 30, mensaje: 1000, marca: 1024, sitio_web: 200,
  representante_nombre: 120, representante_email: 254, representante_telefono: 30,
};
const BOOLEANOS = ['es_menor', 'consentimiento'] as const;

/** Lista cerrada de campos que viajan a Laravel; devuelve null si algo tiene un tipo o un tamaño imposible. */
export function filtrarCampos(entrada: Record<string, unknown>): Record<string, unknown> | null {
  const salida: Record<string, unknown> = {};
  for (const [campo, max] of Object.entries(TEXTOS)) {
    const v = entrada[campo];
    if (v === undefined || v === null || v === '') continue;
    if (typeof v !== 'string' || v.length > max) return null;
    salida[campo] = v;
  }
  for (const campo of BOOLEANOS) {
    const v = entrada[campo];
    if (v === undefined) continue;
    if (typeof v !== 'boolean') return null;
    salida[campo] = v;
  }
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
    const r = await fetch(`${cfg.base}/api/public/v1/matriculas/formulario`, {
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
    const cursos: CursoSolicitable[] = [];
    if (Array.isArray(f.cursos)) {
      for (const c of f.cursos.slice(0, 100)) {
        if (typeof c !== 'object' || c === null) continue;
        const k = c as Record<string, unknown>;
        const valor = typeof k.valor === 'string' && /^[a-f0-9]{64}$/.test(k.valor) ? k.valor : null;
        const titulo = texto(k.titulo, 200);
        const resumen = texto(k.resumen, 1500);
        const modalidad = texto(k.modalidad, 30);
        const vigente = texto(k.vigente_hasta, 10);
        if (valor === null || !titulo || resumen === null || !modalidad || vigente === null) continue;
        cursos.push({ valor, titulo, resumen, modalidad, horario: texto(k.horario, 300), inscripcion: texto(k.inscripcion, 300), vigente_hasta: vigente });
      }
    }
    const habilitado = f.habilitado && marca !== null && cursos.length > 0;
    return { ok: true, data: { habilitado, marca: habilitado ? marca : null, cursos: habilitado ? cursos : [] } };
  } catch {
    return { ok: false };
  }
}

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

export async function enviarSolicitud(campos: Record<string, unknown>, visitante: string): Promise<ResultadoEnvio> {
  const cfg = configuracionApi();
  if (cfg === null) return { ok: false, estado: 503 };
  try {
    const r = await fetch(`${cfg.base}/api/public/v1/matriculas`, {
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
    if (typeof c.codigo !== 'string' || !/^SM-[0-9A-F]{8}$/.test(c.codigo) || typeof c.repetida !== 'boolean') return { ok: false, estado: 503 };
    const curso = texto(c.curso, 200);
    const modalidad = texto(c.modalidad, 30);
    if (!curso || !modalidad) return { ok: false, estado: 503 };
    return { ok: true, data: { codigo: c.codigo, curso, modalidad, repetida: c.repetida } };
  } catch {
    return { ok: false, estado: 503 };
  }
}
