'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Bell, ChevronDown, LogOut, User } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { llamarBff } from '@/lib/panel/cliente';
import type { UsuarioSesion } from '@/lib/panel/tipos';
import type { TonoPanel } from './panel-tipos';

interface MenuUsuarioPanelProps {
  usuario: UsuarioSesion;
  tono: TonoPanel;
}

/**
 * Menú del usuario autenticado en la cabecera superior del panel: perfil propio, bandeja de notificaciones y
 * cierre de sesión en BFF + Laravel.
 */
export function MenuUsuarioPanel({ usuario, tono }: MenuUsuarioPanelProps) {
  const router = useRouter();
  const [abierto, setAbierto] = useState(false);
  const [saliendo, setSaliendo] = useState(false);
  const contenedorRef = useRef<HTMLDivElement>(null);
  const idMenu = useId();

  useEffect(() => {
    if (!abierto) return;
    function alPulsar(e: MouseEvent) {
      if (contenedorRef.current && !contenedorRef.current.contains(e.target as Node)) {
        setAbierto(false);
      }
    }
    function alTeclear(e: KeyboardEvent) {
      if (e.key === 'Escape') setAbierto(false);
    }
    document.addEventListener('mousedown', alPulsar);
    document.addEventListener('keydown', alTeclear);
    return () => {
      document.removeEventListener('mousedown', alPulsar);
      document.removeEventListener('keydown', alTeclear);
    };
  }, [abierto]);

  async function salir() {
    if (saliendo) return;
    setSaliendo(true);
    await llamarBff('/api/bff/auth/logout', { cuerpo: {} });
    router.replace('/login');
    router.refresh();
  }

  return (
    <div ref={contenedorRef} className="relative">
      <button
        type="button"
        onClick={() => setAbierto((v) => !v)}
        aria-expanded={abierto}
        aria-haspopup="menu"
        aria-controls={idMenu}
        className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-panel-text outline-offset-2 hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-academico-700"
      >
        <User className="h-4 w-4 shrink-0 text-panel-muted" aria-hidden="true" />
        <span className="hidden max-w-44 truncate sm:inline">{usuario.nombre}</span>
        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-panel-muted" aria-hidden="true" />
      </button>

      {abierto ? (
        <div
          id={idMenu}
          role="menu"
          aria-label="Menú de cuenta"
          className="absolute right-0 top-full z-50 mt-2 w-60 rounded-xl border border-panel-border bg-panel-surface py-1.5 shadow-xl"
        >
          <div className="border-b border-panel-border px-3.5 py-2">
            <p className="truncate text-sm font-semibold text-panel-text">{usuario.nombre}</p>
            <p className="truncate text-xs text-panel-muted">{usuario.email}</p>
          </div>

          <Link
            role="menuitem"
            href={`/admin/${tono}/perfil`}
            onClick={() => setAbierto(false)}
            className="flex items-center gap-2 px-3.5 py-2 text-sm text-panel-text hover:bg-panel-bg"
          >
            <User className="h-4 w-4 text-panel-muted" aria-hidden="true" />
            <span>Mi perfil</span>
          </Link>

          <Link
            role="menuitem"
            href={`/admin/${tono}/notificaciones`}
            onClick={() => setAbierto(false)}
            className="flex items-center gap-2 px-3.5 py-2 text-sm text-panel-text hover:bg-panel-bg"
          >
            <Bell className="h-4 w-4 text-panel-muted" aria-hidden="true" />
            <span>Notificaciones</span>
          </Link>

          <div className="my-1 border-t border-panel-border" />

          <button
            type="button"
            role="menuitem"
            onClick={salir}
            disabled={saliendo}
            className="flex w-full items-center gap-2 px-3.5 py-2 text-left text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-60"
          >
            <LogOut className="h-4 w-4" aria-hidden="true" />
            <span>{saliendo ? 'Cerrando sesión…' : 'Cerrar sesión'}</span>
          </button>
        </div>
      ) : null}
    </div>
  );
}
