'use client';

import { RotateCw } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useTransition } from 'react';
import { Boton } from './boton';

/** Vuelve a pedir los datos de la página actual (`router.refresh()`), sin recargar el navegador ni perder los filtros. */
export function BotonReintentar({ etiqueta = 'Reintentar' }: { etiqueta?: string }) {
  const router = useRouter();
  const [pendiente, iniciar] = useTransition();

  return (
    <Boton
      variante="secundario"
      cargando={pendiente}
      icono={<RotateCw className="h-4 w-4" aria-hidden="true" />}
      onClick={() => iniciar(() => router.refresh())}
    >
      {etiqueta}
    </Boton>
  );
}
