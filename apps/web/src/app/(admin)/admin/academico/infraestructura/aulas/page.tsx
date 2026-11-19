import { Suspense } from 'react';
import { AccionesAula, BotonNuevaAula } from '@/components/academico/acciones-infraestructura';
import { PestanasInfraestructura } from '@/components/academico/pestanas-infraestructura';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { AulaResumen, SedeResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta } from '@/lib/panel/formato';

export const metadata = { title: 'Aulas · Gestión académica' };

const RUTA = '/admin/academico/infraestructura/aulas';
const PARAMETROS = ['busqueda', 'estado', 'sede', 'sort', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function AulasPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando aulas" />}>
      <Contenido searchParams={searchParams} />
    </Suspense>
  );
}

async function Contenido({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const [aulas, sedes] = await Promise.all([
    consultarPanel<{ data: AulaResumen[]; meta: MetaListado }>(`academico/aulas${cadena}`, `${RUTA}${cadena}`),
    // Sedes activas para el selector del formulario y el filtro (hasta 100).
    consultarPanel<{ data: SedeResumen[] }>('academico/sedes?estado=activo&per_page=100&sort=nombre', RUTA),
  ]);

  const opcionesSede = sedes.estado === 'ok' ? sedes.datos.data.map((s) => ({ identificador: s.identificador, codigo: s.codigo, nombre: s.nombre })) : [];

  return (
    <>
      <EncabezadoPagina
        titulo="Infraestructura"
        descripcion="Sedes, aulas y plataformas virtuales donde se dictan las ediciones."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Infraestructura' }]}
        acciones={<BotonNuevaAula sedes={opcionesSede} />}
      />
      <PestanasInfraestructura activa="aulas" />
      {aulas.estado === 'prohibido' ? (
        <EstadoProhibido volver="/admin/academico" />
      ) : aulas.estado !== 'ok' ? (
        <EstadoError texto="Inténtalo de nuevo en unos minutos." />
      ) : (
        <Listado parametros={parametros} datos={aulas.datos} sedes={opcionesSede} />
      )}
    </>
  );
}

function Listado({ parametros, datos, sedes }: { parametros: Parametros; datos: { data: AulaResumen[]; meta: MetaListado }; sedes: { identificador: string; codigo: string; nombre: string }[] }) {
  const { data: aulas, meta } = datos;
  const actuales = Object.fromEntries(PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]));
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const opcionesFiltroSede = Object.fromEntries(sedes.map((s) => [s.identificador, `${s.codigo} · ${s.nombre}`]));

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Código o nombre del aula" />
        <SelectorFiltro tono="academico" nombre="sede" etiqueta="Sede" valor={parametro(parametros, 'sede')} opciones={opcionesFiltroSede} />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoActivo} />
      </FiltrosListado>

      {aulas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún aula coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay aulas registradas" texto={sedes.length === 0 ? 'Primero registra una sede.' : 'Registra la primera con «Nueva aula».'} />
        )
      ) : (
        <Tabla titulo={`Aulas (${meta.total})`} columnas={['Aula', 'Sede', 'Capacidad', 'Estado', { texto: 'Acciones', oculta: true, derecha: true }]} pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}>
          {aulas.map((aula) => (
            <tr key={aula.identificador} className="hover:bg-academico-50/50">
              <td className="px-4 py-3">
                <p className="font-semibold text-panel-text">{aula.nombre}</p>
                <p className="font-mono text-xs text-panel-muted">{aula.codigo}</p>
              </td>
              <td className="px-4 py-3 text-panel-muted">{aula.sede ? `${aula.sede.codigo} · ${aula.sede.nombre}` : '—'}</td>
              <td className="px-4 py-3 text-panel-muted">{aula.capacidad} personas</td>
              <td className="px-4 py-3"><Insignia color={colorEstado(aula.estado)}>{etiqueta('estadoActivo', aula.estado)}</Insignia></td>
              <td className="px-4 py-3"><AccionesAula aula={aula} sedes={sedes} /></td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
