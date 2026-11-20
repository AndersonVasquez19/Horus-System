import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { BotonesContacto } from '@/components/shared/ui/acciones-contacto';
import { CampoBusqueda, CampoFecha, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import type { TonoUi } from '@/components/shared/ui/boton';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeRespuestaContacto } from '@/lib/panel/contacto';
import { BotonAtenderMensaje, BotonAtenderVisibles } from './acciones-mensaje-contacto';

type Parametros = Record<string, string | string[] | undefined>;
const PARAMETROS = ['busqueda', 'estado', 'asunto', 'desde', 'hasta', 'page', 'per_page'] as const;

interface MetaMensajes extends MetaListado {
  asuntos?: string[];
  nuevos?: number;
}

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
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: MensajeContacto[]; meta: MetaMensajes }>(`compartido/reclamaciones-mensajes${cadena}`, `${ruta}?vista=mensajes`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: mensajes, meta } = resultado.datos;
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const actuales: Record<string, string> = {
    vista: 'mensajes',
    ...Object.fromEntries(
      PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
    ),
  };
  const asuntosUnicos = Array.from(new Set([...(meta.asuntos ?? []), ...mensajes.map((m) => m.asunto)].filter(Boolean)));
  const opcionesAsunto = Object.fromEntries(asuntosUnicos.map((a) => [a, a]));
  const pendientesVisibles = mensajes.filter((m) => m.estado === 'nuevo').map((m) => m.id);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <FiltrosListado tono={tono} ruta={ruta} rutaLimpiar={`${ruta}?vista=mensajes`} hayFiltros={hayFiltros}>
          <input type="hidden" name="vista" value="mensajes" />
          <CampoBusqueda tono={tono} nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Nombre, correo, asunto o texto" />
          <SelectorFiltro tono={tono} nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={{ nuevo: 'Sin atender', atendido: 'Atendidos' }} todos="Todos" />
          {asuntosUnicos.length > 0 ? (
            <SelectorFiltro tono={tono} nombre="asunto" etiqueta="Asunto" valor={parametro(parametros, 'asunto')} opciones={opcionesAsunto} todos="Todos" />
          ) : null}
          <CampoFecha tono={tono} nombre="desde" etiqueta="Desde" valor={parametro(parametros, 'desde')} />
          <CampoFecha tono={tono} nombre="hasta" etiqueta="Hasta" valor={parametro(parametros, 'hasta')} />
          <SelectorPorPagina tono={tono} valor={parametro(parametros, 'per_page')} />
        </FiltrosListado>
        <BotonAtenderVisibles identificadores={pendientesVisibles} tono={tono} />
      </div>

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
              <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                <BotonesContacto
                  telefono={m.telefono}
                  email={m.correo}
                  mensajeWa={mensajeRespuestaContacto({ nombre: m.nombre, asunto: m.asunto })}
                  asuntoCorreo={`Re: ${m.asunto}`}
                  cuerpoCorreo={mensajeRespuestaContacto({ nombre: m.nombre, asunto: m.asunto })}
                />
                {m.estado === 'nuevo' ? (
                  <BotonAtenderMensaje id={m.id} tono={tono} />
                ) : (
                  <p className="text-xs text-panel-muted">Atendido{m.atendido_por ? ` por ${m.atendido_por}` : ''}{m.atendido_local ? ` el ${m.atendido_local}` : ''}.</p>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      <Paginacion suelta meta={meta} ruta={ruta} parametros={actuales} />
    </div>
  );
}
