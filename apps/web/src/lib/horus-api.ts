import 'server-only';
import { connection } from 'next/server';

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

export interface TarjetaServicio {
  titulo: string;
  descripcion: string;
  caracteristicas: string[];
  icono: string | null;
  variante: 'producto' | 'escena' | null;
  imagen_url: string | null;
  imagen_alt: string;
}

export interface ContenidoPagina {
  titulo: string;
  parrafos: string[];
  imagen_url: string | null;
  imagen_alt: string;
  titulo_tarjetas: string | null;
  tarjetas: TarjetaServicio[];
}

export type CodigoPagina = 'cableado-estructurado' | 'camaras-seguridad' | 'soporte-mantenimiento';

/** `contenido: null` = Laravel no tiene ficha autorizada: la página usa su texto estático. */
export interface PaginaServicio {
  pagina: CodigoPagina;
  contenido: ContenidoPagina | null;
}

export interface ServicioPublico {
  titulo: string;
  resumen: string;
  descripcion: string;
  categoria?: string | null;
}

export interface CatalogoServicios {
  habilitado: boolean;
  total: number;
  pagina: number;
  por_pagina: number;
  servicios: ServicioPublico[];
}

const TIMEOUT_MS = 5000;

function configuracion(): { base: string; token: string } | null {
  const base = (process.env.HORUS_API_URL ?? '').replace(/\/+$/, '');
  const token = process.env.HORUS_WEB_SERVICE_TOKEN ?? '';
  if (!/^https?:\/\/[^\s/]+/i.test(base) || token.length < 32) return null;
  return { base, token };
}

export async function horusGet<T>(ruta: string): Promise<ResultadoApi<T>> {
  // Marca la ruta como dinámica aunque falte la configuración durante el build: si no, Next prerrenderizaría el
  // resultado «no_configurado» y no volvería a consultar Laravel en cada visita.
  await connection();
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

export function obtenerServicios(pagina = 1): Promise<ResultadoApi<CatalogoServicios>> {
  return horusGet<CatalogoServicios>(`/servicios?pagina=${Math.max(1, Math.trunc(pagina))}`);
}

export function obtenerPagina(codigo: CodigoPagina): Promise<ResultadoApi<PaginaServicio>> {
  return horusGet<PaginaServicio>(`/paginas/${codigo}`);
}

/**
 * Las imágenes que devuelve Laravel son URLs absolutas construidas con el host con el que se llamó a la API (que
 * puede ser interno). Solo se conserva la ruta, y solo si apunta a `/assets/` o `/storage/`; se antepone la base
 * pública de Laravel (`HORUS_ASSETS_URL`, o `HORUS_API_URL` si no se define). Cualquier otra cosa se descarta.
 */
export function urlPublicaImagen(url: string | null): string | null {
  if (url === null) return null;
  const base = (process.env.HORUS_ASSETS_URL || process.env.HORUS_API_URL || '').replace(/\/+$/, '');
  if (!/^https?:\/\/[^\s/]+/i.test(base)) return null;
  let ruta: string;
  try {
    ruta = new URL(url, 'http://invalido.local').pathname;
  } catch {
    return null;
  }
  if (ruta.includes('..') || !/^\/(assets|storage)\/[A-Za-z0-9_.\/-]+$/.test(ruta)) return null;
  return `${base}${ruta}`;
}
