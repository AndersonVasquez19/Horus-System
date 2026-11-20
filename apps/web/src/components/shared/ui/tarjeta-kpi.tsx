import Link from 'next/link';
import type { ReactNode } from 'react';
import type { TonoUi } from './boton';

const REGLA: Record<TonoUi, string> = {
  academico: 'border-t-academico-700',
};

const ICONO: Record<TonoUi, string> = {
  academico: 'text-academico-600',
};

interface TarjetaKpiProps {
  tono: TonoUi;
  etiqueta: string;
  valor: string;
  detalle?: string;
  icono: ReactNode;
  /** Si se indica, la tarjeta completa lleva al listado que explica la cifra. */
  href?: string;
}

/** Cifra del tablero: la regla superior marca el módulo; el número es lo primero que se lee. */
export function TarjetaKpi({ tono, etiqueta, valor, detalle, icono, href }: TarjetaKpiProps) {
  const tarjeta = (
    <div className={`h-full rounded-lg border border-t-[3px] border-panel-border bg-panel-surface p-5 ${REGLA[tono]}${href ? ' transition-colors hover:bg-panel-bg' : ''}`}>
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm font-medium text-panel-muted">{etiqueta}</p>
        <span className={`shrink-0 ${ICONO[tono]}`} aria-hidden="true">
          {icono}
        </span>
      </div>
      <p className="mt-3 font-heading text-4xl font-semibold tracking-tight text-panel-text [font-variant-numeric:tabular-nums]">{valor}</p>
      {detalle ? <p className="mt-3 text-sm leading-snug text-panel-muted">{detalle}</p> : null}
    </div>
  );
  return href ? (
    <Link href={href} className="block h-full rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">
      {tarjeta}
    </Link>
  ) : (
    tarjeta
  );
}
