'use client';

import { createContext, use, type ReactNode } from 'react';
import type { SesionPanel } from '@/lib/panel/tipos';

const ContextoSesion = createContext<SesionPanel | null>(null);

/** Expone a los componentes cliente la sesión ya validada por Laravel en el servidor. */
export function ProveedorSesionPanel({ sesion, children }: { sesion: SesionPanel; children: ReactNode }) {
  return <ContextoSesion value={sesion}>{children}</ContextoSesion>;
}

export function useSesionPanel(): SesionPanel {
  const sesion = use(ContextoSesion);
  if (sesion === null) throw new Error('useSesionPanel debe usarse dentro de ProveedorSesionPanel.');
  return sesion;
}

/** Ayuda visual para ocultar acciones: Laravel vuelve a comprobar cada operación. */
export function usePuede(permiso: string): boolean {
  return useSesionPanel().permisos.includes(permiso);
}
