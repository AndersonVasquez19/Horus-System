import Link from 'next/link';
import { Suspense } from 'react';
import { ConversacionesAsistente, type Conversacion } from '@/components/compartido/conversaciones-asistente';
import { MetricasAsistente, type Metricas } from '@/components/compartido/metricas-asistente';
import { ProbadorAsistente } from '@/components/compartido/probador-asistente';
import { etiquetaTema } from '@/components/compartido/temas-asistente';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { FiltrosListado, CampoBusqueda, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Seccion } from '@/components/shared/ui/tabla';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Asistente virtual' };

type Parametros = Record<string, string | string[] | undefined>;
const RUTA = '/admin/academico/asistente';
const PARAMETROS = ['vista', 'busqueda', 'intent', 'fuente', 'voto', 'canal', 'dias', 'page'] as const;
const VISTAS = [
  { id: 'resumen', etiqueta: 'Resumen', href: RUTA },
  { id: 'conversaciones', etiqueta: 'Conversaciones', href: `${RUTA}?vista=conversaciones` },
  { id: 'probar', etiqueta: 'Probar asistente', href: `${RUTA}?vista=probar` },
] as const;

type Vista = (typeof VISTAS)[number]['id'];

interface Datos {
  vista: Vista;
  horus_ai?: { habilitado: boolean };
  metricas?: Metricas;
  conversaciones?: Conversacion[];
  temas?: string[];
  con_votos?: boolean;
  separa_canal?: boolean;
}

export default function AsistentePage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Asistente virtual"
        descripcion="Revisa cómo se usa el asistente, lee sus conversaciones y pruébalo."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Asistente virtual' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando el asistente" />}>
        <Contenido searchParams={searchParams} />
      </Suspense>
    </>
  );
}

/** Pestañas como enlaces: cada una tiene su dirección, así filtrar o paginar no devuelve a la primera. */
function PestanasAsistente({ activa, habilitado }: { activa: Vista; habilitado: boolean }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-panel-border pb-2">
      <nav aria-label="Secciones del asistente" className="flex flex-wrap gap-1.5">
        {VISTAS.map((vista) => (
          <Link
            key={vista.id}
            href={vista.href}
            aria-current={vista.id === activa ? 'page' : undefined}
            className={`inline-flex min-h-10 items-center rounded-lg px-3.5 text-sm font-semibold transition ${
              vista.id === activa ? 'bg-academico-700 text-white shadow-sm' : 'text-panel-muted hover:bg-panel-bg hover:text-panel-text'
            }`}
          >
            {vista.etiqueta}
          </Link>
        ))}
      </nav>
      <Insignia color={habilitado ? 'verde' : 'ambar'}>{habilitado ? 'Horus AI activo' : 'Contingencia institucional'}</Insignia>
    </div>
  );
}

async function Contenido({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const pedida = parametro(parametros, 'vista');
  const vista: Vista = VISTAS.find((v) => v.id === pedida)?.id ?? 'resumen';
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: Datos; meta?: MetaListado }>(`compartido/asistente${cadena}`, `${RUTA}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const datos = resultado.datos.data;

  return (
    <div className="space-y-5">
      <PestanasAsistente activa={vista} habilitado={datos.horus_ai?.habilitado === true} />

      {vista === 'resumen' ? (
        datos.metricas ? <MetricasAsistente metricas={datos.metricas} ruta={RUTA} /> : <EstadoError texto="Inténtalo de nuevo en unos minutos." />
      ) : null}

      {vista === 'conversaciones' ? <Conversaciones datos={datos} meta={resultado.datos.meta} parametros={parametros} /> : null}

      {vista === 'probar' ? (
        <Seccion
          id="titulo-probador"
          titulo="Probador"
          descripcion="Responde con Horus AI y, si no está disponible, con la contingencia institucional."
        >
          <ProbadorAsistente />
        </Seccion>
      ) : null}
    </div>
  );
}

function Conversaciones({ datos, meta, parametros }: { datos: Datos; meta?: MetaListado; parametros: Parametros }) {
  const conversaciones = datos.conversaciones;
  if (!conversaciones) return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const valor = (nombre: (typeof PARAMETROS)[number]) => parametro(parametros, nombre);
  const hayFiltros = ['busqueda', 'intent', 'fuente', 'voto', 'canal', 'dias'].some((n) => parametro(parametros, n) !== '');
  const actuales = Object.fromEntries(
    PARAMETROS.filter((n) => n !== 'page' && valor(n) !== '').map((n) => [n, valor(n)]),
  );
  const temas = Object.fromEntries(
    [['sin_dato', etiquetaTema('sin_dato')], ...(datos.temas ?? []).map((t) => [t, etiquetaTema(t)])],
  );
  const rutaLimpia = `${RUTA}?vista=conversaciones`;

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} rutaLimpiar={rutaLimpia} hayFiltros={hayFiltros}>
        <input type="hidden" name="vista" value="conversaciones" />
        {valor('dias') !== '' ? <input type="hidden" name="dias" value={valor('dias')} /> : null}
        <CampoBusqueda tono="academico" nombre="busqueda" valor={valor('busqueda')} marcador="Buscar en las respuestas" />
        <SelectorFiltro tono="academico" nombre="intent" etiqueta="Tema" valor={valor('intent')} opciones={temas} todos="Todos" />
        <SelectorFiltro
          tono="academico"
          nombre="fuente"
          etiqueta="Resultado"
          valor={valor('fuente')}
          opciones={{ advisor: 'Respondida', respaldo: 'Contingencia o privacidad' }}
          todos="Todos"
        />
        {datos.con_votos ? (
          <SelectorFiltro tono="academico" nombre="voto" etiqueta="Voto" valor={valor('voto')} opciones={{ up: 'Útil', down: 'No útil' }} todos="Todos" />
        ) : null}
        {datos.separa_canal ? (
          <SelectorFiltro tono="academico" nombre="canal" etiqueta="Origen" valor={valor('canal')} opciones={{ admin: 'Probador del panel' }} todos="Sitio público" />
        ) : null}
      </FiltrosListado>

      {valor('dias') !== '' ? (
        <p className="text-xs text-panel-muted">Mostrando los últimos {valor('dias')} días.</p>
      ) : null}
      <p className="text-xs text-panel-muted">
        Por privacidad no se guarda lo que escribe el visitante: verás el tema de su consulta y lo que respondió el asistente.
      </p>

      {conversaciones.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna conversación coincide" texto="Prueba con otro filtro o límpialos." />
        ) : (
          <EstadoVacio titulo="Todavía no hay conversaciones" texto="Aparecerán aquí las del sitio público." />
        )
      ) : (
        <>
          <ConversacionesAsistente conversaciones={conversaciones} />
          {meta ? <Paginacion meta={meta} ruta={RUTA} parametros={actuales} suelta /> : null}
        </>
      )}
    </div>
  );
}
