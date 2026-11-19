'use client';

import Link from 'next/link';
import { AlertTriangle, RotateCcw } from 'lucide-react';
import { useEffect } from 'react';
import { Boton } from './boton';

/** Error inesperado dentro del panel: nunca expone el mensaje técnico, solo la referencia (`digest`). */
export function ErrorRutaPanel({
  error,
  reintentar,
  volver = '/admin/academico',
}: {
  error: unknown;
  reintentar?: () => void;
  volver?: string;
}) {
  const referencia = error instanceof globalThis.Error ? (error as globalThis.Error & { digest?: string }).digest : undefined;

  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <div role="alert" className="mx-auto my-8 max-w-lg rounded-2xl border border-panel-border bg-panel-surface p-8 text-center shadow-sm">
      <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-700" aria-hidden="true">
        <AlertTriangle className="h-6 w-6" />
      </div>
      <h1 className="font-heading text-lg font-semibold text-panel-text">Algo salió mal en el panel</h1>
      <p className="mt-2 text-sm text-panel-muted">
        No pudimos mostrar esta sección. Puedes intentarlo de nuevo o volver al inicio del panel.
      </p>
      {referencia ? (
        <p className="mt-3 font-mono text-xs text-panel-muted">Referencia: {referencia}</p>
      ) : null}
      <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
        {reintentar ? (
          <Boton icono={<RotateCcw className="h-4 w-4" aria-hidden="true" />} onClick={reintentar}>
            Intentar de nuevo
          </Boton>
        ) : null}
        <Link
          href={volver}
          className="inline-flex items-center justify-center rounded-lg border border-panel-border bg-panel-surface px-4 py-2 text-sm font-semibold text-panel-text hover:bg-panel-bg"
        >
          Volver al panel
        </Link>
      </div>
    </div>
  );
}
