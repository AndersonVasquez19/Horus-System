'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Bell, ChevronDown, LogOut, User } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { llamarBff } from '@/lib/panel/cliente';
import type { UsuarioSesion } from '@/lib/panel/tipos';
import type { TonoPanel } from './panel-tipos';
import { useNavegacionMenu } from './ui/use-navegacion-menu';

/** Mismo criterio de foco y objetivo táctil que el menú de fila. */
const CLASE_ITEM =
  'flex min-h-10 w-full items-center gap-2 px-3.5 py-2 text-left text-sm transition focus-visible:-outline-offset-2 focus-visible:outline-2';

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
  const botonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const idMenu = useId();

  const cerrar = useCallback(() => setAbierto(false), []);
  const devolverFoco = useCallback(() => botonRef.current?.focus(), []);
  const { alPulsarTecla, enfocarPrimero } = useNavegacionMenu({ menuRef, cerrar, devolverFoco });

  useEffect(() => {
    if (!abierto) return undefined;
    enfocarPrimero();
    function alPulsar(e: MouseEvent) {
      if (contenedorRef.current && !contenedorRef.current.contains(e.target as Node)) {
        setAbierto(false);
      }
    }
    // Escape también cierra si el foco quedó en el botón (menú abierto con el ratón).
    function alTeclear(e: globalThis.KeyboardEvent) {
      if (e.key === 'Escape') {
        setAbierto(false);
        botonRef.current?.focus();
      }
    }
    document.addEventListener('mousedown', alPulsar);
    document.addEventListener('keydown', alTeclear);
    return () => {
      document.removeEventListener('mousedown', alPulsar);
      document.removeEventListener('keydown', alTeclear);
    };
  }, [abierto, enfocarPrimero]);

  function alPulsarBoton(evento: KeyboardEvent<HTMLButtonElement>) {
    if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
      evento.preventDefault();
      setAbierto(true);
    }
  }

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
        ref={botonRef}
        type="button"
        onClick={() => setAbierto((v) => !v)}
        onKeyDown={alPulsarBoton}
        aria-expanded={abierto}
        aria-haspopup="menu"
        aria-controls={abierto ? idMenu : undefined}
        className="inline-flex min-h-10 items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-panel-text outline-offset-2 hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-academico-700 sm:min-h-0"
      >
        <User className="h-4 w-4 shrink-0 text-panel-muted" aria-hidden="true" />
        <span className="sr-only max-w-44 truncate sm:not-sr-only sm:inline">{usuario.nombre}</span>
        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-panel-muted" aria-hidden="true" />
      </button>

      {abierto ? (
        <div
          ref={menuRef}
          id={idMenu}
          role="menu"
          aria-label="Menú de cuenta"
          onKeyDown={alPulsarTecla}
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
            className={`${CLASE_ITEM} text-panel-text hover:bg-panel-bg focus-visible:bg-academico-50 focus-visible:outline-academico-700`}
          >
            <User className="h-4 w-4 text-panel-muted" aria-hidden="true" />
            <span>Mi perfil</span>
          </Link>

          <Link
            role="menuitem"
            href={`/admin/${tono}/notificaciones`}
            onClick={() => setAbierto(false)}
            className={`${CLASE_ITEM} text-panel-text hover:bg-panel-bg focus-visible:bg-academico-50 focus-visible:outline-academico-700`}
          >
            <Bell className="h-4 w-4 text-panel-muted" aria-hidden="true" />
            <span>Notificaciones</span>
          </Link>

          <div role="separator" className="my-1 border-t border-panel-border" />

          <button
            type="button"
            role="menuitem"
            onClick={salir}
            disabled={saliendo}
            className={`${CLASE_ITEM} font-medium text-red-700 hover:bg-red-50 focus-visible:bg-red-50 focus-visible:outline-estado-error disabled:opacity-60`}
          >
            <LogOut className="h-4 w-4" aria-hidden="true" />
            <span>{saliendo ? 'Cerrando sesión…' : 'Cerrar sesión'}</span>
          </button>
        </div>
      ) : null}
    </div>
  );
}
