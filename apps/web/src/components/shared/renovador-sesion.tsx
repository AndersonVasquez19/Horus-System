'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useSesionPanel } from './sesion-panel-contexto';
import { llamarBff } from '@/lib/panel/cliente';
import { urlLogin } from '@/lib/panel/redireccion';

const INTERVALO_MS = 5 * 60 * 1000;
const AVISO_SEGUNDOS = 5 * 60;
const EVENTOS = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const;

function esCampoEditable(elemento: Element | null): boolean {
  if (!elemento) return false;
  const tag = elemento.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  return elemento instanceof HTMLElement && elemento.isContentEditable;
}

/**
 * Mantiene viva la cookie del navegador mientras el usuario trabaja, detecta 401 también al volver a la pestaña,
 * avisa si la sesión se acerca a su vencimiento absoluto (`expira_en`) y habilita `/` para enfocar la búsqueda.
 */
export function RenovadorSesion() {
  const router = useRouter();
  const ruta = usePathname();
  const { expira_en: expiraInicial } = useSesionPanel();
  const actividad = useRef(false);
  const [expiraRemoto, setExpiraRemoto] = useState<number | null | undefined>(undefined);
  const [ahoraSeg, setAhoraSeg] = useState<number | null>(null);
  const [comprobando, setComprobando] = useState(false);

  const expiraEn = expiraRemoto !== undefined ? expiraRemoto : expiraInicial;

  const consultarSesion = useCallback(async () => {
    const resultado = await llamarBff<{ data?: { expira_en?: number | null } }>('/api/bff/auth/sesion');
    if (!resultado.ok) {
      if (resultado.estado === 401) router.replace(urlLogin(ruta));
      return;
    }
    if (resultado.datos.data && 'expira_en' in resultado.datos.data) {
      setExpiraRemoto(resultado.datos.data.expira_en ?? null);
    }
  }, [router, ruta]);

  useEffect(() => {
    const marcar = () => {
      actividad.current = true;
    };
    for (const evento of EVENTOS) window.addEventListener(evento, marcar, { passive: true });

    const alCambiarVisibilidad = () => {
      if (document.visibilityState === 'visible') {
        void consultarSesion();
      }
    };
    document.addEventListener('visibilitychange', alCambiarVisibilidad);

    const temporizador = window.setInterval(() => {
      if (!actividad.current || document.visibilityState !== 'visible') return;
      actividad.current = false;
      void consultarSesion();
    }, INTERVALO_MS);

    return () => {
      for (const evento of EVENTOS) window.removeEventListener(evento, marcar);
      document.removeEventListener('visibilitychange', alCambiarVisibilidad);
      window.clearInterval(temporizador);
    };
  }, [consultarSesion]);

  useEffect(() => {
    if (expiraEn === null) return;
    const inicio = window.setTimeout(() => setAhoraSeg(Math.floor(Date.now() / 1000)), 0);
    const reloj = window.setInterval(() => setAhoraSeg(Math.floor(Date.now() / 1000)), 30_000);
    return () => {
      window.clearTimeout(inicio);
      window.clearInterval(reloj);
    };
  }, [expiraEn]);

  const segundosRestantes = expiraEn !== null && ahoraSeg !== null ? Math.max(0, expiraEn - ahoraSeg) : null;

  useEffect(() => {
    const atajo = (evento: KeyboardEvent) => {
      if (evento.key !== '/' || evento.ctrlKey || evento.metaKey || evento.altKey) return;
      if (esCampoEditable(document.activeElement)) return;
      evento.preventDefault();
      const buscador = document.querySelector<HTMLInputElement>('input[type="search"], input[name="busqueda"]');
      if (buscador) {
        buscador.focus();
        buscador.select();
      } else {
        router.push('/admin/academico/buscar');
      }
    };
    window.addEventListener('keydown', atajo);
    return () => window.removeEventListener('keydown', atajo);
  }, [router]);

  if (segundosRestantes === null || segundosRestantes > AVISO_SEGUNDOS) return null;

  const minutos = Math.max(1, Math.ceil(segundosRestantes / 60));

  async function renovarAhora() {
    setComprobando(true);
    await consultarSesion();
    setComprobando(false);
  }

  return (
    <div
      role="status"
      className="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-950 sm:px-6"
    >
      <span>
        {segundosRestantes === 0
          ? 'Tu sesión ha alcanzado su tiempo máximo y cerrará en cualquier momento.'
          : `Tu sesión caducará en aproximadamente ${minutos} min.`}
      </span>
      <button
        type="button"
        onClick={() => void renovarAhora()}
        disabled={comprobando}
        className="rounded-md border border-amber-300 bg-white px-2.5 py-1 font-semibold text-amber-950 hover:bg-amber-100 disabled:opacity-60"
      >
        {comprobando ? 'Comprobando…' : 'Comprobar sesión'}
      </button>
    </div>
  );
}
