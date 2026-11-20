'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Bell, Check, ExternalLink } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useOperacionPanel } from './use-operacion-panel';
import { useAvisos } from './ui/avisos';
import { Insignia } from './ui/insignia';
import type { Notificacion } from '@/features/notificaciones/tipos';
import type { TonoPanel } from './panel-tipos';

const TOPE_VISIBLE = 99;

const CLASE_DISPARADOR =
  'relative inline-flex items-center rounded-md p-2 text-panel-text outline-offset-2 hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-academico-700';

interface DesplegableCampanaProps {
  tono: TonoPanel;
  iniciales: readonly Notificacion[];
  pendientesIniciales: number | null;
}

/**
 * Campana interactiva del encabezado: muestra las últimas notificaciones no leídas sin cambiar de página,
 * permite marcarlas como leídas y enlaza a la bandeja completa del usuario.
 */
export function DesplegableCampana({ tono, iniciales, pendientesIniciales }: DesplegableCampanaProps) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [leidasIds, setLeidasIds] = useState<number[]>([]);
  const [pendientesRemoto, setPendientesRemoto] = useState<number | null>(null);
  const [ocupadoId, setOcupadoId] = useState<number | null>(null);
  const contenedorRef = useRef<HTMLDivElement>(null);
  const idPanel = useId();

  const items = iniciales.filter((item) => !leidasIds.includes(item.id));
  const pendientes = pendientesRemoto ?? pendientesIniciales;

  useEffect(() => {
    if (!abierto) return;
    function alPulsar(e: MouseEvent) {
      if (contenedorRef.current && !contenedorRef.current.contains(e.target as Node)) {
        setAbierto(false);
      }
    }
    function alTeclear(e: KeyboardEvent) {
      if (e.key === 'Escape') setAbierto(false);
    }
    document.addEventListener('mousedown', alPulsar);
    document.addEventListener('keydown', alTeclear);
    return () => {
      document.removeEventListener('mousedown', alPulsar);
      document.removeEventListener('keydown', alTeclear);
    };
  }, [abierto]);

  async function marcarLeida(id: number) {
    setOcupadoId(id);
    const resultado = await ejecutar<{ meta?: { pendientes?: number } }>(`compartido/notificaciones/${id}/leida`, 'POST');
    setOcupadoId(null);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    setLeidasIds((actuales) => (actuales.includes(id) ? actuales : [...actuales, id]));
    const restantes = resultado.datos.meta?.pendientes;
    if (typeof restantes === 'number') setPendientesRemoto(restantes);
    else if (pendientes !== null && pendientes > 0) setPendientesRemoto(pendientes - 1);
    router.refresh();
  }

  const etiqueta =
    pendientes === null ? 'Notificaciones' : pendientes === 0 ? 'Notificaciones, ninguna sin leer' : `Notificaciones, ${pendientes} sin leer`;

  return (
    <div ref={contenedorRef} className="relative">
      <button
        type="button"
        onClick={() => setAbierto((v) => !v)}
        aria-expanded={abierto}
        aria-controls={idPanel}
        aria-haspopup="true"
        aria-label={etiqueta}
        title="Notificaciones"
        className={CLASE_DISPARADOR}
      >
        <Bell className="h-5 w-5" aria-hidden="true" />
        {pendientes !== null && pendientes > 0 ? (
          <span
            aria-hidden="true"
            className="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-tinta px-1 text-[11px] font-bold leading-5 text-white"
          >
            {pendientes > TOPE_VISIBLE ? `${TOPE_VISIBLE}+` : pendientes}
          </span>
        ) : null}
      </button>

      {abierto ? (
        <div
          id={idPanel}
          role="region"
          aria-label="Notificaciones recientes"
          className="fixed right-3 top-14 z-50 w-[min(22rem,calc(100vw-1.5rem))] rounded-xl border border-panel-border bg-panel-surface shadow-xl sm:absolute sm:right-0 sm:top-full sm:mt-2"
        >
          <div className="flex items-center justify-between border-b border-panel-border px-4 py-2.5">
            <p className="font-heading text-sm font-semibold text-panel-text">
              Notificaciones{pendientes && pendientes > 0 ? ` (${pendientes})` : ''}
            </p>
            <Link
              href={`/admin/${tono}/notificaciones`}
              onClick={() => setAbierto(false)}
              className="text-xs font-semibold text-academico-700 hover:underline"
            >
              Ver todas
            </Link>
          </div>

          {items.length === 0 ? (
            <p className="px-4 py-6 text-center text-sm text-panel-muted">No tienes notificaciones sin leer.</p>
          ) : (
            <ul className="max-h-80 divide-y divide-panel-border overflow-y-auto">
              {items.map((n) => (
                <li key={n.id} className="space-y-1.5 px-4 py-3 text-sm">
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <Insignia color="azul">{n.tipo_etiqueta ?? 'Aviso'}</Insignia>
                      {n.fecha_local ? <span className="text-[11px] text-panel-muted">{n.fecha_local}</span> : null}
                    </div>
                    <button
                      type="button"
                      onClick={() => void marcarLeida(n.id)}
                      disabled={ocupadoId === n.id}
                      title="Marcar como leída"
                      aria-label={`Marcar «${n.titulo}» como leída`}
                      className="rounded p-1 text-panel-muted hover:bg-panel-bg hover:text-panel-text disabled:opacity-50"
                    >
                      <Check className="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                  </div>
                  <p className="font-medium text-panel-text">{n.titulo}</p>
                  {n.mensaje ? <p className="text-xs text-panel-muted">{n.mensaje}</p> : null}
                  {n.vinculo ? (
                    <Link
                      href={n.vinculo}
                      onClick={() => setAbierto(false)}
                      className="inline-flex items-center gap-1 text-xs font-semibold text-academico-700 hover:underline"
                    >
                      <span>{n.enlace_texto || 'Abrir'}</span>
                      <ExternalLink className="h-3 w-3" aria-hidden="true" />
                    </Link>
                  ) : null}
                </li>
              ))}
            </ul>
          )}

          <div className="border-t border-panel-border px-4 py-2 text-center">
            <Link
              href={`/admin/${tono}/notificaciones`}
              onClick={() => setAbierto(false)}
              className="text-xs font-medium text-panel-muted hover:text-panel-text hover:underline"
            >
              Abrir bandeja de notificaciones
            </Link>
          </div>
        </div>
      ) : null}
    </div>
  );
}
