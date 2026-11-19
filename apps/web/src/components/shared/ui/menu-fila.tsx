'use client';

import Link from 'next/link';
import { MoreHorizontal } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { useAvisos } from './avisos';

export type ElementoMenuFila =
  | { tipo: 'enlace'; etiqueta: string; href: string; externo?: boolean }
  | { tipo: 'copiar'; etiqueta: string; valor: string; aviso?: string }
  | { tipo: 'accion'; etiqueta: string; onClick: () => void; peligro?: boolean };

interface MenuFilaProps {
  etiqueta?: string;
  elementos: readonly ElementoMenuFila[];
}

interface PosicionMenu {
  top: number;
  left: number;
}

/**
 * Menú contextual accesible por fila (`role="menu"`). Se posiciona en coordenadas fijas del viewport para no quedar
 * recortado dentro de tablas con `overflow-x-auto`.
 */
export function MenuFila({ etiqueta = 'Más acciones', elementos }: MenuFilaProps) {
  const { mostrar } = useAvisos();
  const idMenu = useId();
  const [abierto, setAbierto] = useState(false);
  const [posicion, setPosicion] = useState<PosicionMenu>({ top: 0, left: 0 });
  const contenedorRef = useRef<HTMLDivElement>(null);
  const botonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!abierto) return;

    function recalcular() {
      const rect = botonRef.current?.getBoundingClientRect();
      if (!rect) return;
      const anchoMenu = 224;
      const altoEstimado = Math.min(320, elementos.length * 38 + 16);
      const cabeAbajo = rect.bottom + 6 + altoEstimado <= window.innerHeight;
      const top = cabeAbajo ? rect.bottom + 6 : Math.max(8, rect.top - altoEstimado - 6);
      const left = Math.max(8, Math.min(rect.right - anchoMenu, window.innerWidth - anchoMenu - 8));
      setPosicion({ top, left });
    }

    recalcular();
    const primerItem = menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]');
    primerItem?.focus();

    function alClickFuera(evento: MouseEvent) {
      if (!contenedorRef.current?.contains(evento.target as Node)) {
        setAbierto(false);
      }
    }
    function alDesplazar() {
      setAbierto(false);
    }

    document.addEventListener('mousedown', alClickFuera);
    window.addEventListener('scroll', alDesplazar, true);
    window.addEventListener('resize', alDesplazar);
    return () => {
      document.removeEventListener('mousedown', alClickFuera);
      window.removeEventListener('scroll', alDesplazar, true);
      window.removeEventListener('resize', alDesplazar);
    };
  }, [abierto, elementos.length]);

  if (elementos.length === 0) return null;

  function alPulsarTecla(evento: KeyboardEvent<HTMLDivElement>) {
    if (evento.key === 'Escape') {
      evento.preventDefault();
      setAbierto(false);
      botonRef.current?.focus();
      return;
    }
    if (evento.key === 'Tab') {
      setAbierto(false);
      return;
    }
    const items = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
    if (items.length === 0) return;
    const actual = items.indexOf(document.activeElement as HTMLElement);
    if (evento.key === 'ArrowDown') {
      evento.preventDefault();
      items[(actual + 1) % items.length]?.focus();
    } else if (evento.key === 'ArrowUp') {
      evento.preventDefault();
      items[(actual - 1 + items.length) % items.length]?.focus();
    } else if (evento.key === 'Home') {
      evento.preventDefault();
      items[0]?.focus();
    } else if (evento.key === 'End') {
      evento.preventDefault();
      items[items.length - 1]?.focus();
    }
  }

  function copiar(valor: string, aviso?: string) {
    setAbierto(false);
    if (typeof navigator === 'undefined' || !navigator.clipboard) {
      mostrar('error', 'El portapapeles no está disponible.');
      return;
    }
    navigator.clipboard.writeText(valor).then(
      () => mostrar('exito', aviso ?? 'Copiado al portapapeles.'),
      () => mostrar('error', 'No se pudo copiar al portapapeles.'),
    );
  }

  const claseItem =
    'block w-full px-3 py-2 text-left text-sm text-panel-text transition hover:bg-panel-bg focus:bg-panel-bg focus:outline-none';

  return (
    <div ref={contenedorRef} className="inline-flex justify-end">
      <button
        ref={botonRef}
        type="button"
        aria-haspopup="menu"
        aria-expanded={abierto}
        aria-controls={abierto ? idMenu : undefined}
        aria-label={etiqueta}
        title={etiqueta}
        onClick={() => setAbierto((v) => !v)}
        className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-panel-border bg-panel-surface text-panel-muted hover:bg-panel-bg hover:text-panel-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700"
      >
        <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
      </button>

      {abierto ? (
        <div
          ref={menuRef}
          id={idMenu}
          role="menu"
          aria-orientation="vertical"
          onKeyDown={alPulsarTecla}
          style={{ top: posicion.top, left: posicion.left }}
          className="fixed z-50 w-56 rounded-xl border border-panel-border bg-panel-surface py-1.5 shadow-lg"
        >
          {elementos.map((item, indice) => {
            if (item.tipo === 'enlace') {
              return item.externo ? (
                <a
                  key={`${item.etiqueta}-${indice}`}
                  href={item.href}
                  target={item.href.startsWith('mailto:') ? undefined : '_blank'}
                  rel={item.href.startsWith('mailto:') ? undefined : 'noopener noreferrer'}
                  role="menuitem"
                  onClick={() => setAbierto(false)}
                  className={claseItem}
                >
                  {item.etiqueta}
                </a>
              ) : (
                <Link
                  key={`${item.etiqueta}-${indice}`}
                  href={item.href}
                  role="menuitem"
                  onClick={() => setAbierto(false)}
                  className={claseItem}
                >
                  {item.etiqueta}
                </Link>
              );
            }
            if (item.tipo === 'copiar') {
              return (
                <button
                  key={`${item.etiqueta}-${indice}`}
                  type="button"
                  role="menuitem"
                  onClick={() => copiar(item.valor, item.aviso)}
                  className={claseItem}
                >
                  {item.etiqueta}
                </button>
              );
            }
            return (
              <button
                key={`${item.etiqueta}-${indice}`}
                type="button"
                role="menuitem"
                onClick={() => {
                  setAbierto(false);
                  item.onClick();
                }}
                className={`${claseItem} ${item.peligro ? 'text-estado-error hover:bg-red-50' : ''}`}
              >
                {item.etiqueta}
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}
