import Link from 'next/link';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { BotonExportarListado } from '@/components/shared/ui/boton-exportar-listado';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, SelectorPorPagina, claseControl, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, type ColorInsignia } from '@/components/shared/ui/insignia';
import { MenuFila } from '@/components/shared/ui/menu-fila';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { ReclamacionFila } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { fecha, hoyLima } from '@/lib/panel/formato';
import { AsignacionLoteReclamaciones } from './lote-reclamaciones';
import { MensajesContacto } from './pagina-mensajes-contacto';

type Parametros = Record<string, string | string[] | undefined>;

const PARAMETROS = ['busqueda', 'tipo', 'estado', 'canal', 'responsable', 'plazo', 'desde', 'hasta', 'sort', 'page', 'per_page'] as const;
const CANALES_RECLAMACION = { web: 'Libro web', respaldo: 'Hoja de respaldo' };
const PLAZOS_RECLAMACION = { vencidas: 'Vencidas', vencen_hoy: 'Vencen hoy', por_vencer: 'Por vencer' };

export const ESTADOS_RECLAMACION: Record<string, { texto: string; color: ColorInsignia }> = {
  abierto: { texto: 'Abierto', color: 'ambar' },
  respondido: { texto: 'Respondido', color: 'verde' },
  concluido_por_acuerdo: { texto: 'Concluido por acuerdo', color: 'azul' },
};

interface MetaBandeja extends MetaListado {
  metricas: { total: number; quejas: number; reclamos: number; abiertos: number };
  plazos?: { vencidas: number; vencen_hoy: number; por_vencer: number };
  responsables?: { id: number; name: string }[];
}

function diasCalendario(desdeIso: string, hastaIso: string): number {
  const [a1, m1, d1] = desdeIso.split('-').map(Number);
  const [a2, m2, d2] = hastaIso.split('-').map(Number);
  if (!a1 || !m1 || !d1 || !a2 || !m2 || !d2) return 0;
  return Math.round((Date.UTC(a2, m2 - 1, d2) - Date.UTC(a1, m1 - 1, d1)) / 86_400_000);
}

function semaforoPlazo(fila: ReclamacionFila, hoy: string): { texto: string; color: ColorInsignia } | null {
  if (fila.estado !== 'abierto') return null;
  if (fila.plazo_suspendido) return { texto: 'Plazo suspendido', color: 'ambar' };
  const dias = diasCalendario(hoy, fila.fecha_limite);
  if (dias < 0) return { texto: `Vencida hace ${Math.abs(dias)} d`, color: 'rojo' };
  if (dias === 0) return { texto: 'Vence hoy', color: 'rojo' };
  if (dias <= 3) return { texto: `Vence en ${dias} d`, color: 'ambar' };
  return { texto: `${dias} d restantes`, color: 'verde' };
}

/** Bandeja del Libro de Reclamaciones. La monta cada panel bajo `/admin/<panel>/reclamaciones`. */
export function PaginaReclamaciones({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Libro de reclamaciones"
        descripcion="Quejas y reclamos recibidos, con su plazo legal de respuesta, y los mensajes enviados desde «Contáctanos». Contienen datos personales: úsalos solo para atender el caso."
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Reclamaciones' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando las reclamaciones" />}>
        <Bandeja tono={tono} searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Bandeja({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  const ruta = `/admin/${tono}/reclamaciones`;
  const parametros = await searchParams;
  const vista = parametro(parametros, 'vista') === 'mensajes' ? 'mensajes' : 'reclamaciones';
  // Cuántos mensajes de contacto esperan respuesta (para la pestaña); si falla, la pestaña sale sin número.
  const nuevos = await consultarPanel<{ meta: { nuevos?: number } }>('compartido/reclamaciones-mensajes?estado=nuevo&per_page=1', `${ruta}?vista=mensajes`);
  const pendientes = nuevos.estado === 'ok' ? (nuevos.datos.meta.nuevos ?? 0) : 0;
  const pestanas = (
    <nav aria-label="Vistas" className="flex gap-2 border-b border-panel-border">
      {([['reclamaciones', 'Reclamaciones', ruta], ['mensajes', pendientes > 0 ? `Mensajes de contacto (${pendientes} nuevos)` : 'Mensajes de contacto', `${ruta}?vista=mensajes`]] as const).map(([clave, texto, href]) => (
        <Link key={clave} href={href} aria-current={vista === clave ? 'page' : undefined} className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${vista === clave ? 'border-panel-text text-panel-text' : 'border-transparent text-panel-muted hover:text-panel-text'}`}>
          {texto}
        </Link>
      ))}
    </nav>
  );
  if (vista === 'mensajes') {
    return (
      <div className="space-y-4">
        {pestanas}
        <MensajesContacto tono={tono} parametros={parametros} />
      </div>
    );
  }
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ReclamacionFila[]; meta: MetaBandeja }>(`compartido/reclamaciones${cadena}`, `${ruta}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: filas, meta } = resultado.datos;
  const sort = parametro(parametros, 'sort');
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const actualesOrden = Object.fromEntries(
    Object.entries(actuales).filter(([clave]) => clave !== 'sort'),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const opcionesResponsable: Record<string, string> = {
    mio: 'Mis casos',
    sin_asignar: 'Sin asignar',
    ...Object.fromEntries((meta.responsables ?? []).map((r) => [String(r.id), r.name])),
  };
  const hoy = hoyLima();
  const plazos = meta.plazos;
  const hayAlertasPlazo = plazos && (plazos.vencidas > 0 || plazos.vencen_hoy > 0 || plazos.por_vencer > 0);

  return (
    <div className="space-y-4">
      {pestanas}
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {([['Total', meta.metricas.total], ['Abiertas', meta.metricas.abiertos], ['Reclamos', meta.metricas.reclamos], ['Quejas', meta.metricas.quejas]] as const).map(([titulo, valor]) => (
          <div key={titulo} className="rounded-xl border border-panel-border bg-panel-surface p-4 shadow-sm">
            <dt className="text-xs text-panel-muted">{titulo}</dt>
            <dd className="font-heading text-2xl font-semibold text-panel-text">{valor}</dd>
          </div>
        ))}
      </dl>

      {hayAlertasPlazo ? (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm" role="region" aria-label="Alertas de plazos">
          <span className="font-medium text-amber-950">Plazos por atender:</span>
          {plazos.vencidas > 0 ? (
            <Link href={`${ruta}?plazo=vencidas`} className="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-900 hover:bg-red-200">
              Vencidas ({plazos.vencidas})
            </Link>
          ) : null}
          {plazos.vencen_hoy > 0 ? (
            <Link href={`${ruta}?plazo=vencen_hoy`} className="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-900 hover:bg-red-200">
              Vencen hoy ({plazos.vencen_hoy})
            </Link>
          ) : null}
          {plazos.por_vencer > 0 ? (
            <Link href={`${ruta}?plazo=por_vencer`} className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-200">
              Por vencer ({plazos.por_vencer})
            </Link>
          ) : null}
        </div>
      ) : null}

      <FiltrosListado tono={tono} ruta={ruta} hayFiltros={hayFiltros}>
        <CampoBusqueda tono={tono} nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="N.º o nombre del consumidor" />
        <SelectorFiltro tono={tono} nombre="tipo" etiqueta="Tipo" valor={parametro(parametros, 'tipo')} opciones={{ reclamo: 'Reclamo', queja: 'Queja' }} />
        <SelectorFiltro
          tono={tono}
          nombre="estado"
          etiqueta="Estado"
          valor={parametro(parametros, 'estado')}
          opciones={Object.fromEntries(Object.entries(ESTADOS_RECLAMACION).map(([clave, { texto }]) => [clave, texto]))}
        />
        <SelectorFiltro tono={tono} nombre="canal" etiqueta="Canal" valor={parametro(parametros, 'canal')} opciones={CANALES_RECLAMACION} />
        <SelectorFiltro tono={tono} nombre="responsable" etiqueta="Responsable" valor={parametro(parametros, 'responsable')} opciones={opcionesResponsable} />
        <SelectorFiltro tono={tono} nombre="plazo" etiqueta="Plazo" valor={parametro(parametros, 'plazo')} opciones={PLAZOS_RECLAMACION} />
        {(['desde', 'hasta'] as const).map((nombre) => (
          <div key={nombre}>
            <label htmlFor={nombre} className="mb-1 block text-xs font-medium text-panel-muted">{nombre === 'desde' ? 'Desde' : 'Hasta'}</label>
            <input id={nombre} name={nombre} type="date" defaultValue={parametro(parametros, nombre)} className={claseControl(tono)} />
          </div>
        ))}
        <SelectorPorPagina tono={tono} valor={parametro(parametros, 'per_page')} />
      </FiltrosListado>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <Link
            href={`${ruta}?responsable=mio`}
            className={`rounded-full border px-3 py-1 font-semibold transition ${parametro(parametros, 'responsable') === 'mio' ? 'border-panel-text bg-panel-text text-white' : 'border-panel-border bg-panel-surface text-panel-text hover:bg-panel-bg'}`}
          >
            Mis casos
          </Link>
          <Link
            href={`${ruta}?responsable=sin_asignar`}
            className={`rounded-full border px-3 py-1 font-semibold transition ${parametro(parametros, 'responsable') === 'sin_asignar' ? 'border-panel-text bg-panel-text text-white' : 'border-panel-border bg-panel-surface text-panel-text hover:bg-panel-bg'}`}
          >
            Sin asignar
          </Link>
        </div>
        <BotonExportarListado
          ruta="compartido/reclamaciones/exportar"
          filtros={consulta(parametros, ['busqueda', 'tipo', 'estado', 'canal', 'responsable', 'plazo', 'desde', 'hasta', 'sort'])}
          permiso="reclamaciones.ver"
        />
      </div>

      <AsignacionLoteReclamaciones
        casos={filas.filter((f) => f.estado === 'abierto').map((f) => ({ id: f.id, numero: f.numero, consumidor: f.consumidor }))}
        responsables={meta.responsables ?? []}
        tono={tono}
      />

      {filas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna reclamación coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay reclamaciones" texto="Las quejas y reclamos del libro aparecerán aquí." />
        )
      ) : (
        <Tabla accionesFijas
          titulo={`Reclamaciones (${meta.total})`}
          columnas={[
            { texto: 'N.º', orden: 'numero' },
            'Consumidor',
            'Tipo',
            'Estado',
            { texto: 'Recibida (Lima)', orden: 'recibida' },
            { texto: 'Fecha límite', orden: 'limite' },
            { texto: 'Acciones', oculta: true, derecha: true },
          ]}
          ordenActual={sort || '-recibida'}
          rutaOrden={ruta}
          parametrosOrden={actualesOrden}
          pie={<Paginacion meta={meta} ruta={ruta} parametros={actuales} />}
        >
          {filas.map((fila) => {
            const estado = ESTADOS_RECLAMACION[fila.estado] ?? { texto: fila.estado, color: 'gris' as const };
            const semaforo = semaforoPlazo(fila, hoy);
            return (
              <tr key={fila.id}>
                <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">
                  <Link href={`${ruta}/${fila.id}`} className="font-semibold text-panel-text hover:underline">{fila.numero}</Link>
                </td>
                <td className="px-4 py-3 text-panel-text">{fila.consumidor}</td>
                <td className="px-4 py-3"><Insignia color={fila.tipo === 'reclamo' ? 'azul' : 'gris'}>{fila.tipo === 'reclamo' ? 'Reclamo' : 'Queja'}</Insignia></td>
                <td className="px-4 py-3"><Insignia color={estado.color}>{estado.texto}</Insignia></td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fila.recibida_local}</td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">
                  <span>{fecha(fila.fecha_limite)}</span>
                  {semaforo ? (
                    <div className="mt-1">
                      <Insignia color={semaforo.color}>{semaforo.texto}</Insignia>
                    </div>
                  ) : null}
                </td>
                <td className="px-4 py-3 text-right">
                  <MenuFila
                    etiqueta={`Acciones para reclamación ${fila.numero}`}
                    elementos={[
                      { tipo: 'enlace', etiqueta: 'Ver expediente', href: `${ruta}/${fila.id}` },
                      { tipo: 'enlace', etiqueta: 'Hoja imprimible', href: `${ruta}/${fila.id}/hoja` },
                      { tipo: 'copiar', etiqueta: 'Copiar N.º de expediente', valor: fila.numero, aviso: 'Número de expediente copiado.' },
                    ]}
                  />
                </td>
              </tr>
            );
          })}
        </Tabla>
      )}
    </div>
  );
}
