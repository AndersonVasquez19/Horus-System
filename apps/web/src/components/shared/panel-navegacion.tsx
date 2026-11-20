'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Menu, X } from 'lucide-react';
import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { ICONOS_PANEL } from './panel-iconos';
import type { ElementoNavegacion, InsigniasNavegacion, ModeloNavegacion } from './panel-tipos';

/*
 * Las clases se escriben completas (no se componen) para que Tailwind las detecte.
 * Riel oscuro (tinta): foco en oro claro (≥ 9:1 sobre tinta); la sección activa lleva una marca dorada a la izquierda.
 */
const ESTILO_RIEL = {
  foco: 'focus-visible:outline-oro-300',
  reposo: 'hover:bg-white/10 hover:text-white',
  activo: 'border-oro-400 bg-white/10 text-white',
} as const;

/** `exacta`: es la página actual; `prefijo`: es una subruta de la entrada; `null`: inactiva. */
function coincidencia(ruta: string, elemento: ElementoNavegacion): 'exacta' | 'prefijo' | null {
  const href = elemento.href.replace(/\/+$/, '') || '/';
  if (ruta === href) return 'exacta';
  if ((elemento.coincidencia ?? 'prefijo') === 'prefijo' && ruta.startsWith(`${href}/`)) return 'prefijo';
  return null;
}

interface PanelNavegacionProps {
  titulo: string;
  modelo: ModeloNavegacion;
  insignias?: InsigniasNavegacion;
}

/**
 * Barra lateral de un panel: grupos, estado activo con `aria-current` y menú plegable en móvil.
 *
 * Recibe el modelo ya resuelto y no lee sesión ni permisos: el filtrado por permisos, cuando
 * exista, debe ocurrir antes (en servidor) y entregar aquí solo lo que el usuario puede ver.
 */
export function PanelNavegacion({ titulo, modelo, insignias = {} }: PanelNavegacionProps) {
  const ruta = usePathname();
  const [abierto, setAbierto] = useState(false);
  const idMenu = useId();
  const botonRef = useRef<HTMLButtonElement>(null);
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
        <p className="px-3 py-2 text-sm text-white/70">Sin módulos disponibles todavía.</p>
      </nav>
    );
  }

  return (
    <nav aria-label={`Navegación de ${titulo}`} className="relative p-3 pb-6" onKeyDown={alPulsarTecla}>
      {/* Fondo atenuado del menú móvil: pulsar fuera lo cierra. Solo existe mientras el menú está abierto. */}
      {abierto ? <div aria-hidden="true" onClick={() => setAbierto(false)} className="fixed inset-0 z-30 bg-slate-950/50 md:hidden" /> : null}
      <button
        ref={botonRef}
        type="button"
        aria-expanded={abierto}
        aria-controls={idMenu}
        onClick={() => setAbierto((valor) => !valor)}
        className={`relative z-40 flex min-h-10 w-full items-center justify-between rounded-md border border-white/25 px-3 py-2 text-sm font-medium text-white outline-offset-2 focus-visible:outline-2 md:hidden ${ESTILO_RIEL.foco}`}
      >
        <span>Menú de {titulo}</span>
        {abierto ? <X className="h-4 w-4" aria-hidden="true" /> : <Menu className="h-4 w-4" aria-hidden="true" />}
      </button>

      {/* En móvil el menú se superpone al contenido (panel flotante bajo el botón); desde `md` es el riel fijo de siempre. */}
      <div
        id={idMenu}
        className={`${abierto ? 'block' : 'hidden'} max-md:absolute max-md:inset-x-3 max-md:top-[calc(100%-1.5rem)] max-md:z-40 max-md:max-h-[70dvh] max-md:overflow-y-auto max-md:rounded-xl max-md:bg-tinta max-md:px-2 max-md:pb-3 max-md:shadow-2xl max-md:ring-1 max-md:ring-white/20 md:mt-0 md:block`}
      >
        {modelo.map((grupo) =>
          grupo.elementos.length === 0 ? null : (
            <div key={grupo.id}>
              {grupo.etiqueta ? (
                <p
                  id={`${idMenu}-${grupo.id}`}
                  className="px-3 pb-1 pt-5 text-xs font-medium text-white/55"
                >
                  {grupo.etiqueta}
                </p>
              ) : null}
              <ul aria-labelledby={grupo.etiqueta ? `${idMenu}-${grupo.id}` : undefined} className="space-y-1">
                {grupo.elementos.map((elemento) => {
                  const estado = coincidencia(ruta, elemento);
                  const Icono = elemento.icono ? ICONOS_PANEL[elemento.icono] : null;
                  const insignia = insignias[elemento.id];
                  return (
                    <li key={elemento.id}>
                      <Link
                        href={elemento.href}
                        aria-current={estado === 'exacta' ? 'page' : estado === 'prefijo' ? 'true' : undefined}
                        onClick={() => setAbierto(false)}
                        className={`flex items-center justify-between gap-2 rounded-md border-l-[3px] px-3 py-2 text-sm outline-offset-2 focus-visible:outline-2 ${ESTILO_RIEL.foco} ${
                          estado ? `${ESTILO_RIEL.activo} font-semibold` : `border-transparent font-medium text-white/75 ${ESTILO_RIEL.reposo}`
                        }`}
                      >
                        <span className="flex min-w-0 items-center gap-2">
                          {Icono ? <Icono className="h-4 w-4 shrink-0" aria-hidden="true" /> : null}
                          <span className="truncate">{elemento.etiqueta}</span>
                        </span>
                        {typeof insignia === 'number' && insignia > 0 ? (
                          <span className="inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-oro-400/20 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-oro-200">
                            <span aria-hidden="true">{insignia > 99 ? '99+' : insignia}</span>
                            <span className="sr-only">{`${insignia} pendiente(s)`}</span>
                          </span>
                        ) : null}
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
