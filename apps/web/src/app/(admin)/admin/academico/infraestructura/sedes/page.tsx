import { Suspense } from 'react';
import { AccionesSede, BotonNuevaSede } from '@/components/academico/acciones-infraestructura';
import { PestanasInfraestructura } from '@/components/academico/pestanas-infraestructura';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { SedeResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta } from '@/lib/panel/formato';

export const metadata = { title: 'Sedes · Gestión académica' };

const RUTA = '/admin/academico/infraestructura/sedes';
const PARAMETROS = ['busqueda', 'estado', 'sort', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function SedesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Infraestructura"
        descripcion="Sedes, aulas y plataformas virtuales donde se dictan las ediciones."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Infraestructura' }]}
        acciones={<BotonNuevaSede />}
      />
      <PestanasInfraestructura activa="sedes" />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando sedes" />}>
        <ListadoSedes searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function ListadoSedes({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: SedeResumen[]; meta: MetaListado }>(`academico/sedes${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: sedes, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Código, nombre o ciudad" />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoActivo} />
      </FiltrosListado>

      {sedes.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna sede coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay sedes registradas" texto="Registra la primera con «Nueva sede»." />
        )
      ) : (
        <Tabla titulo={`Sedes (${meta.total})`} columnas={['Sede', 'Dirección', 'Aulas', 'Estado', { texto: 'Acciones', oculta: true, derecha: true }]} pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}>
          {sedes.map((sede) => (
            <tr key={sede.identificador} className="hover:bg-academico-50/50">
              <td className="px-4 py-3">
                <p className="font-semibold text-panel-text">{sede.nombre}</p>
                <p className="font-mono text-xs text-panel-muted">{sede.codigo}</p>
              </td>
              <td className="px-4 py-3 text-panel-muted">
                <p className="max-w-64 truncate">{sede.direccion}</p>
                <p className="text-xs">{sede.ciudad}</p>
              </td>
              <td className="px-4 py-3 text-panel-muted">{sede.aulas}</td>
              <td className="px-4 py-3"><Insignia color={colorEstado(sede.estado)}>{etiqueta('estadoActivo', sede.estado)}</Insignia></td>
              <td className="px-4 py-3"><AccionesSede sede={sede} /></td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
