import Link from 'next/link';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, claseControl, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, type ColorInsignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { ReclamacionFila } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { fecha } from '@/lib/panel/formato';

type Parametros = Record<string, string | string[] | undefined>;

const PARAMETROS = ['busqueda', 'tipo', 'estado', 'desde', 'hasta', 'page'] as const;

export const ESTADOS_RECLAMACION: Record<string, { texto: string; color: ColorInsignia }> = {
  abierto: { texto: 'Abierto', color: 'ambar' },
  respondido: { texto: 'Respondido', color: 'verde' },
  concluido_por_acuerdo: { texto: 'Concluido por acuerdo', color: 'azul' },
};

interface MetaBandeja extends MetaListado {
  metricas: { total: number; quejas: number; reclamos: number; abiertos: number };
}

/** Bandeja del Libro de Reclamaciones. La monta cada panel bajo `/admin/<panel>/reclamaciones`. */
export function PaginaReclamaciones({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Libro de reclamaciones"
        descripcion="Quejas y reclamos recibidos, con su plazo legal de respuesta. Contienen datos personales: úsalos solo para atender el caso."
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
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ReclamacionFila[]; meta: MetaBandeja }>(`compartido/reclamaciones${cadena}`, `${ruta}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: filas, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {([['Total', meta.metricas.total], ['Abiertas', meta.metricas.abiertos], ['Reclamos', meta.metricas.reclamos], ['Quejas', meta.metricas.quejas]] as const).map(([titulo, valor]) => (
          <div key={titulo} className="rounded-xl border border-panel-border bg-panel-surface p-4 shadow-sm">
            <dt className="text-xs text-panel-muted">{titulo}</dt>
            <dd className="font-heading text-2xl font-semibold text-panel-text">{valor}</dd>
          </div>
        ))}
      </dl>

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
        {(['desde', 'hasta'] as const).map((nombre) => (
          <div key={nombre}>
            <label htmlFor={nombre} className="mb-1 block text-xs font-medium text-panel-muted">{nombre === 'desde' ? 'Desde' : 'Hasta'}</label>
            <input id={nombre} name={nombre} type="date" defaultValue={parametro(parametros, nombre)} className={claseControl(tono)} />
          </div>
        ))}
      </FiltrosListado>

      {filas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna reclamación coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay reclamaciones" texto="Las quejas y reclamos del libro aparecerán aquí." />
        )
      ) : (
        <Tabla
          titulo={`Reclamaciones (${meta.total})`}
          columnas={['N.º', 'Consumidor', 'Tipo', 'Estado', 'Recibida (Lima)', 'Fecha límite']}
          pie={<Paginacion meta={meta} ruta={ruta} parametros={actuales} />}
        >
          {filas.map((fila) => {
            const estado = ESTADOS_RECLAMACION[fila.estado] ?? { texto: fila.estado, color: 'gris' as const };
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
                  {fecha(fila.fecha_limite)}
                  {fila.plazo_suspendido ? <span className="block text-xs text-amber-800">Plazo suspendido</span> : null}
                </td>
              </tr>
            );
          })}
        </Tabla>
      )}
    </div>
  );
}
