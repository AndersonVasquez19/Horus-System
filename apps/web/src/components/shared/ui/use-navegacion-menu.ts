'use client';

import { useCallback, useRef, type KeyboardEvent, type RefObject } from 'react';

const SELECTOR_ITEMS = '[role="menuitem"]:not([disabled]):not([aria-disabled="true"])';
const PAUSA_BUSQUEDA_MS = 700;

interface Opciones {
  /** Contenedor con `role="menu"` cuyos `[role="menuitem"]` se recorren. */
  menuRef: RefObject<HTMLElement | null>;
  /** Cierra el menú. */
  cerrar: () => void;
  /** Devuelve el foco al botón que abrió el menú (se usa con Escape). */
  devolverFoco: () => void;
}

/**
 * Teclado de un menú accesible (patrón WAI-ARIA «menu button»): flechas, Inicio, Fin, búsqueda por letra, Escape
 * (cierra y devuelve el foco) y Tab (cierra). Compartido por el menú de fila y el menú de usuario.
 */
export function useNavegacionMenu({ menuRef, cerrar, devolverFoco }: Opciones) {
  const busqueda = useRef({ texto: '', temporizador: 0 });

  const items = useCallback(
    () => Array.from(menuRef.current?.querySelectorAll<HTMLElement>(SELECTOR_ITEMS) ?? []),
    [menuRef],
  );

  const enfocarPrimero = useCallback(() => items()[0]?.focus(), [items]);

  const alPulsarTecla = useCallback(
    (evento: KeyboardEvent<HTMLElement>) => {
      if (evento.key === 'Escape') {
        evento.preventDefault();
        cerrar();
        devolverFoco();
        return;
      }
      if (evento.key === 'Tab') {
        cerrar();
        return;
      }
      const lista = items();
      if (lista.length === 0) return;
      const actual = lista.indexOf(document.activeElement as HTMLElement);

      if (evento.key === 'ArrowDown') {
        evento.preventDefault();
        lista[(actual + 1) % lista.length]?.focus();
      } else if (evento.key === 'ArrowUp') {
        evento.preventDefault();
        lista[(actual - 1 + lista.length) % lista.length]?.focus();
      } else if (evento.key === 'Home') {
        evento.preventDefault();
        lista[0]?.focus();
      } else if (evento.key === 'End') {
        evento.preventDefault();
        lista[lista.length - 1]?.focus();
      } else if (evento.key.length === 1 && !evento.ctrlKey && !evento.metaKey && !evento.altKey && evento.key !== ' ') {
        // Búsqueda por letra: acumula lo escrito durante una pausa breve y salta al primer ítem que coincida.
        window.clearTimeout(busqueda.current.temporizador);
        busqueda.current.texto += evento.key.toLowerCase();
        busqueda.current.temporizador = window.setTimeout(() => {
          busqueda.current.texto = '';
        }, PAUSA_BUSQUEDA_MS);
        const prefijo = busqueda.current.texto;
        const orden = [...lista.slice(actual + 1), ...lista.slice(0, actual + 1)];
        const coincidencia = orden.find((item) => (item.textContent ?? '').trim().toLowerCase().startsWith(prefijo));
        if (coincidencia) {
          evento.preventDefault();
          coincidencia.focus();
        }
      }
    },
    [cerrar, devolverFoco, items],
  );

  return { alPulsarTecla, enfocarPrimero };
}
