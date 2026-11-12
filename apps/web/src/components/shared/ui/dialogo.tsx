'use client';

import { X } from 'lucide-react';
import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Boton, type TonoUi } from './boton';

interface DialogoProps {
  abierto: boolean;
  titulo: string;
  descripcion?: string;
  onCerrar: () => void;
  children: ReactNode;
  /** Ancho máximo del panel. */
  ancho?: 'sm' | 'lg';
  /** Mientras se guarda, Escape y el botón de cerrar no descartan el diálogo. */
  bloqueado?: boolean;
}

/**
 * Diálogo modal sobre `<dialog>` nativo: `showModal()` aporta el foco atrapado, el fondo inerte y Escape. Al
 * cerrar, el navegador devuelve el foco al elemento que lo abrió.
 */
export function Dialogo({ abierto, titulo, descripcion, onCerrar, children, ancho = 'lg', bloqueado = false }: DialogoProps) {
  const referencia = useRef<HTMLDialogElement>(null);
  const idTitulo = useId();
  const idDescripcion = useId();

  useEffect(() => {
    const dialogo = referencia.current;
    if (!dialogo) return;
    if (abierto && !dialogo.open) dialogo.showModal();
    if (!abierto && dialogo.open) dialogo.close();
  }, [abierto]);

  return (
    <dialog
      ref={referencia}
      aria-labelledby={idTitulo}
      aria-describedby={descripcion ? idDescripcion : undefined}
      onCancel={(evento) => {
        evento.preventDefault();
        if (!bloqueado) onCerrar();
      }}
      className={`m-auto w-[calc(100%-2rem)] rounded-xl border border-panel-border bg-panel-surface p-0 text-panel-text shadow-2xl backdrop:bg-slate-900/50 ${ancho === 'sm' ? 'max-w-md' : 'max-w-2xl'}`}
    >
      {abierto ? (
        <div className="flex max-h-[85vh] flex-col">
          <div className="flex items-start justify-between gap-4 border-b border-panel-border px-5 py-4">
            <div>
              <h2 id={idTitulo} className="font-heading text-lg font-semibold">{titulo}</h2>
              {descripcion ? <p id={idDescripcion} className="mt-1 text-sm text-panel-muted">{descripcion}</p> : null}
            </div>
            <button
              type="button"
              onClick={onCerrar}
              disabled={bloqueado}
              className="rounded-md p-1 text-panel-muted hover:bg-panel-bg hover:text-panel-text focus-visible:outline-2 focus-visible:outline-panel-text disabled:opacity-50"
              aria-label="Cerrar"
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>
          </div>
          <div className="overflow-y-auto px-5 py-4">{children}</div>
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

/** Confirmación de una operación con efecto (anular, desactivar, eliminar, restaurar). */
export function DialogoConfirmacion({ abierto, titulo, texto, confirmar, peligro = false, tono = 'academico', cargando, error, onConfirmar, onCerrar }: ConfirmacionProps) {
  return (
    <Dialogo abierto={abierto} titulo={titulo} onCerrar={onCerrar} ancho="sm" bloqueado={cargando}>
      <div className="space-y-4 text-sm">
        <div className="text-panel-muted">{texto}</div>
        {error ? <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-estado-error">{error}</p> : null}
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Boton tono={tono} variante="secundario" onClick={onCerrar} disabled={cargando}>Cancelar</Boton>
          <Boton tono={tono} variante={peligro ? 'peligro' : 'primario'} onClick={onConfirmar} cargando={cargando}>
            {confirmar}
          </Boton>
        </div>
      </div>
    </Dialogo>
  );
}
