'use client';

import { X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Boton, type TonoUi } from './boton';

interface DialogoProps {
  abierto: boolean;
  titulo: string;
  descripcion?: string;
  onCerrar: () => void;
  children: ReactNode;
  /** Ancho máximo del panel centrado. En móvil, `lg` y `xl` ocupan toda la pantalla. */
  ancho?: 'sm' | 'lg' | 'xl';
  /**
   * `centro` (modal) o `derecha` (panel lateral a toda la altura). Los paneles laterales son para crear o editar
   * entidades con varios campos; en móvil ocupan toda la pantalla.
   */
  lado?: 'centro' | 'derecha';
  /** Mientras se guarda, Escape y el botón de cerrar no descartan el diálogo. */
  bloqueado?: boolean;
}

/** Primer campo editable del cuerpo: es donde debe empezar el foco (no en el botón de cerrar). */
const SELECTOR_PRIMER_CAMPO =
  '[data-cuerpo-dialogo] :is(input:not([type="hidden"]):not([type="file"]):not([type="checkbox"]):not([type="radio"]), select, textarea):not([disabled])';

/**
 * Diálogo modal sobre `<dialog>` nativo: `showModal()` aporta el foco atrapado, el fondo inerte y Escape. Al
 * cerrar, el navegador devuelve el foco al elemento que lo abrió.
 *
 * - **Foco inicial:** el elemento con `data-foco-inicial` o, si no hay, el primer campo del cuerpo.
 * - **Cierre por el fondo:** un clic (pulsado y soltado) sobre el fondo cierra, salvo `bloqueado`.
 * - **Cambios sin guardar:** si la persona escribió algo, cerrar con la X, Escape o el fondo pide confirmación antes
 *   de descartar. Los botones «Cancelar» de cada formulario llaman a `onCerrar` directamente y descartan sin
 *   preguntar, porque son una decisión explícita.
 * - **Pie de acciones:** una barra con la clase `pie-acciones` queda fija al borde inferior del área con scroll.
 */
export function Dialogo({ abierto, titulo, descripcion, onCerrar, children, ancho = 'lg', lado = 'centro', bloqueado = false }: DialogoProps) {
  const referencia = useRef<HTMLDialogElement>(null);
  const botonSeguirRef = useRef<HTMLButtonElement>(null);
  const fondoPulsado = useRef(false);
  const idTitulo = useId();
  const idDescripcion = useId();
  const [sucio, setSucio] = useState(false);
  const [confirmandoDescarte, setConfirmandoDescarte] = useState(false);
  const [abiertoPrevio, setAbiertoPrevio] = useState(abierto);

  // Cada apertura empieza limpia. Se ajusta durante el render (patrón de React), no en un efecto.
  if (abierto !== abiertoPrevio) {
    setAbiertoPrevio(abierto);
    setSucio(false);
    setConfirmandoDescarte(false);
  }

  useEffect(() => {
    const dialogo = referencia.current;
    if (!dialogo) return;
    if (abierto && !dialogo.open) {
      dialogo.showModal();
      const destino = dialogo.querySelector<HTMLElement>('[data-foco-inicial]') ?? dialogo.querySelector<HTMLElement>(SELECTOR_PRIMER_CAMPO);
      destino?.focus();
    }
    if (!abierto && dialogo.open) dialogo.close();
  }, [abierto]);

  useEffect(() => {
    if (confirmandoDescarte) botonSeguirRef.current?.focus();
  }, [confirmandoDescarte]);

  function intentarCerrar() {
    if (bloqueado) return;
    if (sucio) setConfirmandoDescarte(true);
    else onCerrar();
  }

  const esPanel = lado === 'derecha';
  const esGrande = ancho === 'lg' || ancho === 'xl';
  const claseAnchoCentro = ancho === 'sm' ? 'max-w-md' : ancho === 'xl' ? 'max-w-3xl' : 'max-w-2xl';
  const claseDialogo = esPanel
    ? 'm-0 ml-auto h-dvh max-h-none w-full max-w-xl rounded-none border-y-0 border-r-0 sm:rounded-l-xl'
    : `m-auto w-[calc(100%-2rem)] rounded-xl border ${claseAnchoCentro} ${
        esGrande
          ? 'max-sm:m-0 max-sm:h-dvh max-sm:max-h-none max-sm:w-full max-sm:max-w-none max-sm:rounded-none max-sm:border-0'
          : ''
      }`;
  const clasePanel = esPanel
    ? 'flex h-dvh max-h-dvh flex-col'
    : `flex max-h-[88dvh] flex-col ${esGrande ? 'max-sm:h-dvh max-sm:max-h-dvh' : ''}`;

  return (
    <dialog
      ref={referencia}
      data-lado={lado}
      aria-labelledby={idTitulo}
      aria-describedby={descripcion ? idDescripcion : undefined}
      onCancel={(evento) => {
        evento.preventDefault();
        if (referencia.current?.querySelector('[role="combobox"][aria-expanded="true"]')) return;
        if (confirmandoDescarte) setConfirmandoDescarte(false);
        else intentarCerrar();
      }}
      onMouseDown={(evento) => {
        fondoPulsado.current = evento.target === evento.currentTarget;
      }}
      onClick={(evento) => {
        // El fondo del <dialog> modal es el propio elemento: el contenido lo cubre por completo (padding 0).
        const sobreElFondo = evento.target === evento.currentTarget && fondoPulsado.current;
        fondoPulsado.current = false;
        if (sobreElFondo && !confirmandoDescarte) intentarCerrar();
      }}
      className={`border-panel-border bg-panel-surface p-0 text-panel-text shadow-2xl backdrop:bg-slate-900/50 ${claseDialogo}`}
    >
      {abierto ? (
        <div className={clasePanel}>
          <div className="flex items-start justify-between gap-4 border-b border-panel-border px-5 py-4">
            <div>
              <h2 id={idTitulo} className="font-heading text-lg font-semibold">{titulo}</h2>
              {descripcion ? <p id={idDescripcion} className="mt-1 text-sm text-panel-muted">{descripcion}</p> : null}
            </div>
            <button
              type="button"
              onClick={intentarCerrar}
              disabled={bloqueado}
              className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-panel-muted hover:bg-panel-bg hover:text-panel-text focus-visible:outline-2 focus-visible:outline-panel-text disabled:opacity-50 sm:-mr-2 sm:-mt-2"
              aria-label="Cerrar"
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>
          </div>

          {confirmandoDescarte ? (
            <div role="alert" className="flex flex-col gap-3 border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
              <p className="font-medium">Tienes cambios sin guardar. Si cierras, se perderán.</p>
              <div className="flex shrink-0 gap-2">
                <Boton ref={botonSeguirRef} variante="secundario" onClick={() => setConfirmandoDescarte(false)}>Seguir editando</Boton>
                <Boton variante="peligro" onClick={onCerrar}>Descartar</Boton>
              </div>
            </div>
          ) : null}

          <div
            data-cuerpo-dialogo
            onInput={() => setSucio(true)}
            onChange={() => setSucio(true)}
            className="min-h-0 flex-1 overflow-y-auto px-5 py-4"
          >
            {children}
          </div>
        </div>
      ) : null}
    </dialog>
  );
}

interface ConfirmacionProps {
  abierto: boolean;
  titulo: string;
  texto: ReactNode;
  confirmar: string;
  peligro?: boolean;
  tono?: TonoUi;
  cargando: boolean;
  error?: string | null;
  onConfirmar: () => void;
  onCerrar: () => void;
}

/** Confirmación de una operación con efecto (anular, desactivar, eliminar, restaurar). El foco empieza en «Cancelar». */
export function DialogoConfirmacion({ abierto, titulo, texto, confirmar, peligro = false, tono = 'academico', cargando, error, onConfirmar, onCerrar }: ConfirmacionProps) {
  return (
    <Dialogo abierto={abierto} titulo={titulo} onCerrar={onCerrar} ancho="sm" bloqueado={cargando}>
      <div className="space-y-4 text-sm">
        <div className="text-panel-muted">{texto}</div>
        {error ? <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-estado-error">{error}</p> : null}
        <div className="pie-acciones">
          <Boton tono={tono} variante="secundario" onClick={onCerrar} disabled={cargando} data-foco-inicial>Cancelar</Boton>
          <Boton tono={tono} variante={peligro ? 'peligro' : 'primario'} onClick={onConfirmar} cargando={cargando}>
            {confirmar}
          </Boton>
        </div>
      </div>
    </Dialogo>
  );
}
