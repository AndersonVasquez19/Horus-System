import { ChevronDown } from 'lucide-react';
import Link from 'next/link';
import { Suspense } from 'react';
import { ProbadorAsistente } from '@/components/compartido/probador-asistente';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { FiltrosListado, CampoBusqueda, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Seccion, Tabla } from '@/components/shared/ui/tabla';
import { TextoPlegable } from '@/components/shared/ui/texto-plegable';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Asistente virtual' };

type Parametros = Record<string, string | string[] | undefined>;
const PARAMETROS = ['busqueda', 'intent', 'fuente', 'per_page', 'page'] as const;

interface Datos {
  intenciones: { nombre: string; respuesta: string; prioridad: number; activo: boolean }[];
  registros: { id: number; fecha_local: string; mensaje: string; respuesta: string; intencion: string | null; fuente: string | null }[];
  filtro?: string | null;
  horus_ai?: { habilitado: boolean; url_configurada: boolean };
}

const ETIQUETAS_INTENCION: Record<string, string> = {
  saludo: 'Saludo y bienvenida',
  cursos_disponibles: 'Cursos disponibles',
  horarios_modalidad: 'Horarios y modalidad',
  precios_matricula: 'Precios y matrícula',
  contacto_asesor: 'Contacto con asesor',
  reclamaciones: 'Libro de reclamaciones',
  training_info: 'Información de cursos',
  followup_attribute: 'Seguimiento de atributo (horario/modalidad)',
  vocational_guidance: 'Orientación vocacional',
  course_comparison: 'Comparación de cursos',
  enrollment_info: 'Proceso de matrícula',
  certification_info: 'Certificación y constancias',
  payment_methods: 'Medios de pago',
  company_info: 'Sobre Horus Group',
  tech_services: 'Servicios tecnológicos',
  out_of_scope: 'Consulta fuera de alcance',
};

function etiquetaIntencion(nombre: string): string {
  return ETIQUETAS_INTENCION[nombre] ?? nombre.replaceAll('_', ' ');
}

const FUENTES: Record<string, string> = { advisor: 'Horus AI', respaldo: 'Contingencia institucional' };

export default function AsistentePage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Asistente virtual"
        descripcion="Prueba el asistente y revisa sus últimas conversaciones."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Asistente virtual' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando el asistente" />}>
        <Contenido searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Contenido({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const ruta = '/admin/academico/asistente';
  const resultado = await consultarPanel<{ data: Datos; meta?: MetaListado }>(`compartido/asistente${cadena}`, `${ruta}${cadena}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { intenciones, registros, horus_ai: horusAi } = resultado.datos.data;
  const meta = resultado.datos.meta;
  const busqueda = parametro(parametros, 'busqueda');
  const intent = parametro(parametros, 'intent');
  const fuente = parametro(parametros, 'fuente');
  const porPagina = parametro(parametros, 'per_page');
  const hayFiltros = busqueda !== '' || intent !== '' || fuente !== '';
  const actuales = Object.fromEntries(
    PARAMETROS.filter((n) => n !== 'page' && parametro(parametros, n) !== '').map((n) => [n, parametro(parametros, n)]),
  );

  const opcionesIntent = Object.fromEntries(
    intenciones.map((i) => [i.nombre, etiquetaIntencion(i.nombre)]),
  );

  return (
    <div className="space-y-8">
      <Seccion
        id="titulo-probador"
        titulo="Probador"
        descripcion="Responde con Horus AI y, si no está disponible, con la contingencia institucional."
        acciones={
          <Insignia color={horusAi?.habilitado ? 'verde' : 'ambar'}>
            {horusAi?.habilitado ? 'Horus AI activo' : 'Contingencia institucional'}
          </Insignia>
        }
      >
        <ProbadorAsistente />
      </Seccion>

      <section aria-labelledby="titulo-registros" className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="titulo-registros" className="font-heading text-base font-semibold text-panel-text">
            Últimas conversaciones
          </h2>
          {intent ? (
            <p className="text-xs text-panel-muted">
              Filtrado por intención: <strong className="text-panel-text">{etiquetaIntencion(intent)}</strong>
            </p>
          ) : null}
        </div>
        <FiltrosListado tono="academico" ruta={ruta} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={busqueda} marcador="Buscar en pregunta o respuesta" />
          <SelectorFiltro
            tono="academico"
            nombre="fuente"
            etiqueta="Motor"
            valor={fuente}
            opciones={{ advisor: 'Horus AI', respaldo: 'Contingencia institucional' }}
            todos="Todos"
          />
          {intenciones.length > 0 ? (
            <SelectorFiltro
              tono="academico"
              nombre="intent"
              etiqueta="Intención"
              valor={intent}
              opciones={opcionesIntent}
              todos="Todas"
            />
          ) : null}
          <SelectorPorPagina tono="academico" valor={porPagina || '50'} />
        </FiltrosListado>
        {registros.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados titulo="Ninguna conversación coincide con la intención" texto="Prueba con otra intención o quita el filtro." />
          ) : (
            <EstadoVacio titulo="Todavía no hay conversaciones" texto="Aparecerán aquí las del sitio público y las de este probador." />
          )
        ) : (
          <Tabla
            titulo={`Últimas conversaciones (${meta?.total ?? registros.length})`}
            columnas={['Fecha (Lima)', 'Mensaje', 'Respuesta', 'Intención', 'Origen']}
            pie={meta ? <Paginacion meta={meta} ruta={ruta} parametros={actuales} /> : undefined}
          >
            {registros.map((registro) => (
              <tr key={registro.id} className="align-top">
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{registro.fecha_local}</td>
                <td className="max-w-xs px-4 py-3 font-medium text-panel-text"><TextoPlegable texto={registro.mensaje} /></td>
                <td className="max-w-md px-4 py-3 text-panel-muted"><TextoPlegable texto={registro.respuesta} /></td>
                <td className="px-4 py-3">
                  {registro.intencion ? <Insignia color="azul">{etiquetaIntencion(registro.intencion)}</Insignia> : <span className="text-panel-muted">—</span>}
                </td>
                <td className="px-4 py-3 text-panel-muted">{(registro.fuente && FUENTES[registro.fuente]) ?? 'No registrado'}</td>
              </tr>
            ))}
          </Tabla>
        )}
      </section>

      {intenciones.length > 0 ? (
        <details className="group overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 outline-offset-[-2px] focus-visible:outline-2 focus-visible:outline-academico-700 [&::-webkit-details-marker]:hidden">
            <h2 id="titulo-intenciones" className="font-heading text-base font-semibold text-panel-text">
              Intenciones configuradas ({intenciones.length})
            </h2>
            <ChevronDown className="h-4 w-4 shrink-0 text-panel-muted transition-transform group-open:rotate-180" aria-hidden="true" />
          </summary>
          <Tabla plana titulo={`Intenciones (${intenciones.length})`} columnas={['Intención', 'Prioridad', 'Estado']}>
            {intenciones.map((intencion) => {
              const destino = `/admin/academico/asistente?intent=${encodeURIComponent(intencion.nombre)}${porPagina ? `&per_page=${encodeURIComponent(porPagina)}` : ''}`;
              return (
                <tr key={intencion.nombre}>
                  <td className="px-4 py-3">
                    <Link href={destino} className="font-medium text-panel-text hover:underline">
                      {etiquetaIntencion(intencion.nombre)}
                    </Link>
                    <p className="mt-0.5 max-w-xl truncate text-xs text-panel-muted">{intencion.respuesta}</p>
                  </td>
                  <td className="px-4 py-3 tabular-nums">{intencion.prioridad}</td>
                  <td className="px-4 py-3">{intencion.activo ? <Insignia color="verde">Activa</Insignia> : <Insignia color="gris">Inactiva</Insignia>}</td>
                </tr>
              );
            })}
          </Tabla>
        </details>
      ) : null}
    </div>
  );
}
