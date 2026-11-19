import { Suspense } from 'react';
import { AccionesPlataforma, BotonNuevaPlataforma } from '@/components/academico/acciones-infraestructura';
import { PestanasInfraestructura } from '@/components/academico/pestanas-infraestructura';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { PlataformaResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta } from '@/lib/panel/formato';

export const metadata = { title: 'Plataformas virtuales · Gestión académica' };

const RUTA = '/admin/academico/infraestructura/plataformas';
const PARAMETROS = ['busqueda', 'estado', 'tipo', 'sort', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function PlataformasPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Infraestructura"
        descripcion="Sedes, aulas y plataformas virtuales donde se dictan las ediciones."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Infraestructura' }]}
        acciones={<BotonNuevaPlataforma />}
      />
      <PestanasInfraestructura activa="plataformas" />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando plataformas" />}>
        <ListadoPlataformas searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function ListadoPlataformas({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: PlataformaResumen[]; meta: MetaListado }>(`academico/plataformas-virtuales${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: plataformas, meta } = resultado.datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Nombre de la plataforma" />
        <SelectorFiltro tono="academico" nombre="tipo" etiqueta="Tipo" valor={parametro(parametros, 'tipo')} opciones={ETIQUETAS.tipoPlataforma} />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoActivo} />
      </FiltrosListado>

      {plataformas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna plataforma coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay plataformas registradas" texto="Registra la primera con «Nueva plataforma»." />
        )
      ) : (
        <Tabla titulo={`Plataformas virtuales (${meta.total})`} columnas={['Plataforma', 'Tipo', 'Estado', { texto: 'Acciones', oculta: true, derecha: true }]} pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}>
          {plataformas.map((p) => (
            <tr key={p.identificador} className="hover:bg-academico-50/50">
              <td className="px-4 py-3 font-semibold text-panel-text">{p.nombre}</td>
              <td className="px-4 py-3 text-panel-muted">{etiqueta('tipoPlataforma', p.tipo)}</td>
              <td className="px-4 py-3"><Insignia color={colorEstado(p.estado)}>{etiqueta('estadoActivo', p.estado)}</Insignia></td>
              <td className="px-4 py-3"><AccionesPlataforma plataforma={p} /></td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
