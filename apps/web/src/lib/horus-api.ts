import 'server-only';

/**
 * Cliente servidor a servidor de la API pública de Laravel (`/api/public/v1/*`, Fase 2).
 * Solo se importa desde Server Components, route handlers o server actions: `server-only` rompe el build si llega
 * al navegador. El token (HORUS_WEB_SERVICE_TOKEN) nunca se registra ni se envía al cliente.
 */

export type ResultadoApi<T> =
  | { ok: true; data: T }
  | { ok: false; motivo: 'no_configurado' | 'no_disponible' };

export interface CursoPublico {
  titulo: string;
  resumen: string;
  modalidad: 'presencial' | 'semipresencial' | 'virtual' | 'hibrido';
  horario: string | null;
  inscripcion: string | null;
  vigente_desde: string;
  vigente_hasta: string;
}

export interface CatalogoCursos {
  habilitado: boolean;
  modalidad: string | null;
  total: number;
  cursos: CursoPublico[];
}

const TIMEOUT_MS = 5000;

function configuracion(): { base: string; token: string } | null {
  const base = (process.env.HORUS_API_URL ?? '').replace(/\/+$/, '');
  const token = process.env.HORUS_WEB_SERVICE_TOKEN ?? '';
  if (!/^https?:\/\/[^\s/]+/i.test(base) || token.length < 32) return null;
  return { base, token };
}

export async function horusGet<T>(ruta: string): Promise<ResultadoApi<T>> {
  const cfg = configuracion();
  if (cfg === null) return { ok: false, motivo: 'no_configurado' };
  try {
    const respuesta = await fetch(`${cfg.base}/api/public/v1${ruta}`, {
      headers: { Accept: 'application/json', 'X-Horus-Web-Token': cfg.token },
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (!respuesta.ok) return { ok: false, motivo: 'no_disponible' };
    return { ok: true, data: (await respuesta.json()) as T };
  } catch {
    return { ok: false, motivo: 'no_disponible' };
  }
}

export function obtenerCursos(): Promise<ResultadoApi<CatalogoCursos>> {
  return horusGet<CatalogoCursos>('/cursos');
}
