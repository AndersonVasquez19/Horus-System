'use client';

import Link from 'next/link';
import { ArrowRight, Eye, EyeOff, Loader2 } from 'lucide-react';
import {
  useEffect,
  useId,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type ChangeEvent,
  type InputHTMLAttributes,
  type ReactNode,
} from 'react';
import { AYUDA_CLAVE } from '@/components/shared/ui/campo';

/**
 * Mantiene el mismo comportamiento accesible que `Campo` en el panel:
 * el error desaparece al editar el campo y reaparece tras un nuevo envío fallido,
 * llevando el foco automáticamente al primer campo inválido del formulario.
 */
function useErrorAcceso<T extends HTMLElement>(error: string | undefined) {
  const ref = useRef<T>(null);
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

  useEffect(() => {
    if (!visible || !enviado.current) return;
    enviado.current = false;
    const formulario = ref.current?.closest('form');
    const control = ref.current?.querySelector<HTMLElement>('input, select, textarea');
    if (formulario && control && formulario.querySelector('[aria-invalid="true"]') === control) {
      control.focus();
    }
  }, [visible]);

  return { ref, error: visible, descartar: () => setDescartado(error) };
}

interface EncabezadoAccesoProps {
  titulo: string;
  descripcion: string;
}

/** Cabecera homogénea para todas las páginas de `(acceso)` (el H1 lleva su propio peso, sin eyebrow). */
export function EncabezadoAcceso({ titulo, descripcion }: EncabezadoAccesoProps) {
  return (
    <div className="mb-7">
      <h1 className="font-heading text-3xl font-bold tracking-tight text-tinta text-balance">{titulo}</h1>
      <p className="mt-2 text-sm leading-6 text-panel-muted">{descripcion}</p>
    </div>
  );
}

interface CampoAccesoProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  etiqueta: string;
  /** Acción o enlace alineado a la derecha de la etiqueta (p. ej. «¿Olvidaste tu contraseña?»). */
  accionEtiqueta?: ReactNode;
  icono?: ReactNode;
  accesorio?: ReactNode;
  ayuda?: string;
  error?: string;
}

/** Campo unificado de `(acceso)` con paleta Azul + Ámbar, `role="alert"` y auto-foco en el primer error. */
export function CampoAcceso({
  etiqueta,
  accionEtiqueta,
  icono,
  accesorio,
  ayuda,
  error: errorServidor,
  className = '',
  onChange,
  ...resto
}: CampoAccesoProps) {
  const id = useId();
  const { ref, error, descartar } = useErrorAcceso<HTMLDivElement>(errorServidor);
  const idAyuda = `${id}-ayuda`;
  const idError = `${id}-error`;
  const descritoPor = [ayuda ? idAyuda : null, error ? idError : null].filter(Boolean).join(' ') || undefined;

  return (
    <div ref={ref} className={className}>
      <div className="mb-1.5 flex items-center justify-between gap-3">
        <label htmlFor={id} className="block text-sm font-medium text-tinta">
          {etiqueta}
          {resto.required ? (
            <span aria-hidden="true" className="ml-0.5 text-estado-error">
              *
            </span>
          ) : null}
        </label>
        {accionEtiqueta}
      </div>

      <div className="group relative">
        {icono ? (
          <span
            aria-hidden="true"
            className={`pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 transition-colors ${
              error ? 'text-estado-error' : 'text-panel-muted group-focus-within:text-amber-700'
            }`}
          >
            {icono}
          </span>
        ) : null}
        <input
          id={id}
          aria-invalid={error ? true : undefined}
          aria-describedby={descritoPor}
          className={`block w-full rounded-xl border bg-panel-surface py-2.5 text-sm text-panel-text caret-amber-700 shadow-xs outline-none transition placeholder:text-slate-500 focus:ring-4 ${
            icono ? 'pl-10' : 'pl-3.5'
          } ${accesorio ? 'pr-11' : 'pr-3.5'} ${
            error
              ? 'border-estado-error focus:border-estado-error focus:ring-estado-error/15'
              : 'border-panel-border hover:border-academico-300 focus:border-academico-700 focus:ring-amber-400/25'
          }`}
          {...resto}
          onChange={(e: ChangeEvent<HTMLInputElement>) => {
            descartar();
            onChange?.(e);
          }}
        />
        {accesorio ? <div className="absolute inset-y-0 right-0 flex items-center pr-1.5">{accesorio}</div> : null}
      </div>

      {ayuda ? (
        <p id={idAyuda} className="mt-1.5 text-xs leading-5 text-panel-muted">
          {ayuda}
        </p>
      ) : null}
      {error ? (
        <p id={idError} role="alert" className="mt-1.5 text-sm text-estado-error">
          {error}
        </p>
      ) : null}
    </div>
  );
}

interface CampoClaveAccesoProps extends Omit<CampoAccesoProps, 'type' | 'accesorio'> {
  /** Muestra la ayuda estándar de la política de contraseñas (`AYUDA_CLAVE`). */
  politica?: boolean;
}

/** Campo de contraseña unificado con botón mostrar/ocultar accesible (`aria-pressed`). */
export function CampoClaveAcceso({ politica = false, ayuda, ...resto }: CampoClaveAccesoProps) {
  const [visible, setVisible] = useState(false);

  return (
    <CampoAcceso
      {...resto}
      type={visible ? 'text' : 'password'}
      ayuda={ayuda ?? (politica ? AYUDA_CLAVE : undefined)}
      accesorio={
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-pressed={visible}
          aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}
          className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-panel-muted outline-offset-2 transition hover:bg-academico-50 hover:text-tinta focus-visible:outline-2 focus-visible:outline-academico-700"
        >
          {visible ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
        </button>
      }
    />
  );
}

interface BotonAccesoProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  cargando?: boolean;
  icono?: ReactNode;
}

/**
 * Botón primario unificado de `(acceso)`: azul institucional profundo (`academico-900`)
 * con distintivo de acción en ámbar (`amber-400` + icono en `academico-950`, contraste > 10:1).
 */
export function BotonAcceso({
  cargando = false,
  icono,
  className = '',
  disabled,
  children,
  type = 'submit',
  ...resto
}: BotonAccesoProps) {
  return (
    <button
      type={type}
      disabled={disabled || cargando}
      aria-busy={cargando || undefined}
      className={`group flex w-full items-center justify-between rounded-xl bg-academico-900 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-academico-950/15 outline-offset-2 transition hover:bg-academico-800 focus-visible:outline-2 focus-visible:outline-amber-500 disabled:cursor-not-allowed disabled:opacity-70 ${className}`}
      {...resto}
    >
      <span>{children}</span>
      <span
        aria-hidden="true"
        className="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-400 text-academico-950 transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none motion-reduce:group-hover:translate-x-0"
      >
        {cargando ? <Loader2 className="h-4 w-4 animate-spin" /> : (icono ?? <ArrowRight className="h-4 w-4" />)}
      </span>
    </button>
  );
}

/** Enlace de navegación consistente en las cuatro rutas de `(acceso)`. */
export function EnlaceAcceso({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link
      href={href}
      className="rounded font-medium text-academico-700 underline-offset-4 outline-offset-2 transition-colors hover:text-amber-800 hover:underline focus-visible:outline-2 focus-visible:outline-academico-700"
    >
      {children}
    </Link>
  );
}
