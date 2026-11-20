'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useCallback, useRef } from 'react';
import { llamarBff, type ResultadoBff } from '@/lib/panel/cliente';
import { urlLogin } from '@/lib/panel/redireccion';
import type { ErrorPanel, SesionPanel } from '@/lib/panel/tipos';
import { useSesionPanel } from './sesion-panel-contexto';

type Metodo = 'GET' | 'POST' | 'PUT' | 'DELETE';

/**
 * Operaciones del panel desde componentes cliente, siempre a través del BFF (`/api/bff/panel/*`).
 *
 * - Envía el token CSRF de la sesión ya validada por Laravel.
 * - Si Laravel responde 419 (token rotado, p. ej. tras iniciar sesión en otra pestaña), relee la sesión una vez y
 *   reintenta. Es seguro: un 419 significa que Laravel rechazó la petición antes de ejecutar nada.
 * - Si la sesión terminó (401), vuelve al acceso conservando la página actual.
 */
export function useOperacionPanel() {
  const sesion = useSesionPanel();
  const router = useRouter();
  const ruta = usePathname();
  const csrf = useRef(sesion.csrf_token);

  const ejecutar = useCallback(
    async <T,>(destino: string, metodo: Metodo = 'GET', cuerpo?: unknown): Promise<ResultadoBff<T>> => {
      const url = `/api/bff/panel/${destino}` as const;
      const intentar = () => llamarBff<T>(url, { metodo, cuerpo: metodo === 'GET' ? undefined : (cuerpo ?? {}), csrf: metodo === 'GET' ? undefined : csrf.current });

      let resultado = await intentar();
      if (!resultado.ok && resultado.estado === 419) {
        const renovada = await llamarBff<{ data: SesionPanel }>('/api/bff/auth/sesion');
        if (renovada.ok) {
          csrf.current = renovada.datos.data.csrf_token;
          resultado = await intentar();
        }
      }
      if (!resultado.ok && resultado.estado === 401) router.replace(urlLogin(ruta));
      return resultado;
    },
    [router, ruta],
  );

  /** Envía un formulario multipart (imagen de la ficha) con las mismas garantías CSRF y de sesión. */
  const subir = useCallback(
    async <T,>(destino: string, formulario: FormData): Promise<ResultadoBff<T>> => {
      const url = `/api/bff/panel/${destino}` as const;
      const intentar = () => llamarBff<T>(url, { metodo: 'POST', formulario, csrf: csrf.current });

      let resultado = await intentar();
      if (!resultado.ok && resultado.estado === 419) {
        const renovada = await llamarBff<{ data: SesionPanel }>('/api/bff/auth/sesion');
        if (renovada.ok) {
          csrf.current = renovada.datos.data.csrf_token;
          resultado = await intentar();
        }
      }
      if (!resultado.ok && resultado.estado === 401) router.replace(urlLogin(ruta));
      return resultado;
    },
    [router, ruta],
  );

  /** Descarga un archivo generado por Laravel (`POST` por defecto, o `GET` para documentos sin efecto en datos). */
  const descargar = useCallback(
    async (destino: string, metodo: 'GET' | 'POST' = 'POST'): Promise<{ ok: true; aviso?: string } | { ok: false; error: ErrorPanel }> => {
      const intentar = () =>
        fetch(`/api/bff/panel/${destino}`, {
          method: metodo,
          headers: metodo === 'POST'
            ? { Accept: 'text/csv, application/pdf, application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.current }
            : { Accept: 'application/pdf, text/csv, application/json' },
          body: metodo === 'POST' ? '{}' : undefined,
          credentials: 'same-origin',
          cache: 'no-store',
        });

      let respuesta: Response;
      try {
        respuesta = await intentar();
        if (respuesta.status === 419) {
          const renovada = await llamarBff<{ data: SesionPanel }>('/api/bff/auth/sesion');
          if (renovada.ok) {
            csrf.current = renovada.datos.data.csrf_token;
            respuesta = await intentar();
          }
        }
      } catch {
        return { ok: false, error: { message: 'No se pudo conectar. Revisa tu conexión e inténtalo de nuevo.', code: 'sin_conexion' } };
      }

      if (!respuesta.ok) {
        if (respuesta.status === 401) router.replace(urlLogin(ruta));
        const error = (await respuesta.json().catch(() => null)) as ErrorPanel | null;
        return { ok: false, error: error && typeof error.message === 'string' ? error : { message: 'No se pudo generar el archivo.', code: 'error' } };
      }

      const nombre = /filename="([^"]+)"/.exec(respuesta.headers.get('content-disposition') ?? '')?.[1] ?? 'exportacion.csv';
      const enlace = document.createElement('a');
      enlace.href = URL.createObjectURL(await respuesta.blob());
      enlace.download = nombre;
      document.body.append(enlace);
      enlace.click();
      enlace.remove();
      window.setTimeout(() => URL.revokeObjectURL(enlace.href), 10_000);
      const aviso = respuesta.headers.get('x-export-truncated') === 'true'
        ? (respuesta.headers.get('x-export-message') ?? undefined)
        : undefined;
      return { ok: true, aviso };
    },
    [router, ruta],
  );

  return { ejecutar, descargar, subir };
}
