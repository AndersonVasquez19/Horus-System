'use client';

import Link from 'next/link';
import { Check, Copy, Mail, MessageCircle } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { enlaceWaMe } from '@/lib/panel/whatsapp-plantillas';

/** Botón mínimo para copiar un dato (teléfono, correo, código) directamente al lado del texto en una tabla. */
export function BotonCopiarDato({ valor, etiqueta }: { valor: string; etiqueta: string }) {
  const [copiado, setCopiado] = useState(false);

  function copiar() {
    void navigator.clipboard?.writeText(valor).then(() => {
      setCopiado(true);
      setTimeout(() => setCopiado(false), 1600);
    });
  }

  return (
    <button
      type="button"
      onClick={copiar}
      title={copiado ? 'Copiado' : etiqueta}
      aria-label={copiado ? 'Copiado' : etiqueta}
      className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-panel-border bg-panel-surface text-panel-muted transition hover:border-academico-300 hover:bg-academico-50 hover:text-academico-700 cursor-pointer"
    >
      {copiado ? <Check className="h-3.5 w-3.5 text-emerald-600" aria-hidden="true" /> : <Copy className="h-3.5 w-3.5" aria-hidden="true" />}
    </button>
  );
}

/** Celda de teléfono con botón inline de copiar y acceso directo a WhatsApp (sin menú de 3 puntos). */
export function CeldaTelefono({
  telefono,
  mensajeWa,
}: {
  telefono: string | null | undefined;
  mensajeWa?: string;
}) {
  if (!telefono) return <span className="text-panel-muted">—</span>;
  const wa = enlaceWaMe(telefono, mensajeWa ?? '');

  return (
    <div className="inline-flex items-center gap-1.5">
      <span className="font-mono text-xs text-panel-text">{telefono}</span>
      <BotonCopiarDato valor={telefono} etiqueta={`Copiar teléfono ${telefono}`} />
      {wa ? (
        <a
          href={wa}
          target="_blank"
          rel="noopener noreferrer"
          title="Contactar por WhatsApp"
          aria-label={`Contactar por WhatsApp al ${telefono}`}
          className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-emerald-600/30 bg-emerald-50 text-emerald-700 transition hover:bg-emerald-100"
        >
          <MessageCircle className="h-3.5 w-3.5" aria-hidden="true" />
        </a>
      ) : null}
    </div>
  );
}

/** Celda de correo con botón inline de copiar y enlace mailto opcional. */
export function CeldaCorreo({
  email,
  conMailto = false,
}: {
  email: string | null | undefined;
  conMailto?: boolean;
}) {
  if (!email) return <span className="text-panel-muted">—</span>;

  return (
    <div className="inline-flex max-w-[230px] items-center gap-1.5">
      <span className="truncate text-xs text-panel-muted" title={email}>
        {email}
      </span>
      <BotonCopiarDato valor={email} etiqueta={`Copiar correo ${email}`} />
      {conMailto ? (
        <a
          href={`mailto:${email}`}
          title={`Enviar correo a ${email}`}
          aria-label={`Enviar correo a ${email}`}
          className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-panel-border bg-panel-surface text-panel-muted transition hover:border-academico-300 hover:bg-academico-50 hover:text-academico-700"
        >
          <Mail className="h-3.5 w-3.5" aria-hidden="true" />
        </a>
      ) : null}
    </div>
  );
}

const TONO_BOTON = {
  neutro: 'border-panel-border bg-panel-surface text-panel-text hover:border-academico-300 hover:bg-academico-50 hover:text-academico-800',
  primario: 'border-academico-600/30 bg-academico-50 text-academico-800 hover:bg-academico-100',
  verde: 'border-emerald-600/30 bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
  ambar: 'border-amber-600/30 bg-amber-50 text-amber-800 hover:bg-amber-100',
  peligro: 'border-red-600/30 bg-red-50 text-red-700 hover:bg-red-100',
} as const;

export type TonoAccionFila = keyof typeof TONO_BOTON;

/** Enlace de acción directa en una fila de tabla (reemplaza opciones ocultas en el menú de 3 puntos). */
export function EnlaceAccionFila({
  href,
  etiqueta,
  icono,
  tono = 'neutro',
  nuevaPestana = false,
}: {
  href: string;
  etiqueta: string;
  icono: ReactNode;
  tono?: TonoAccionFila;
  nuevaPestana?: boolean;
}) {
  const clase = `inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold transition ${TONO_BOTON[tono]}`;
  if (nuevaPestana) {
    return (
      <a href={href} target="_blank" rel="noopener noreferrer" title={etiqueta} aria-label={etiqueta} className={clase}>
        {icono}
        <span className="hidden xl:inline">{etiqueta}</span>
      </a>
    );
  }
  return (
    <Link href={href} title={etiqueta} aria-label={etiqueta} className={clase}>
      {icono}
      <span className="hidden xl:inline">{etiqueta}</span>
    </Link>
  );
}

/** Botón de acción directa en una fila de tabla (reemplaza el menú de 3 puntos). */
export function BotonAccionFila({
  onClick,
  etiqueta,
  icono,
  tono = 'neutro',
  disabled = false,
  soloIcono = false,
}: {
  onClick: () => void;
  etiqueta: string;
  icono: ReactNode;
  tono?: TonoAccionFila;
  disabled?: boolean;
  soloIcono?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={etiqueta}
      aria-label={etiqueta}
      className={`inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border text-xs font-semibold transition cursor-pointer disabled:cursor-not-allowed disabled:opacity-50 ${
        soloIcono ? 'w-8 px-0' : 'px-2.5'
      } ${TONO_BOTON[tono]}`}
    >
      {icono}
      {!soloIcono ? <span className="hidden xl:inline">{etiqueta}</span> : null}
    </button>
  );
}
