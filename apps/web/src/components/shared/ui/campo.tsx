'use client';

import { useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import type { TonoUi } from './boton';

const FOCO: Record<TonoUi, string> = {
  academico: 'focus:border-academico-700 focus:ring-academico-700/30',
  servicios: 'focus:border-servicios-700 focus:ring-servicios-700/30',
};

interface CampoProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  etiqueta: string;
  error?: string;
  ayuda?: string;
  tono?: TonoUi;
  /** Contenido a la derecha del campo (p. ej. botón para mostrar la contraseña). */
  accesorio?: ReactNode;
}

/** Campo de formulario accesible: etiqueta asociada, error anunciado y descrito con `aria-describedby`. */
export function Campo({ etiqueta, error, ayuda, tono = 'academico', accesorio, className = '', ...resto }: CampoProps) {
  const id = useId();
  const idAyuda = `${id}-ayuda`;
  const idError = `${id}-error`;
  const descritoPor = [ayuda ? idAyuda : null, error ? idError : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-panel-text">
        {etiqueta}
      </label>
      <div className="relative">
        <input
          id={id}
          aria-invalid={error ? true : undefined}
          aria-describedby={descritoPor}
          className={`block w-full rounded-lg border bg-panel-surface px-3 py-2.5 text-sm text-panel-text shadow-sm outline-none transition placeholder:text-slate-400 focus:ring-4 ${
            error ? 'border-estado-error' : 'border-panel-border'
          } ${FOCO[tono]} ${accesorio ? 'pr-11' : ''}`}
          {...resto}
        />
        {accesorio ? <div className="absolute inset-y-0 right-0 flex items-center pr-1.5">{accesorio}</div> : null}
      </div>
      {ayuda ? (
        <p id={idAyuda} className="mt-1.5 text-xs text-panel-muted">
          {ayuda}
        </p>
      ) : null}
      {error ? (
        <p id={idError} className="mt-1.5 text-sm text-estado-error">
          {error}
        </p>
      ) : null}
    </div>
  );
}

interface CampoSeleccionProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id'> {
  etiqueta: string;
  opciones: Record<string, string>;
  error?: string;
  ayuda?: string;
  tono?: TonoUi;
}

/** Lista desplegable con la misma estructura accesible que `Campo`. */
export function CampoSeleccion({ etiqueta, opciones, error, ayuda, tono = 'academico', className = '', ...resto }: CampoSeleccionProps) {
  const id = useId();
  const descritoPor = [ayuda ? `${id}-ayuda` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-panel-text">{etiqueta}</label>
      <select
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={descritoPor}
        className={`block w-full rounded-lg border bg-panel-surface px-3 py-2.5 text-sm text-panel-text shadow-sm outline-none transition focus:ring-4 ${
          error ? 'border-estado-error' : 'border-panel-border'
        } ${FOCO[tono]}`}
        {...resto}
      >
        {Object.entries(opciones).map(([clave, texto]) => (
          <option key={clave} value={clave}>{texto}</option>
        ))}
      </select>
      {ayuda ? <p id={`${id}-ayuda`} className="mt-1.5 text-xs text-panel-muted">{ayuda}</p> : null}
      {error ? <p id={`${id}-error`} className="mt-1.5 text-sm text-estado-error">{error}</p> : null}
    </div>
  );
}

interface CampoAreaProps extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> {
  etiqueta: string;
  error?: string;
  ayuda?: string;
  tono?: TonoUi;
}

/** Texto largo con la misma estructura accesible que `Campo`. */
export function CampoArea({ etiqueta, error, ayuda, tono = 'academico', className = '', ...resto }: CampoAreaProps) {
  const id = useId();
  const descritoPor = [ayuda ? `${id}-ayuda` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-panel-text">{etiqueta}</label>
      <textarea
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={descritoPor}
        className={`block w-full rounded-lg border bg-panel-surface px-3 py-2.5 text-sm text-panel-text shadow-sm outline-none transition placeholder:text-slate-400 focus:ring-4 ${
          error ? 'border-estado-error' : 'border-panel-border'
        } ${FOCO[tono]}`}
        {...resto}
      />
      {ayuda ? <p id={`${id}-ayuda`} className="mt-1.5 text-xs text-panel-muted">{ayuda}</p> : null}
      {error ? <p id={`${id}-error`} className="mt-1.5 text-sm text-estado-error">{error}</p> : null}
    </div>
  );
}
