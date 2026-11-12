'use client';

import { useLayoutEffect, useRef, type ReactNode } from 'react';

interface MarcaPanelProps {
  /** Atributo marcador que usan las reglas de aislamiento de globals.css. */
  atributo: 'data-panel-root' | 'data-panel-tono';
  valor?: string;
  className?: string;
  children: ReactNode;
}

/**
 * Contenedor que lleva un atributo marcador solo mientras está en pantalla.
 *
 * Con `cacheComponents`, al navegar fuera de un panel Next conserva su subárbol en el DOM,
 * oculto con `display: none`. Un marcador fijo en el HTML seguiría casando con `:has()` y el
 * tema del panel se filtraría a las páginas públicas. React limpia los efectos de un subárbol
 * oculto y los vuelve a ejecutar al mostrarlo: el atributo se retira al ocultarse y se repone
 * antes de pintar al volver (efecto de layout: sin parpadeo).
 *
 * El atributo también se renderiza en el servidor, para que el primer pintado ya esté aislado
 * sin esperar a la hidratación.
 */
export function MarcaPanel({ atributo, valor = '', className, children }: MarcaPanelProps) {
  const ref = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const elemento = ref.current;
    if (!elemento) return;
    elemento.setAttribute(atributo, valor);
    return () => elemento.removeAttribute(atributo);
  }, [atributo, valor]);

  return (
    <div ref={ref} {...{ [atributo]: valor }} className={className}>
      {children}
    </div>
  );
}
