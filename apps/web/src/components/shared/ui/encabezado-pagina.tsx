import Link from 'next/link';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';

interface Miga {
  etiqueta: string;
  href?: string;
}

interface EncabezadoPaginaProps {
  titulo: string;
  descripcion?: string;
  migas?: Miga[];
  acciones?: ReactNode;
}

/** Título de página (h1), migas de pan y acciones principales. */
export function EncabezadoPagina({ titulo, descripcion, migas, acciones }: EncabezadoPaginaProps) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="min-w-0">
        {migas && migas.length > 0 ? (
          <nav aria-label="Ruta de navegación" className="mb-2">
            <ol className="flex flex-wrap items-center gap-1 text-xs text-panel-muted">
              {migas.map((miga, indice) => (
                <li key={`${miga.etiqueta}-${indice}`} className="flex items-center gap-1">
                  {indice > 0 ? <ChevronRight className="h-3 w-3" aria-hidden="true" /> : null}
                  {miga.href ? (
                    <Link href={miga.href} className="hover:text-panel-text hover:underline">
                      {miga.etiqueta}
                    </Link>
                  ) : (
                    <span aria-current="page">{miga.etiqueta}</span>
                  )}
                </li>
              ))}
            </ol>
          </nav>
        ) : null}
        <h1 className="font-heading text-2xl font-bold tracking-tight text-panel-text">{titulo}</h1>
        {descripcion ? <p className="mt-1 text-sm text-panel-muted">{descripcion}</p> : null}
      </div>
      {acciones ? <div className="flex flex-wrap items-center gap-2">{acciones}</div> : null}
    </div>
  );
}
