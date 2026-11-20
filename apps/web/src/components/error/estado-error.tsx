import type { ReactNode } from 'react';

interface EstadoErrorProps {
  codigo: string;
  titulo: string;
  texto: string;
  /** Acciones (enlaces o botones). */
  children: ReactNode;
  /** Identificador del error del servidor, para cruzarlo con los registros (nunca el mensaje). */
  referencia?: string;
}

/**
 * Pantalla de error con la marca de Horus: tarjeta blanca sobre el fondo azul tinta del sitio, línea dorada y código
 * grande. Texto en español, sin detalles técnicos. La usan `not-found.tsx` y `error.tsx`.
 */
export function EstadoError({ codigo, titulo, texto, children, referencia }: EstadoErrorProps) {
  return (
    <section className="flex min-h-[70vh] items-center justify-center px-4 py-24">
      <div className="w-full max-w-lg rounded-3xl border border-gold/25 bg-white p-8 text-ink shadow-[0_35px_100px_rgba(0,0,0,0.35)] sm:p-10">
        <p aria-hidden="true" className="font-heading text-6xl font-bold leading-none tracking-tight text-gold-dark">
          {codigo}
        </p>
        <span aria-hidden="true" className="mt-5 block h-0.5 w-11 bg-gradient-to-r from-gold to-gold/15" />
        <h1 className="mt-5 font-heading text-2xl font-bold tracking-tight">{titulo}</h1>
        <p className="mt-2 text-sm leading-6 text-slate-600">{texto}</p>
        <div className="mt-7 flex flex-wrap items-center gap-3">{children}</div>
        {referencia ? <p className="mt-6 text-xs text-slate-500">Referencia del error: {referencia}</p> : null}
      </div>
    </section>
  );
}

export const CLASE_BOTON_PRIMARIO =
  'inline-flex items-center rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white outline-offset-2 transition hover:bg-ink-light focus-visible:outline-2 focus-visible:outline-gold';
export const CLASE_ENLACE =
  'rounded text-sm font-medium text-ink underline underline-offset-4 outline-offset-2 hover:text-ink-light focus-visible:outline-2 focus-visible:outline-gold';
