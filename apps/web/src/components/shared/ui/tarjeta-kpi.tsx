import type { ReactNode } from 'react';
import type { TonoUi } from './boton';

const ICONO: Record<TonoUi, string> = {
  academico: 'bg-academico-100 text-academico-800',
  servicios: 'bg-servicios-100 text-servicios-800',
};

interface TarjetaKpiProps {
  tono: TonoUi;
  etiqueta: string;
  valor: string;
  detalle?: string;
  icono: ReactNode;
}

export function TarjetaKpi({ tono, etiqueta, valor, detalle, icono }: TarjetaKpiProps) {
  return (
    <div className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-medium text-panel-muted">{etiqueta}</p>
          <p className="mt-2 font-heading text-3xl font-bold tracking-tight text-panel-text">{valor}</p>
        </div>
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${ICONO[tono]}`} aria-hidden="true">
          {icono}
        </span>
      </div>
      {detalle ? <p className="mt-3 text-xs text-panel-muted">{detalle}</p> : null}
    </div>
  );
}
