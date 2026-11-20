'use client';

import { FileText, ImageOff } from 'lucide-react';
import { useState } from 'react';
import { Dialogo } from '@/components/shared/ui/dialogo';

/**
 * Miniatura del comprobante de una fila de Pagos. La imagen es privada: la sirve el BFF con la sesión (no hay URL pública) y
 * Laravel exige `pagos.ver`. Al pulsarla se ve grande y se puede descargar. Sin comprobante, muestra un aviso en su lugar.
 */
export function MiniaturaComprobante({ identificador, tiene, estudiante, tipo }: { identificador: string; tiene: boolean; estudiante: string; tipo: 'imagen' | 'pdf' | null }) {
  const [viendo, setViendo] = useState(false);
  const [fallo, setFallo] = useState(false);
  const url = `/api/bff/panel/academico/pagos/transacciones/${identificador}/comprobante`;

  // Un PDF solo se descarga: nunca se abre ni se previsualiza dentro de la página (puede traer contenido activo).
  if (tiene && tipo === 'pdf') {
    return (
      <a
        href={url}
        download="comprobante-de-pago.pdf"
        aria-label={`Descargar comprobante PDF de ${estudiante}`}
        title="Descargar comprobante (PDF)"
        className="inline-flex h-12 w-12 flex-col items-center justify-center rounded-lg border border-panel-border bg-panel-bg text-panel-muted transition hover:border-academico-400"
      >
        <FileText className="h-5 w-5" aria-hidden="true" />
        <span className="text-[10px] font-semibold leading-none">PDF</span>
      </a>
    );
  }

  if (!tiene) {
    return (
      <span className="inline-flex h-12 w-12 items-center justify-center rounded-lg border border-dashed border-panel-border text-panel-muted" title="Sin comprobante">
        <ImageOff className="h-5 w-5" aria-hidden="true" />
        <span className="sr-only">Sin comprobante</span>
      </span>
    );
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setViendo(true)}
        aria-label={`Ver comprobante de ${estudiante}`}
        className="inline-flex h-12 w-12 items-center justify-center overflow-hidden rounded-lg border border-panel-border bg-panel-bg transition hover:border-academico-400"
      >
        {fallo ? (
          <FileText className="h-5 w-5 text-panel-muted" aria-hidden="true" />
        ) : (
          // Imagen privada servida por el BFF: no se puede optimizar con next/image ni tiene URL pública.
          // eslint-disable-next-line @next/next/no-img-element
          <img src={url} alt="" loading="lazy" onError={() => setFallo(true)} className="h-full w-full object-cover" />
        )}
      </button>
      <Dialogo abierto={viendo} titulo="Comprobante de pago" descripcion={estudiante} onCerrar={() => setViendo(false)}>
        {viendo ? (
          <>
            <p className="mb-3 text-right">
              <a href={url} download={`comprobante-de-pago-${identificador}`} className="text-sm font-semibold text-panel-text underline">Descargar imagen</a>
            </p>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={url} alt={`Comprobante de pago de ${estudiante}`} className="mx-auto max-h-[70vh] w-auto rounded-lg border border-panel-border" />
          </>
        ) : null}
      </Dialogo>
    </>
  );
}
