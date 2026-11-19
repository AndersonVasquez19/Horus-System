'use client';

import Link from 'next/link';
import { useEffect } from 'react';
import { CLASE_BOTON_PRIMARIO, CLASE_ENLACE, EstadoError } from '@/components/error/estado-error';

/** Error inesperado en una página. No muestra el mensaje del error: solo su referencia (`digest`) para los registros. */
export default function Error({ error, retry }: { error: unknown; retry: () => void }) {
  const referencia = error instanceof globalThis.Error ? (error as globalThis.Error & { digest?: string }).digest : undefined;

  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <EstadoError codigo="500" titulo="Algo salió mal" texto="No pudimos mostrar esta página. Inténtalo de nuevo; si sigue fallando, contáctanos." referencia={referencia}>
      <button type="button" onClick={() => retry()} className={CLASE_BOTON_PRIMARIO}>
        Intentar de nuevo
      </button>
      <Link href="/" className={CLASE_ENLACE}>
        Ir al inicio
      </Link>
    </EstadoError>
  );
}
