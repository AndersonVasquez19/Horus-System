import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { TonoUi } from '@/components/shared/ui/boton';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { BotonAtenderMensaje } from './acciones-mensaje-contacto';

type Parametros = Record<string, string | string[] | undefined>;

interface MensajeContacto {
  id: string;
  nombre: string;
  telefono: string | null;
  correo: string | null;
  asunto: string;
  mensaje: string;
  estado: 'nuevo' | 'atendido';
  recibido_local: string | null;
  atendido_por: string | null;
  atendido_local: string | null;
}

/** Mensajes enviados desde «Contáctanos». Contienen datos personales: úsalos solo para responder a la persona. */
export async function MensajesContacto({ tono, parametros }: { tono: TonoUi; parametros: Parametros }) {
  const ruta = `/admin/${tono}/reclamaciones`;
  const cadena = consulta(parametros, ['estado', 'page']);
  const resultado = await consultarPanel<{ data: MensajeContacto[]; meta: MetaListado }>(`compartido/reclamaciones-mensajes${cadena}`, `${ruta}?vista=mensajes`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: mensajes, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales: Record<string, string> = { vista: 'mensajes', ...(parametro(parametros, 'estado') ? { estado: parametro(parametros, 'estado') } : {}) };

  return (
    <div className="space-y-4">
      <FiltrosListado tono={tono} ruta={ruta} hayFiltros={hayFiltros}>
        <input type="hidden" name="vista" value="mensajes" />
        <SelectorFiltro tono={tono} nombre="estado" etiqueta="Mostrar" valor={parametro(parametros, 'estado')} opciones={{ nuevo: 'Solo sin atender', atendido: 'Solo atendidos' }} todos="Todos" />
      </FiltrosListado>

      {mensajes.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún mensaje coincide con el filtro" texto="Prueba con otro criterio o limpia el filtro." />
        ) : (
          <EstadoVacio titulo="Todavía no hay mensajes" texto="Lo que la gente escriba en «Contáctanos» aparecerá aquí." />
        )
      ) : (
        <ul className="space-y-3">
          {mensajes.map((m) => (
            <li key={m.id} className="space-y-2 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h2 className="font-heading text-base font-semibold text-panel-text">{m.nombre}</h2>
                  <p className="text-xs text-panel-muted">
                    {[m.telefono, m.correo].filter(Boolean).join(' · ') || 'Sin datos de contacto'} · {m.recibido_local ?? '—'}
                  </p>
                </div>
                <div className="flex gap-1">
                  <Insignia color="azul">{m.asunto}</Insignia>
                  <Insignia color={m.estado === 'nuevo' ? 'ambar' : 'verde'}>{m.estado === 'nuevo' ? 'Sin atender' : 'Atendido'}</Insignia>
                </div>
              </div>
              <p className="whitespace-pre-line text-sm text-panel-text">{m.mensaje}</p>
              {m.estado === 'nuevo' ? (
                <BotonAtenderMensaje id={m.id} tono={tono} />
              ) : (
                <p className="text-xs text-panel-muted">Atendido{m.atendido_por ? ` por ${m.atendido_por}` : ''}{m.atendido_local ? ` el ${m.atendido_local}` : ''}.</p>
              )}
            </li>
          ))}
        </ul>
      )}

      <Paginacion meta={meta} ruta={ruta} parametros={actuales} />
    </div>
  );
}
