import type { InputHTMLAttributes, ReactNode } from 'react';

interface CasillaProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'id'> {
  etiqueta: ReactNode;
  /** Texto de apoyo bajo la etiqueta; forma parte del nombre accesible. */
  ayuda?: string;
  /** Filas más bajas para listas largas (p. ej. la matriz de permisos). El objetivo táctil sigue siendo toda la fila. */
  compacta?: boolean;
}

/**
 * Casilla de verificación con etiqueta clicable y objetivo táctil de al menos 40 px de alto (32 px en `compacta`). El
 * color lo pone la regla base del panel (`accent-color`). Sirve con `register(...)` de react-hook-form y como control
 * manual (`checked` + `onChange`).
 */
export function Casilla({ etiqueta, ayuda, compacta = false, className = '', disabled, ...resto }: CasillaProps) {
  const tamano = compacta ? 'min-h-8 py-1' : 'min-h-10 py-2';
  const estado = disabled ? 'cursor-not-allowed text-panel-muted opacity-60' : 'cursor-pointer text-panel-text';
  return (
    <label className={`flex items-start gap-3 rounded-lg px-1 text-sm ${tamano} ${estado} ${className}`}>
      <input type="checkbox" disabled={disabled} className="mt-0.5 h-5 w-5 shrink-0 rounded border-panel-border" {...resto} />
      <span>
        {etiqueta}
        {ayuda ? <span className="mt-0.5 block text-xs text-panel-muted">{ayuda}</span> : null}
      </span>
    </label>
  );
}
