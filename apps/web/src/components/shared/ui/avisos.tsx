'use client';

import { createContext, use, useCallback, useEffect, useMemo, useRef, type CSSProperties, type ReactNode } from 'react';
import { Toaster, toast } from 'sonner';

type TipoAviso = 'exito' | 'error' | 'info' | 'aviso';

interface ContextoAvisos {
  mostrar: (tipo: TipoAviso, texto: string) => void;
}

const Contexto = createContext<ContextoAvisos | null>(null);
const DURACION_MS = 6000;

/* Texto 800 sobre fondo 50 (contraste ≥ 7:1, igual que `Insignia`). `richColors` de sonner los aplica por tipo. */
const COLORES = {
  '--normal-bg': 'var(--color-panel-surface)',
  '--normal-border': 'var(--color-panel-border)',
  '--normal-text': 'var(--color-panel-text)',
  '--success-bg': '#F0FDF4',
  '--success-border': '#BBF7D0',
  '--success-text': '#166534',
  '--error-bg': '#FEF2F2',
  '--error-border': '#FECACA',
  '--error-text': '#991B1B',
  '--warning-bg': '#FFFBEB',
  '--warning-border': '#FDE68A',
  '--warning-text': '#92400E',
  '--info-bg': '#EFF6FF',
  '--info-border': '#BFDBFE',
  '--info-text': '#1E40AF',
  fontFamily: 'var(--font-sans)',
} as CSSProperties;

/**
 * Avisos breves tras una operación (toasts), sobre `sonner`. No sustituyen a los errores en línea de los formularios.
 *
 * - Éxito, aviso e información se cierran solos a los 6 s; **los errores permanecen** hasta cerrarlos. Pasar el
 *   ratón o enfocar un aviso pausa su cierre (WCAG 2.2.1).
 * - Un aviso idéntico a uno visible no se apila: se actualiza el existente (el `id` es tipo + texto).
 * - `sonner` anuncia los avisos con una región `aria-live="polite"`.
 * - Los diálogos modales (`<dialog>.showModal()`) viven en la capa superior del navegador y un `z-index` no los
 *   supera. Por eso el `Toaster` va dentro de un `popover` manual que se reabre en cada aviso para quedar por
 *   encima del último diálogo abierto. Mientras ese diálogo esté abierto el resto de la página es inerte: los
 *   avisos se ven, pero no se pueden pulsar hasta cerrarlo.
 */
export function ProveedorAvisos({ children }: { children: ReactNode }) {
  const capa = useRef<HTMLDivElement>(null);

  /** Lleva la capa de avisos al frente de la capa superior; sin soporte de `popover` no hace nada. */
  const elevar = useCallback(() => {
    const elemento = capa.current;
    if (!elemento || typeof elemento.showPopover !== 'function') return;
    try {
      if (elemento.matches(':popover-open')) elemento.hidePopover();
      elemento.showPopover();
    } catch {
      // El Toaster sigue visible con su `position: fixed` habitual.
    }
  }, []);

  // La capa permanece abierta: así la región `aria-live` de sonner nunca queda oculta al anunciar.
  useEffect(() => {
    elevar();
  }, [elevar]);

  const mostrar = useCallback(
    (tipo: TipoAviso, texto: string) => {
      elevar();
      const opciones = { id: `${tipo}:${texto}`, duration: tipo === 'error' ? Number.POSITIVE_INFINITY : DURACION_MS };
      if (tipo === 'exito') toast.success(texto, opciones);
      else if (tipo === 'error') toast.error(texto, opciones);
      else if (tipo === 'aviso') toast.warning(texto, opciones);
      else toast.info(texto, opciones);
    },
    [elevar],
  );
  const valor = useMemo(() => ({ mostrar }), [mostrar]);

  return (
    <Contexto value={valor}>
      {children}
      <div
        ref={capa}
        popover="manual"
        className="pointer-events-none m-0 h-0 w-0 overflow-visible border-0 bg-transparent p-0"
      >
        <Toaster
          position="bottom-right"
          theme="light"
          richColors
          closeButton
          visibleToasts={3}
          offset={16}
          mobileOffset={16}
          containerAriaLabel="Avisos"
          style={COLORES}
        />
      </div>
    </Contexto>
  );
}

export function useAvisos(): ContextoAvisos {
  const contexto = use(Contexto);
  if (contexto === null) throw new Error('useAvisos debe usarse dentro de ProveedorAvisos.');
  return contexto;
}
