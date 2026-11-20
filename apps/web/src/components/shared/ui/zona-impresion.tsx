'use client';

import { useSyncExternalStore, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

const sinSuscripcion = () => () => {};
const destinoCliente = () => document.body;
const destinoServidor = () => null;

/**
 * Contenido pensado para el papel, montado directamente en `<body>` (fuera del panel) y oculto en pantalla.
 * Se imprime con `imprimirSoloZona()`: durante la impresión el resto de la página se oculta y solo queda esta zona.
 * Así «Imprimir» no arrastra el riel, la barra superior ni los filtros. Las reglas están en `globals.css`.
 */
export function ZonaImpresion({ children }: { children: ReactNode }) {
  const destino = useSyncExternalStore(sinSuscripcion, destinoCliente, destinoServidor);
  if (destino === null) return null;
  return createPortal(<div className="zona-impresion">{children}</div>, destino);
}

/** Imprime solo la `ZonaImpresion` montada. La marca temporal se retira cuando el navegador termina de imprimir. */
export function imprimirSoloZona() {
  const clase = 'imprimiendo-zona';
  document.body.classList.add(clase);
  const limpiar = () => {
    document.body.classList.remove(clase);
    window.removeEventListener('afterprint', limpiar);
  };
  window.addEventListener('afterprint', limpiar);
  window.print();
}
