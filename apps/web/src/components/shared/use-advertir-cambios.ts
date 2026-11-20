'use client';

import { useEffect } from 'react';

/**
 * Pide confirmación al cerrar la pestaña, recargar o salir a otro sitio mientras hay cambios sin guardar
 * (`beforeunload`). Es para formularios que viven en una página; los diálogos ya traen su propio aviso.
 *
 * Limitación: el navegador no avisa en la navegación interna de Next (enlaces de la propia app), porque esa
 * navegación no descarga la página.
 */
export function useAdvertirCambios(activo: boolean) {
  useEffect(() => {
    if (!activo) return undefined;
    const avisar = (evento: BeforeUnloadEvent) => {
      evento.preventDefault();
      // Algunos navegadores solo muestran el aviso si `returnValue` tiene un valor; el texto lo fija el navegador.
      evento.returnValue = '';
    };
    window.addEventListener('beforeunload', avisar);
    return () => window.removeEventListener('beforeunload', avisar);
  }, [activo]);
}
