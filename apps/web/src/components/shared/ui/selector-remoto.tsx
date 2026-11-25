'use client';

import { Check, ChevronsUpDown, Loader2, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { TonoUi } from './boton';

export interface OpcionRemota {
  id: number;
  texto: string;
  eliminado?: boolean;
}

interface SelectorRemotoProps {
  etiqueta: string;
  /** Opción elegida (o la inicial de un registro existente). */
  valor: OpcionRemota | null;
  onCambiar: (opcion: OpcionRemota | null) => void;
  /** Busca en el servidor; se llama con una pausa tras teclear. */
  buscar: (texto: string) => Promise<OpcionRemota[] | null>;
  marcador?: string;
  ayuda?: string;
  error?: string;
  tono?: TonoUi;
  deshabilitado?: boolean;
  requerido?: boolean;
}

const PAUSA_MS = 300;

const FOCO: Record<TonoUi, string> = {
  academico: 'focus:border-academico-700 focus:ring-academico-700/30',
};

/**
 * Selector con búsqueda en el servidor (patrón combobox de WAI-ARIA con lista emergente). Las opciones solo
 * proponen: Laravel vuelve a validar la elección al guardar. Flechas para moverse, Enter para elegir, Escape para
 * cerrar.
 */
export function SelectorRemoto({ etiqueta, valor, onCambiar, buscar, marcador, ayuda, error, tono = 'academico', deshabilitado = false, requerido = false }: SelectorRemotoProps) {
  const id = useId();
  const idLista = `${id}-lista`;
  const idAyuda = `${id}-ayuda`;
  const idError = `${id}-error`;
  const [texto, setTexto] = useState(valor?.texto ?? '');
  const [abierto, setAbierto] = useState(false);
  const [opciones, setOpciones] = useState<OpcionRemota[]>([]);
  const [activa, setActiva] = useState(-1);
  const [cargando, setCargando] = useState(false);
  const [fallo, setFallo] = useState(false);
  const consulta = useRef(0);
  const buscarRef = useRef(buscar);

  useEffect(() => {
    buscarRef.current = buscar;
  }, [buscar]);

  // Solo cuando llega una opción (elegida o inicial): al teclear, la elección pasa a null sin borrar lo escrito.
  const textoElegido = valor?.texto;
  const [elegidoPrevio, setElegidoPrevio] = useState(textoElegido);
  if (textoElegido !== elegidoPrevio) {
    setElegidoPrevio(textoElegido);
    if (textoElegido !== undefined) setTexto(textoElegido);
  }

  useEffect(() => {
    if (!abierto) return;
    const numero = ++consulta.current;
    const temporizador = window.setTimeout(async () => {
      setCargando(true);
      const resultado = await buscarRef.current(texto === valor?.texto ? '' : texto.trim());
      if (numero !== consulta.current) return;
      setCargando(false);
      setFallo(resultado === null);
      setOpciones(resultado ?? []);
      setActiva(-1);
    }, PAUSA_MS);
    return () => window.clearTimeout(temporizador);
  }, [abierto, texto, valor]);

  const elegir = (opcion: OpcionRemota) => {
    onCambiar(opcion);
    setTexto(opcion.texto);
    setAbierto(false);
  };

  const descritoPor = [ayuda ? idAyuda : null, error ? idError : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className="relative">
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-panel-text">
        {etiqueta}
        {requerido ? <span aria-hidden="true" className="ml-0.5 text-estado-error">*</span> : null}
      </label>
      <div className="relative">
        <input
          id={id}
          type="text"
          role="combobox"
          autoComplete="off"
          aria-autocomplete="list"
          aria-expanded={abierto}
          aria-controls={idLista}
          aria-activedescendant={abierto && activa >= 0 ? `${idLista}-${activa}` : undefined}
          aria-required={requerido || undefined}
          aria-invalid={error ? true : undefined}
          aria-describedby={descritoPor}
          disabled={deshabilitado}
          value={texto}
          placeholder={marcador}
          onChange={(evento) => {
            setTexto(evento.target.value);
            setAbierto(true);
            if (valor !== null) onCambiar(null);
          }}
          onFocus={() => setAbierto(true)}
          onBlur={() => window.setTimeout(() => setAbierto(false), 150)}
          onKeyDown={(evento) => {
            if (evento.key === 'ArrowDown') {
              evento.preventDefault();
              setAbierto(true);
              setActiva((actual) => Math.min(actual + 1, opciones.length - 1));
            } else if (evento.key === 'ArrowUp') {
              evento.preventDefault();
              setActiva((actual) => Math.max(actual - 1, 0));
            } else if (evento.key === 'Enter' && abierto && activa >= 0 && opciones[activa]) {
              evento.preventDefault();
              elegir(opciones[activa]);
            } else if (evento.key === 'Escape' && abierto) {
              evento.preventDefault();
              evento.stopPropagation();
              setAbierto(false);
            }
          }}
          className={`block w-full rounded-lg border bg-panel-surface py-2.5 pl-3 text-sm text-panel-text shadow-sm outline-none transition placeholder:text-panel-muted focus:ring-4 disabled:opacity-60 ${
            valor !== null && !deshabilitado ? 'pr-16' : 'pr-10'
          } ${
            error ? 'border-estado-error' : 'border-panel-border'
          } ${FOCO[tono]}`}
        />
        <div className="absolute inset-y-0 right-0 flex items-center gap-1 pr-2">
          {valor !== null && !deshabilitado ? (
            <button
              type="button"
              onMouseDown={(evento) => evento.preventDefault()}
              onClick={() => {
                onCambiar(null);
                setTexto('');
                setAbierto(true);
              }}
              aria-label={`Quitar ${etiqueta.toLowerCase()}`}
              className="inline-flex h-7 w-7 items-center justify-center rounded-md text-panel-muted transition hover:bg-panel-bg hover:text-panel-text"
            >
              <X className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          ) : null}
          <span className="pointer-events-none flex items-center pr-1 text-panel-muted" aria-hidden="true">
            {cargando ? <Loader2 className="h-4 w-4 animate-spin" /> : <ChevronsUpDown className="h-4 w-4" />}
          </span>
        </div>
      </div>

      {abierto ? (
        <ul id={idLista} role="listbox" aria-label={etiqueta} className="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-panel-border bg-panel-surface py-1 text-sm shadow-lg">
          {opciones.length === 0 ? (
            <li className="px-3 py-2 text-panel-muted" role="presentation">
              {cargando ? 'Buscando…' : fallo ? 'No se pudieron cargar las opciones.' : 'Sin resultados.'}
            </li>
          ) : (
            opciones.map((opcion, indice) => (
              <li
                key={opcion.id}
                id={`${idLista}-${indice}`}
                role="option"
                aria-selected={valor?.id === opcion.id}
                onMouseDown={(evento) => evento.preventDefault()}
                onClick={() => elegir(opcion)}
                className={`flex cursor-pointer items-center justify-between gap-2 px-3 py-2 transition-colors hover:bg-academico-50 ${indice === activa ? 'bg-academico-50' : ''} ${opcion.eliminado ? 'text-panel-muted' : 'text-panel-text'}`}
              >
                <span className="truncate">{opcion.texto}</span>
                {valor?.id === opcion.id ? <Check className="h-4 w-4 shrink-0 text-academico-700" aria-hidden="true" /> : null}
              </li>
            ))
          )}
        </ul>
      ) : null}

      {ayuda ? <p id={idAyuda} className="mt-1.5 text-xs text-panel-muted">{ayuda}</p> : null}
      {error ? <p id={idError} role="alert" className="mt-1.5 text-sm text-estado-error">{error}</p> : null}
    </div>
  );
}
