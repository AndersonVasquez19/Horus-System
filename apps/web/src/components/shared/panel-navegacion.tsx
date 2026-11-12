'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Menu, X } from 'lucide-react';
import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { ICONOS_PANEL } from './panel-iconos';
import type { ElementoNavegacion, ModeloNavegacion, TonoPanel } from './panel-tipos';

/*
 * Las clases se escriben completas (no se componen) para que Tailwind las detecte.
 * Foco con 700 (contraste ≥ 5,4:1 sobre blanco y sobre panel-bg); ver globals.css.
 */
const TONOS: Record<TonoPanel, { foco: string; reposo: string; activo: string }> = {
  academico: {
    foco: 'focus-visible:outline-academico-700',
    reposo: 'hover:bg-academico-50 hover:text-academico-800',
    activo: 'border-academico-700 bg-academico-100 text-academico-900',
  },
  servicios: {
    foco: 'focus-visible:outline-servicios-700',
    reposo: 'hover:bg-servicios-50 hover:text-servicios-800',
    activo: 'border-servicios-700 bg-servicios-100 text-servicios-900',
  },
};

/** `exacta`: es la página actual; `prefijo`: es una subruta de la entrada; `null`: inactiva. */
function coincidencia(ruta: string, elemento: ElementoNavegacion): 'exacta' | 'prefijo' | null {
  const href = elemento.href.replace(/\/+$/, '') || '/';
  if (ruta === href) return 'exacta';
  if ((elemento.coincidencia ?? 'prefijo') === 'prefijo' && ruta.startsWith(`${href}/`)) return 'prefijo';
  return null;
}

interface PanelNavegacionProps {
  tono: TonoPanel;
  titulo: string;
  modelo: ModeloNavegacion;
}

/**
 * Barra lateral de un panel: grupos, estado activo con `aria-current` y menú plegable en móvil.
 *
 * Recibe el modelo ya resuelto y no lee sesión ni permisos: el filtrado por permisos, cuando
 * exista, debe ocurrir antes (en servidor) y entregar aquí solo lo que el usuario puede ver.
 */
export function PanelNavegacion({ tono, titulo, modelo }: PanelNavegacionProps) {
  const ruta = usePathname();
  const [abierto, setAbierto] = useState(false);
  const idMenu = useId();
  const botonRef = useRef<HTMLButtonElement>(null);
  const t = TONOS[tono];
  const hayElementos = modelo.some((grupo) => grupo.elementos.length > 0);

  function alPulsarTecla(evento: KeyboardEvent<HTMLElement>) {
    if (evento.key === 'Escape' && abierto) {
      setAbierto(false);
      botonRef.current?.focus();
    }
  }

  if (!hayElementos) {
    return (
      <nav aria-label={`Navegación de ${titulo}`} className="p-3">
        <p className="px-3 py-2 text-sm text-panel-muted">Sin módulos disponibles todavía.</p>
      </nav>
    );
  }

  return (
    <nav aria-label={`Navegación de ${titulo}`} className="p-3" onKeyDown={alPulsarTecla}>
      <button
        ref={botonRef}
        type="button"
        aria-expanded={abierto}
        aria-controls={idMenu}
        onClick={() => setAbierto((valor) => !valor)}
        className={`flex w-full items-center justify-between rounded-md border border-panel-border px-3 py-2 text-sm font-medium text-panel-text outline-offset-2 focus-visible:outline-2 md:hidden ${t.foco}`}
      >
        <span>Menú de {titulo}</span>
        {abierto ? <X className="h-4 w-4" aria-hidden="true" /> : <Menu className="h-4 w-4" aria-hidden="true" />}
      </button>

      <div id={idMenu} className={`${abierto ? 'block' : 'hidden'} mt-2 md:mt-0 md:block`}>
        {modelo.map((grupo) =>
          grupo.elementos.length === 0 ? null : (
            <div key={grupo.id}>
              {grupo.etiqueta ? (
                <p
                  id={`${idMenu}-${grupo.id}`}
                  className="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-panel-muted"
                >
                  {grupo.etiqueta}
                </p>
              ) : null}
              <ul aria-labelledby={grupo.etiqueta ? `${idMenu}-${grupo.id}` : undefined} className="space-y-1">
                {grupo.elementos.map((elemento) => {
                  const estado = coincidencia(ruta, elemento);
                  const Icono = elemento.icono ? ICONOS_PANEL[elemento.icono] : null;
                  return (
                    <li key={elemento.id}>
                      <Link
                        href={elemento.href}
                        aria-current={estado === 'exacta' ? 'page' : estado === 'prefijo' ? 'true' : undefined}
                        onClick={() => setAbierto(false)}
                        className={`flex items-center gap-2 rounded-md border-l-4 px-3 py-2 text-sm outline-offset-2 focus-visible:outline-2 ${t.foco} ${
                          estado ? `${t.activo} font-semibold` : `border-transparent font-medium text-panel-muted ${t.reposo}`
                        }`}
                      >
                        {Icono ? <Icono className="h-4 w-4 shrink-0" aria-hidden="true" /> : null}
                        <span>{elemento.etiqueta}</span>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          ),
        )}
      </div>
    </nav>
  );
}
