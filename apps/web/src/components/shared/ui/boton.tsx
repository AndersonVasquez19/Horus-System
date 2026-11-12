import { Loader2 } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';

export type TonoUi = 'academico' | 'servicios';
type Variante = 'primario' | 'secundario' | 'peligro' | 'fantasma';

/* Clases completas para que Tailwind las detecte. Texto blanco sobre 700 (≥ 5,4:1). */
const VARIANTES: Record<Variante, Record<TonoUi, string>> = {
  primario: {
    academico: 'bg-academico-700 text-white hover:bg-academico-800 focus-visible:outline-academico-700',
    servicios: 'bg-servicios-700 text-white hover:bg-servicios-800 focus-visible:outline-servicios-700',
  },
  secundario: {
    academico: 'border border-panel-border bg-panel-surface text-panel-text hover:bg-academico-50 focus-visible:outline-academico-700',
    servicios: 'border border-panel-border bg-panel-surface text-panel-text hover:bg-servicios-50 focus-visible:outline-servicios-700',
  },
  peligro: {
    academico: 'bg-estado-error text-white hover:brightness-90 focus-visible:outline-estado-error',
    servicios: 'bg-estado-error text-white hover:brightness-90 focus-visible:outline-estado-error',
  },
  fantasma: {
    academico: 'text-academico-800 hover:bg-academico-50 focus-visible:outline-academico-700',
    servicios: 'text-servicios-800 hover:bg-servicios-50 focus-visible:outline-servicios-700',
  },
};

interface BotonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  tono?: TonoUi;
  variante?: Variante;
  cargando?: boolean;
  icono?: ReactNode;
}

export function Boton({
  tono = 'academico',
  variante = 'primario',
  cargando = false,
  icono,
  className = '',
  disabled,
  children,
  type = 'button',
  ...resto
}: BotonProps) {
  return (
    <button
      type={type}
      disabled={disabled || cargando}
      aria-busy={cargando || undefined}
      className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold outline-offset-2 transition focus-visible:outline-2 disabled:cursor-not-allowed disabled:opacity-60 ${VARIANTES[variante][tono]} ${className}`}
      {...resto}
    >
      {cargando ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : icono}
      {children}
    </button>
  );
}
