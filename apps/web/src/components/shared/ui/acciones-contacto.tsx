'use client';

import { Copy, ExternalLink, Mail, MessageCircle } from 'lucide-react';
import { useAvisos } from './avisos';
import { esEnlaceGrupoWhatsApp, urlMailto, urlWhatsApp } from '@/lib/panel/contacto';

interface BotonesContactoProps {
  telefono?: string | null;
  email?: string | null;
  mensajeWa?: string;
  asuntoCorreo?: string;
  cuerpoCorreo?: string;
  compacto?: boolean;
}

const CLASE_BASE =
  'inline-flex items-center gap-1.5 rounded-lg border border-panel-border bg-panel-surface text-xs font-medium text-panel-text transition hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700';

/**
 * Acciones rápidas de contacto (WhatsApp con mensaje prellenado, cliente de correo y copiado al portapapeles).
 */
export function BotonesContacto({
  telefono,
  email,
  mensajeWa,
  asuntoCorreo,
  cuerpoCorreo,
  compacto = false,
}: BotonesContactoProps) {
  const { mostrar } = useAvisos();
  const hrefWa = urlWhatsApp(telefono, mensajeWa);
  const hrefMail = urlMailto(email, asuntoCorreo, cuerpoCorreo);
  const telLimpio = (telefono ?? '').trim();
  const mailLimpio = (email ?? '').trim();

  if (!hrefWa && !hrefMail && telLimpio === '' && mailLimpio === '') {
    return null;
  }

  const padding = compacto ? 'px-2 py-1' : 'px-2.5 py-1.5';

  function copiar(valor: string, aviso: string) {
    if (typeof navigator === 'undefined' || !navigator.clipboard) {
      mostrar('error', 'El portapapeles no está disponible en este navegador.');
      return;
    }
    navigator.clipboard.writeText(valor).then(
      () => mostrar('exito', aviso),
      () => mostrar('error', 'No se pudo copiar al portapapeles.'),
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {hrefWa ? (
        <a
          href={hrefWa}
          target="_blank"
          rel="noopener noreferrer"
          className={`${CLASE_BASE} ${padding} border-emerald-200 bg-emerald-50/70 text-emerald-900 hover:bg-emerald-100`}
        >
          <MessageCircle className="h-3.5 w-3.5" aria-hidden="true" />
          WhatsApp
        </a>
      ) : null}
      {hrefMail ? (
        <a href={hrefMail} className={`${CLASE_BASE} ${padding}`}>
          <Mail className="h-3.5 w-3.5" aria-hidden="true" />
          Correo
        </a>
      ) : null}
      {telLimpio !== '' ? (
        <button type="button" onClick={() => copiar(telLimpio, 'Teléfono copiado.')} className={`${CLASE_BASE} ${padding}`}>
          <Copy className="h-3.5 w-3.5" aria-hidden="true" />
          {compacto ? 'Tel.' : 'Copiar teléfono'}
        </button>
      ) : null}
      {mailLimpio !== '' ? (
        <button type="button" onClick={() => copiar(mailLimpio, 'Correo copiado.')} className={`${CLASE_BASE} ${padding}`}>
          <Copy className="h-3.5 w-3.5" aria-hidden="true" />
          {compacto ? 'Email' : 'Copiar correo'}
        </button>
      ) : null}
    </div>
  );
}

/**
 * Acciones rápidas para el grupo de WhatsApp del curso («Abrir grupo» y «Copiar enlace»).
 */
export function BotonesGrupoWhatsApp({ enlace, compacto = false }: { enlace?: string | null; compacto?: boolean }) {
  const { mostrar } = useAvisos();
  const limpio = (enlace ?? '').trim();

  if (!esEnlaceGrupoWhatsApp(limpio)) {
    return null;
  }

  const padding = compacto ? 'px-2 py-1' : 'px-2.5 py-1.5';

  function copiarEnlace() {
    if (typeof navigator === 'undefined' || !navigator.clipboard) {
      mostrar('error', 'El portapapeles no está disponible en este navegador.');
      return;
    }
    navigator.clipboard.writeText(limpio).then(
      () => mostrar('exito', 'Enlace del grupo de WhatsApp copiado.'),
      () => mostrar('error', 'No se pudo copiar el enlace.'),
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <a
        href={limpio}
        target="_blank"
        rel="noopener noreferrer"
        className={`${CLASE_BASE} ${padding} border-emerald-200 bg-emerald-50/70 text-emerald-900 hover:bg-emerald-100`}
      >
        <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
        Abrir grupo WhatsApp
      </a>
      <button type="button" onClick={copiarEnlace} className={`${CLASE_BASE} ${padding}`}>
        <Copy className="h-3.5 w-3.5" aria-hidden="true" />
        Copiar enlace
      </button>
    </div>
  );
}
