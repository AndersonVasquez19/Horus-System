'use client';

import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useRef } from 'react';
import { llamarBff } from '@/lib/panel/cliente';
import { urlLogin } from '@/lib/panel/redireccion';

const INTERVALO_MS = 5 * 60 * 1000;
const EVENTOS = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const;

/**
 * Mantiene viva la cookie del navegador mientras el usuario trabaja: Laravel desliza la sesión en cada petición,
 * pero los Server Components no pueden reescribir la cookie. Solo renueva si hubo actividad desde la última
 * renovación, de modo que una pestaña abandonada sí caduca por inactividad. Si Laravel indica que la sesión
 * terminó (inactividad, vida máxima o revocación), vuelve al acceso.
 */
export function RenovadorSesion() {
  const router = useRouter();
  const ruta = usePathname();
  const actividad = useRef(false);

  useEffect(() => {
    const marcar = () => {
      actividad.current = true;
    };
    for (const evento of EVENTOS) window.addEventListener(evento, marcar, { passive: true });

    const temporizador = window.setInterval(async () => {
      if (!actividad.current || document.visibilityState !== 'visible') return;
      actividad.current = false;
      const resultado = await llamarBff('/api/bff/auth/sesion');
      if (!resultado.ok && resultado.estado === 401) router.replace(urlLogin(ruta));
    }, INTERVALO_MS);

    return () => {
      for (const evento of EVENTOS) window.removeEventListener(evento, marcar);
      window.clearInterval(temporizador);
    };
  }, [router, ruta]);

  return null;
}
