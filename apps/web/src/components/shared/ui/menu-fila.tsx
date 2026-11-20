'use client';

import Link from 'next/link';
import { ArrowUpRight, ChevronDown, Copy, ExternalLink, Loader2, Mail, MoreHorizontal, type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { useAvisos } from './avisos';
import { useNavegacionMenu } from './use-navegacion-menu';

interface ConIcono {
  /**
   * Icono de lucide; si falta, `copiar` y `enlace` usan uno por defecto.
   * Un icono es una función: solo puede asignarse en menús armados dentro de componentes cliente. Los elementos
   * que una página de servidor pasa como props deben ser serializables y por tanto no pueden llevarlo.
   */
  icono?: LucideIcon;
}

export type ElementoMenuFila =
  | ({ tipo: 'enlace'; etiqueta: string; href: string; externo?: boolean } & ConIcono)
  | ({ tipo: 'copiar'; etiqueta: string; valor: string; aviso?: string } & ConIcono)
  | ({ tipo: 'accion'; etiqueta: string; onClick: () => void; peligro?: boolean } & ConIcono);

interface MenuFilaProps {
  /** Nombre accesible del botón y del menú. Con `disparador`, el botón se nombra por su texto visible. */
  etiqueta?: string;
  elementos: readonly ElementoMenuFila[];
  /**
   * Botón con texto en lugar del icono ⋯ (p. ej. «Exportar» en la cabecera de una página). Se usa el texto visible
   * como nombre accesible: un `aria-label` distinto incumpliría WCAG 2.5.3 (la etiqueta en el nombre).
   */
  disparador?: { texto: string; icono?: LucideIcon };
  /** Muestra un indicador de carga en el disparador y lo deshabilita (operación en curso). */
  cargando?: boolean;
}

type ElementoConEtiqueta = ElementoMenuFila;
type ItemMenu = ElementoMenuFila | { tipo: 'separador' } | { tipo: 'titulo'; etiqueta: string };

const MARGEN = 8;
const HUECO = 6;
const ALTO_MAXIMO = 400;
/** Con tantos ítems o más, cada bloque de contacto y de copiado lleva su encabezado. */
const MINIMO_PARA_TITULOS = 6;

/** Bloque del menú: 0 navegar y editar · 1 contactar (enlaces externos) · 2 copiar · 3 destructivas. */
function bloqueDe(elemento: ElementoMenuFila): 0 | 1 | 2 | 3 {
  if (elemento.tipo === 'accion') return elemento.peligro ? 3 : 0;
  if (elemento.tipo === 'copiar') return 2;
  return elemento.externo ? 1 : 0;
}

const TITULOS_BLOQUE: Partial<Record<number, string>> = { 1: 'Contactar', 2: 'Copiar' };

/**
 * Ordena por bloques (el orden que dio el llamador se respeta dentro de cada bloque), separa los bloques y, en menús
 * largos, los titula. Así todos los listados comparten la misma estructura y lo destructivo queda siempre al final y
 * apartado del resto.
 */
function ordenar(elementos: readonly ElementoMenuFila[]): ItemMenu[] {
  const ordenados = elementos
    .map((elemento, indice) => ({ elemento, indice, bloque: bloqueDe(elemento) }))
    .sort((a, b) => a.bloque - b.bloque || a.indice - b.indice);
  const conTitulos = ordenados.length >= MINIMO_PARA_TITULOS;
  const salida: ItemMenu[] = [];
  let previo = -1;
  for (const { elemento, bloque } of ordenados) {
    if (bloque !== previo) {
      if (previo !== -1) salida.push({ tipo: 'separador' });
      const titulo = TITULOS_BLOQUE[bloque];
      if (conTitulos && titulo) salida.push({ tipo: 'titulo', etiqueta: titulo });
      previo = bloque;
    }
    salida.push(elemento);
  }
  return salida;
}

function esElemento(item: ItemMenu): item is ElementoMenuFila {
  return item.tipo === 'enlace' || item.tipo === 'copiar' || item.tipo === 'accion';
}

function iconoDe(elemento: ElementoConEtiqueta): LucideIcon | undefined {
  if (elemento.icono) return elemento.icono;
  if (elemento.tipo === 'copiar') return Copy;
  if (elemento.tipo === 'enlace') {
    if (!elemento.externo) return ArrowUpRight;
    return elemento.href.startsWith('mailto:') ? Mail : ExternalLink;
  }
  return undefined;
}

/**
 * Menú contextual accesible por fila (`role="menu"`). Se posiciona en coordenadas fijas del viewport para no quedar
 * recortado dentro de tablas con `overflow-x-auto`; mide su altura real, elige arriba o abajo según el espacio y,
 * si no cabe, limita su altura y desplaza internamente (el desplazamiento dentro del menú no lo cierra).
 */
export function MenuFila({ etiqueta = 'Más acciones', elementos, disparador, cargando = false }: MenuFilaProps) {
  const { mostrar } = useAvisos();
  const idMenu = useId();
  const [abierto, setAbierto] = useState(false);
  /** Dónde se pinta el panel: fuera de la tabla, para que ninguna celda (p. ej. la columna fija) lo tape. */
  const [destino, setDestino] = useState<HTMLElement | null>(null);
  const contenedorRef = useRef<HTMLDivElement>(null);
  const botonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  const lista = useMemo(() => ordenar(elementos), [elementos]);
  const hayAcciones = lista.some(esElemento);
  const hayIconos = lista.some((item) => esElemento(item) && iconoDe(item) !== undefined);

  const cerrar = useCallback(() => setAbierto(false), []);
  const devolverFoco = useCallback(() => botonRef.current?.focus(), []);
  const { alPulsarTecla, enfocarPrimero } = useNavegacionMenu({ menuRef, cerrar, devolverFoco });

  /** Aplica la posición directamente al DOM: depende de la altura medida y no debe provocar otro render. */
  const posicionar = useCallback(() => {
    const boton = botonRef.current;
    const menu = menuRef.current;
    if (!boton || !menu) return;
    const rect = boton.getBoundingClientRect();
    const maximo = Math.min(ALTO_MAXIMO, window.innerHeight - MARGEN * 2);
    menu.style.maxHeight = `${maximo}px`;
    const natural = Math.min(menu.scrollHeight, maximo);
    const abajo = window.innerHeight - rect.bottom - HUECO - MARGEN;
    const arriba = rect.top - HUECO - MARGEN;

    let top: number;
    let alto = natural;
    if (natural <= abajo) {
      top = rect.bottom + HUECO;
    } else if (natural <= arriba) {
      top = rect.top - HUECO - natural;
    } else if (abajo >= arriba) {
      alto = Math.max(120, abajo);
      top = rect.bottom + HUECO;
    } else {
      alto = Math.max(120, arriba);
      top = rect.top - HUECO - alto;
    }
    menu.style.maxHeight = `${alto}px`;
    const ancho = menu.offsetWidth;
    menu.style.top = `${Math.max(MARGEN, top)}px`;
    menu.style.left = `${Math.max(MARGEN, Math.min(rect.right - ancho, window.innerWidth - ancho - MARGEN))}px`;
  }, []);

  useLayoutEffect(() => {
    if (abierto) posicionar();
  }, [abierto, posicionar, lista]);

  useEffect(() => {
    if (!abierto) return undefined;
    enfocarPrimero();

    function alClickFuera(evento: MouseEvent) {
      const objetivo = evento.target as Node;
      // El panel vive en un portal: está fuera del contenedor del botón, pero pulsar en él no es «pulsar fuera».
      if (!contenedorRef.current?.contains(objetivo) && !menuRef.current?.contains(objetivo)) setAbierto(false);
    }
    // Desplazar la página cierra el menú (queda desanclado del botón); desplazar el propio menú, no.
    function alDesplazar(evento: Event) {
      if (menuRef.current?.contains(evento.target as Node)) return;
      setAbierto(false);
    }

    document.addEventListener('mousedown', alClickFuera);
    window.addEventListener('scroll', alDesplazar, true);
    // Cambiar el tamaño (p. ej. la barra de direcciones del móvil) reubica el menú en vez de cerrarlo.
    window.addEventListener('resize', posicionar);
    return () => {
      document.removeEventListener('mousedown', alClickFuera);
      window.removeEventListener('scroll', alDesplazar, true);
      window.removeEventListener('resize', posicionar);
    };
  }, [abierto, enfocarPrimero, posicionar]);

  if (!hayAcciones) return null;

  /**
   * El panel sale de la tabla con un portal: al hacer `sticky` una columna, cada celda crea su propio contexto de
   * apilamiento y un menú que viva dentro de ella quedaría tapado por las celdas siguientes. Dentro de un `<dialog>`
   * modal el destino es el propio diálogo: fuera de él sería inerte y quedaría por debajo de su capa.
   */
  function abrirMenu() {
    setDestino(botonRef.current?.closest('dialog') ?? document.body);
    setAbierto(true);
  }

  function alPulsarBoton(evento: KeyboardEvent<HTMLButtonElement>) {
    if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
      evento.preventDefault();
      abrirMenu();
    }
  }

  function copiar(valor: string, aviso?: string) {
    setAbierto(false);
    if (typeof navigator === 'undefined' || !navigator.clipboard) {
      mostrar('error', 'El portapapeles no está disponible.');
      return;
    }
    navigator.clipboard.writeText(valor).then(
      () => mostrar('exito', aviso ?? 'Copiado al portapapeles.'),
      () => mostrar('error', 'No se pudo copiar al portapapeles.'),
    );
  }

  const claseBase =
    'flex min-h-10 w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition focus-visible:-outline-offset-2';
  const claseNormal = `${claseBase} text-panel-text hover:bg-panel-bg focus-visible:bg-academico-50 focus-visible:outline-2 focus-visible:outline-academico-700`;
  const clasePeligro = `${claseBase} text-estado-error hover:bg-red-50 focus-visible:bg-red-50 focus-visible:outline-2 focus-visible:outline-estado-error`;

  function contenido(elemento: ElementoConEtiqueta) {
    const Icono = iconoDe(elemento);
    return (
      <>
        {Icono ? <Icono className="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" /> : hayIconos ? <span className="h-4 w-4 shrink-0" aria-hidden="true" /> : null}
        <span className="min-w-0 flex-1">{elemento.etiqueta}</span>
      </>
    );
  }

  return (
    <div ref={contenedorRef} className="inline-flex justify-end">
      <button
        ref={botonRef}
        type="button"
        aria-haspopup="menu"
        aria-expanded={abierto}
        aria-controls={abierto ? idMenu : undefined}
        aria-label={disparador ? undefined : etiqueta}
        title={disparador ? undefined : etiqueta}
        disabled={cargando}
        aria-busy={cargando || undefined}
        onClick={() => (abierto ? setAbierto(false) : abrirMenu())}
        onKeyDown={alPulsarBoton}
        className={
          disparador
            ? 'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-panel-border bg-panel-surface px-4 py-2.5 text-sm font-semibold text-panel-text outline-offset-2 hover:bg-academico-50 focus-visible:outline-2 focus-visible:outline-academico-700 disabled:cursor-not-allowed disabled:opacity-60'
            : 'inline-flex h-10 w-10 items-center justify-center rounded-lg border border-panel-border bg-panel-surface text-panel-muted hover:bg-panel-bg hover:text-panel-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700 sm:h-9 sm:w-9'
        }
      >
        {disparador ? (
          <>
            {cargando ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : disparador.icono ? <disparador.icono className="h-4 w-4" aria-hidden="true" /> : null}
            {disparador.texto}
            <ChevronDown className="h-4 w-4 opacity-70" aria-hidden="true" />
          </>
        ) : (
          <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
        )}
      </button>

      {abierto && destino ? createPortal(
        <div
          ref={menuRef}
          id={idMenu}
          role="menu"
          aria-orientation="vertical"
          aria-label={etiqueta}
          onKeyDown={alPulsarTecla}
          className="fixed z-50 w-60 overflow-y-auto overscroll-contain text-left rounded-xl border border-panel-border bg-panel-surface py-1.5 shadow-lg"
        >
          {lista.map((item, indice) => {
            if (item.tipo === 'separador') {
              return <div key={`separador-${indice}`} role="separator" className="my-1 h-px bg-panel-border" />;
            }
            if (item.tipo === 'titulo') {
              // Decorativo: cada ítem ya se nombra solo («Copiar teléfono», «WhatsApp»).
              return <p key={`titulo-${indice}`} aria-hidden="true" className="px-3 pb-1 pt-1.5 text-xs font-semibold text-panel-muted">{item.etiqueta}</p>;
            }
            const clave = `${item.etiqueta}-${indice}`;
            if (item.tipo === 'enlace') {
              return item.externo ? (
                <a
                  key={clave}
                  href={item.href}
                  target={item.href.startsWith('mailto:') ? undefined : '_blank'}
                  rel={item.href.startsWith('mailto:') ? undefined : 'noopener noreferrer'}
                  role="menuitem"
                  onClick={() => setAbierto(false)}
                  className={claseNormal}
                >
                  {contenido(item)}
                </a>
              ) : (
                <Link key={clave} href={item.href} role="menuitem" onClick={() => setAbierto(false)} className={claseNormal}>
                  {contenido(item)}
                </Link>
              );
            }
            if (item.tipo === 'copiar') {
              return (
                <button key={clave} type="button" role="menuitem" onClick={() => copiar(item.valor, item.aviso)} className={claseNormal}>
                  {contenido(item)}
                </button>
              );
            }
            return (
              <button
                key={clave}
                type="button"
                role="menuitem"
                onClick={() => {
                  setAbierto(false);
                  item.onClick();
                }}
                className={item.peligro ? clasePeligro : claseNormal}
              >
                {contenido(item)}
              </button>
            );
          })}
        </div>,
        destino,
      ) : null}
    </div>
  );
}
