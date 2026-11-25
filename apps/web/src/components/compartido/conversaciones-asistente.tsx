import { ChevronRight, ThumbsDown, ThumbsUp } from 'lucide-react';
import { etiquetaTema } from '@/components/compartido/temas-asistente';
import { Insignia } from '@/components/shared/ui/insignia';
import { TextoPlegable } from '@/components/shared/ui/texto-plegable';

export interface Intercambio {
  id: number;
  hora_local: string;
  tema: string | null;
  respuesta: string;
  privacidad: boolean;
  contingencia: boolean;
  voto: 'up' | 'down' | null;
}

export interface Conversacion {
  clave: number;
  /** Código corto para distinguir visitantes; no identifica a nadie. */
  visitante: string;
  probador: boolean;
  ultima_local: string;
  total: number;
  temas: string[];
  utiles: number;
  no_utiles: number;
  intercambios: Intercambio[];
}

const TEMAS_EN_LA_CABECERA = 3;

function Votos({ utiles, noUtiles }: { utiles: number; noUtiles: number }) {
  if (utiles === 0 && noUtiles === 0) return null;
  return (
    <span className="inline-flex items-center gap-2 text-xs font-semibold">
      {utiles > 0 ? (
        <span className="inline-flex items-center gap-1 text-green-800">
          <ThumbsUp className="h-3.5 w-3.5" aria-hidden="true" /> {utiles}
          <span className="sr-only"> votos útiles</span>
        </span>
      ) : null}
      {noUtiles > 0 ? (
        <span className="inline-flex items-center gap-1 text-amber-800">
          <ThumbsDown className="h-3.5 w-3.5" aria-hidden="true" /> {noUtiles}
          <span className="sr-only"> votos no útiles</span>
        </span>
      ) : null}
    </span>
  );
}

/**
 * Conversaciones agrupadas por visitante. Cada una se abre y se cierra con `<details>` (teclado y lector de pantalla
 * sin JavaScript). La pregunta no se guarda: se muestra el tema, si se registró, y lo que respondió el asistente.
 */
export function ConversacionesAsistente({ conversaciones }: { conversaciones: Conversacion[] }) {
  return (
    <ul className="space-y-3">
      {conversaciones.map((conversacion) => {
        const nombre = conversacion.probador ? 'Probador del panel' : `Visitante ${conversacion.visitante}`;
        const restantes = conversacion.temas.length - TEMAS_EN_LA_CABECERA;
        return (
          <li key={conversacion.clave}>
            {/* Grupo con nombre: `TextoPlegable` usa `group-open` y, sin nombre, tomaría el estado de esta tarjeta. */}
            <details className="group/conversacion overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
              <summary className="flex cursor-pointer list-none items-start gap-3 px-4 py-3 outline-offset-[-2px] hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-academico-700 [&::-webkit-details-marker]:hidden">
                <ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-panel-muted transition-transform group-open/conversacion:rotate-90" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <h2 className="text-sm font-semibold text-panel-text">{nombre}</h2>
                    <p className="text-xs text-panel-muted">
                      {conversacion.ultima_local} · {conversacion.total} {conversacion.total === 1 ? 'consulta' : 'consultas'}
                    </p>
                  </div>
                  <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                    {conversacion.temas.slice(0, TEMAS_EN_LA_CABECERA).map((tema) => (
                      <Insignia key={tema} color="azul">{etiquetaTema(tema)}</Insignia>
                    ))}
                    {restantes > 0 ? <span className="text-xs text-panel-muted">y {restantes} más</span> : null}
                    <Votos utiles={conversacion.utiles} noUtiles={conversacion.no_utiles} />
                  </div>
                </div>
              </summary>

              <div className="border-t border-panel-border bg-panel-bg px-4 py-4">
                {conversacion.total > conversacion.intercambios.length ? (
                  <p className="mb-3 text-xs text-panel-muted">Se muestran las últimas {conversacion.intercambios.length} consultas.</p>
                ) : null}
                <ol className="space-y-4">
                  {conversacion.intercambios.map((intercambio) => (
                    <li key={intercambio.id}>
                      <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-panel-muted">
                        <span className="tabular-nums">{intercambio.hora_local}</span>
                        <span className="font-medium text-panel-text">
                          {conversacion.probador ? 'Se preguntó' : 'Visitante preguntó'}
                          {intercambio.tema ? ` sobre: ${etiquetaTema(intercambio.tema)}` : ''}
                        </span>
                        {intercambio.privacidad ? (
                          <Insignia color="ambar">Bloqueada por privacidad</Insignia>
                        ) : intercambio.contingencia ? (
                          <Insignia color="ambar">Contingencia</Insignia>
                        ) : null}
                      </p>
                      <div className="mt-1.5 flex items-end gap-2">
                        <div className="min-w-0 max-w-2xl break-words rounded-2xl rounded-tl-sm border border-panel-border bg-panel-surface px-4 py-2.5 text-sm text-panel-text">
                          <span className="sr-only">Horus respondió: </span>
                          <TextoPlegable texto={intercambio.respuesta} limite={320} />
                        </div>
                        {intercambio.voto === 'up' ? <Insignia color="verde">Útil</Insignia> : null}
                        {intercambio.voto === 'down' ? <Insignia color="ambar">No útil</Insignia> : null}
                      </div>
                    </li>
                  ))}
                </ol>
              </div>
            </details>
          </li>
        );
      })}
    </ul>
  );
}
