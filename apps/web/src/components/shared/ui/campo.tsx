'use client';

import { Eye, EyeOff } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState, type ChangeEvent, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { tamanoLegible } from '@/lib/panel/captura';
import type { TonoUi } from './boton';

const FOCO: Record<TonoUi, string> = {
  academico: 'focus:border-academico-700 focus:ring-academico-700/30',
};

/**
 * El error de un campo deja de mostrarse en cuanto la persona lo edita y reaparece si el servidor lo
 * vuelve a devolver tras un nuevo envío del formulario.
 */
function useErrorVigente<T extends HTMLElement>(error: string | undefined) {
  const ref = useRef<T>(null);
  /** Hubo un envío del formulario y aún no se enfocó el primer campo con error. */
  const enviado = useRef(false);
  const [descartado, setDescartado] = useState<string | undefined>(undefined);
  useEffect(() => {
    const formulario = ref.current?.closest('form');
    if (!formulario) return undefined;
    const reiniciar = () => {
      enviado.current = true;
      setDescartado(undefined);
    };
    formulario.addEventListener('submit', reiniciar);
    return () => formulario.removeEventListener('submit', reiniciar);
  }, []);

  const visible = error && error !== descartado ? error : undefined;

  // Tras un envío fallido (validación local o 422), el foco va al primer campo inválido del formulario.
  useEffect(() => {
    if (!visible || !enviado.current) return;
    enviado.current = false;
    const formulario = ref.current?.closest('form');
    const control = ref.current?.querySelector<HTMLElement>('input, select, textarea');
    if (formulario && control && formulario.querySelector('[aria-invalid="true"]') === control) control.focus();
  }, [visible]);

  return { ref, error: visible, descartar: () => setDescartado(error) };
}

/** Etiqueta con marca visual de obligatorio (el control ya expone `required` a los lectores de pantalla). */
function EtiquetaCampo({ id, texto, requerido }: { id: string; texto: string; requerido?: boolean }) {
  return (
    <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-panel-text">
      {texto}
      {requerido ? <span aria-hidden="true" className="ml-0.5 text-estado-error">*</span> : null}
    </label>
  );
}

/** Error de campo anunciado al aparecer (`role="alert"`) y enlazado al control con `aria-describedby`. */
function ErrorCampo({ id, texto }: { id: string; texto: string }) {
  return (
    <p id={id} role="alert" className="mt-1.5 text-sm text-estado-error">
      {texto}
    </p>
  );
}

interface CampoProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  etiqueta: string;
  error?: string;
  ayuda?: string;
  tono?: TonoUi;
  /** Contenido a la derecha del campo (p. ej. botón para mostrar la contraseña). */
  accesorio?: ReactNode;
}

/** Campo de formulario accesible: etiqueta asociada, error anunciado y descrito con `aria-describedby`. */
export function Campo({ etiqueta, error: errorServidor, ayuda, tono = 'academico', accesorio, className = '', onChange, ...resto }: CampoProps) {
  const id = useId();
  const { ref, error, descartar } = useErrorVigente<HTMLDivElement>(errorServidor);
  const idAyuda = `${id}-ayuda`;
  const idError = `${id}-error`;
  const descritoPor = [ayuda ? idAyuda : null, error ? idError : null].filter(Boolean).join(' ') || undefined;

  return (
    <div ref={ref} className={className}>
      <EtiquetaCampo id={id} texto={etiqueta} requerido={resto.required} />
      <div className="relative">
        <input
          id={id}
          aria-invalid={error ? true : undefined}
          aria-describedby={descritoPor}
          className={`block w-full rounded-lg border bg-panel-surface px-3 py-2.5 text-sm text-panel-text shadow-sm outline-none transition placeholder:text-slate-500 focus:ring-4 ${
            error ? 'border-estado-error' : 'border-panel-border'
          } ${FOCO[tono]} ${accesorio ? 'pr-11' : ''}`}
          {...resto}
          onChange={(e: ChangeEvent<HTMLInputElement>) => {
            descartar();
            onChange?.(e);
          }}
        />
        {accesorio ? <div className="absolute inset-y-0 right-0 flex items-center pr-1.5">{accesorio}</div> : null}
      </div>
      {ayuda ? (
        <p id={idAyuda} className="mt-1.5 text-xs text-panel-muted">
          {ayuda}
        </p>
      ) : null}
      {error ? <ErrorCampo id={idError} texto={error} /> : null}
    </div>
  );
}

/** Política de contraseñas (la aplica Laravel con `PasswordPolicy`; aquí solo se explica). */
export const AYUDA_CLAVE = 'Al menos 10 caracteres, con mayúsculas, minúsculas y un número.';

interface CampoClaveProps extends Omit<CampoProps, 'type' | 'accesorio'> {
  /** Muestra la ayuda de la política de contraseñas si no se da una `ayuda` propia. */
  politica?: boolean;
}

/** Campo de contraseña con botón para mostrar u ocultar lo escrito. */
export function CampoClave({ politica = false, ayuda, ...resto }: CampoClaveProps) {
  const [visible, setVisible] = useState(false);
  return (
    <Campo
      {...resto}
      type={visible ? 'text' : 'password'}
      ayuda={ayuda ?? (politica ? AYUDA_CLAVE : undefined)}
      accesorio={
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-pressed={visible}
          aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}
          className="inline-flex h-9 w-9 items-center justify-center rounded-md text-panel-muted hover:bg-panel-bg hover:text-panel-text"
        >
          {visible ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
        </button>
      }
    />
  );
}

interface CampoArchivoProps {
  etiqueta: string;
  /** Valor del atributo `accept`. */
  accept: string;
  /** Avisa con el archivo elegido o `null` si se quitó. La validación la hace quien lo usa. */
  onArchivo: (archivo: File | null) => void;
  error?: string;
  ayuda?: string;
  requerido?: boolean;
  className?: string;
}

/**
 * Campo de archivo con el botón «Elegir» estilizado (sigue siendo el `<input type="file">` nativo, con su teclado),
 * nombre y tamaño del archivo elegido y vista previa si es una imagen.
 */
export function CampoArchivo({ etiqueta, accept, onArchivo, error, ayuda, requerido = false, className = '' }: CampoArchivoProps) {
  const id = useId();
  const [archivo, setArchivo] = useState<File | null>(null);
  const idAyuda = `${id}-ayuda`;
  const idError = `${id}-error`;
  const descritoPor = [ayuda ? idAyuda : null, error ? idError : null].filter(Boolean).join(' ') || undefined;

  // La URL de la vista previa se crea al elegir la imagen y se libera al cambiarla o al cerrar.
  const vista = useMemo(() => (archivo !== null && archivo.type.startsWith('image/') ? URL.createObjectURL(archivo) : null), [archivo]);
  useEffect(() => {
    return () => {
      if (vista !== null) URL.revokeObjectURL(vista);
    };
  }, [vista]);

  return (
    <div className={className}>
      <EtiquetaCampo id={id} texto={etiqueta} requerido={requerido} />
      <input
        id={id}
        type="file"
        accept={accept}
        aria-invalid={error ? true : undefined}
        aria-describedby={descritoPor}
        onChange={(e) => {
          const elegido = e.target.files?.[0] ?? null;
          setArchivo(elegido);
          onArchivo(elegido);
        }}
        className={`block w-full rounded-lg border bg-panel-surface p-1.5 text-sm text-panel-text shadow-sm transition file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-academico-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-academico-800 hover:file:bg-academico-100 focus-visible:outline-2 focus-visible:outline-academico-700 ${
          error ? 'border-estado-error' : 'border-panel-border'
        }`}
      />
      {archivo !== null ? (
        <div className="mt-2 flex items-center gap-3 rounded-lg border border-panel-border bg-panel-bg p-2">
          {vista !== null ? (
            // eslint-disable-next-line @next/next/no-img-element -- vista previa local (blob:), no admite optimización de next/image
            <img src={vista} alt="Vista previa del archivo elegido" className="h-14 w-14 shrink-0 rounded-md object-cover" />
          ) : null}
          <p className="min-w-0 text-sm text-panel-text">
            <span className="block truncate font-medium">{archivo.name}</span>
            <span className="text-xs text-panel-muted">{tamanoLegible(archivo.size)}</span>
          </p>
        </div>
      ) : null}
      {ayuda ? <p id={idAyuda} className="mt-1.5 text-xs text-panel-muted">{ayuda}</p> : null}
      {error ? <ErrorCampo id={idError} texto={error} /> : null}
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
export function CampoSeleccion({ etiqueta, opciones, error: errorServidor, ayuda, tono = 'academico', className = '', onChange, ...resto }: CampoSeleccionProps) {
  const id = useId();
  const { ref, error, descartar } = useErrorVigente<HTMLDivElement>(errorServidor);
  const descritoPor = [ayuda ? `${id}-ayuda` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  return (
    <div ref={ref} className={className}>
      <EtiquetaCampo id={id} texto={etiqueta} requerido={resto.required} />
      <select
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={descritoPor}
        className={`block w-full rounded-lg border bg-panel-surface px-3 py-2.5 text-sm text-panel-text shadow-sm outline-none transition focus:ring-4 ${
          error ? 'border-estado-error' : 'border-panel-border'
        } ${FOCO[tono]}`}
        {...resto}
        onChange={(e: ChangeEvent<HTMLSelectElement>) => {
          descartar();
          onChange?.(e);
        }}
      >
        {Object.entries(opciones).map(([clave, texto]) => (
          <option key={clave} value={clave}>{texto}</option>
        ))}
      </select>
      {ayuda ? <p id={`${id}-ayuda`} className="mt-1.5 text-xs text-panel-muted">{ayuda}</p> : null}
      {error ? <ErrorCampo id={`${id}-error`} texto={error} /> : null}
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
export function CampoArea({ etiqueta, error: errorServidor, ayuda, tono = 'academico', className = '', onChange, ...resto }: CampoAreaProps) {
  const id = useId();
  const { ref, error, descartar } = useErrorVigente<HTMLDivElement>(errorServidor);
  const descritoPor = [ayuda ? `${id}-ayuda` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  return (
    <div ref={ref} className={className}>
      <EtiquetaCampo id={id} texto={etiqueta} requerido={resto.required} />
      <textarea
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={descritoPor}
        className={`block w-full rounded-lg border bg-panel-surface px-3 py-2.5 text-sm text-panel-text shadow-sm outline-none transition placeholder:text-slate-500 focus:ring-4 ${
          error ? 'border-estado-error' : 'border-panel-border'
        } ${FOCO[tono]}`}
        {...resto}
        onChange={(e: ChangeEvent<HTMLTextAreaElement>) => {
          descartar();
          onChange?.(e);
        }}
      />
      {ayuda ? <p id={`${id}-ayuda`} className="mt-1.5 text-xs text-panel-muted">{ayuda}</p> : null}
      {error ? <ErrorCampo id={`${id}-error`} texto={error} /> : null}
    </div>
  );
}
