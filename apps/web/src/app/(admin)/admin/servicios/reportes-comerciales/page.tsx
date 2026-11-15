import { FolderKanban, LifeBuoy, Target, TrendingUp } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesReporteComercial } from '@/components/servicios/acciones-reporte-comercial';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido } from '@/components/shared/ui/estados';
import { FiltrosListado, SelectorFiltro, claseControl, parametro } from '@/components/shared/ui/filtros-listado';
import { EsqueletoListado, Seccion } from '@/components/shared/ui/tabla';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, moneda, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Reportes comerciales · Servicios tecnológicos' };

const RUTA = '/admin/servicios/reportes-comerciales';
type Parametros = Record<string, string | string[] | undefined>;
type Conteos = Record<string, number>;

interface Cerradas {
  total: number;
  monto: string;
}

interface Reporte {
  periodo: { desde: string; hasta: string };
  bloques: {
    prospectos?: { creados: number; por_estado: Conteos; convertidos: number; tasa_conversion: number | null };
    oportunidades?: {
      abiertas: { total: number; monto: string; monto_ponderado: string };
      creadas: number;
      creadas_por_etapa: Conteos;
      ganadas: Cerradas;
      perdidas: Cerradas;
      tasa_exito: number | null;
    };
    soporte?: { creados: number; por_estado: Conteos; por_prioridad: Conteos; resueltos: number; tiempo_medio_resolucion_horas: number | null; vencidos: number };
    proyectos?: { por_estado: Conteos; creados: number; completados: number; hitos_vencidos: number };
  };
}

const porcentaje = (valor: number | null) => (valor === null ? 'Sin datos' : `${valor.toLocaleString('es-PE')} %`);

/** Filtros que viajan a Laravel con sus nombres (texto acotado). */
function consultaApi(parametros: Parametros): string {
  const salida = new URLSearchParams();
  for (const nombre of ['desde', 'hasta', 'responsable_id'] as const) {
    const valor = parametro(parametros, nombre).trim();
    if (valor !== '') salida.set(nombre, valor.slice(0, 20));
  }
  const texto = salida.toString();
  return texto === '' ? '' : `?${texto}`;
}

export default function ReportesComercialesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando el reporte" />}>
      <Contenido searchParams={searchParams} />
    </Suspense>
  );
}

function Distribucion({ titulo, datos, etiquetas }: { titulo: string; datos: Conteos; etiquetas: Record<string, string> }) {
  return (
    <div>
      <h3 className="mb-2 text-sm font-semibold text-panel-text">{titulo}</h3>
      <dl className="grid gap-1 text-sm">
        {Object.entries(datos).map(([clave, total]) => (
          <div key={clave} className="flex justify-between gap-4 border-b border-panel-border/60 py-1 last:border-0">
            <dt className="text-panel-muted">{etiquetas[clave] ?? clave}</dt>
            <dd className="font-medium text-panel-text">{numero(total)}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

async function Contenido({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consultaApi(parametros);
  const [resultado, responsables] = await Promise.all([
    consultarPanel<{ data: Reporte }>(`servicios/reportes-comerciales${cadena}`, `${RUTA}${cadena}`),
    consultarPanel<{ data: { id: number; texto: string }[] }>('servicios/reportes-comerciales/opciones/responsables', RUTA),
  ]);
  const opcionesResponsable = responsables.estado === 'ok' ? Object.fromEntries(responsables.datos.data.map((o) => [String(o.id), o.texto])) : {};

  const encabezado = (acciones?: React.ReactNode) => (
    <EncabezadoPagina
      titulo="Reportes comerciales"
      descripcion="Embudo, pipeline, soporte y proyectos en un periodo de días completos (hora de Lima). Las cifras de «hoy» no dependen del periodo."
      migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Reportes comerciales' }]}
      acciones={acciones}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado()}<EstadoProhibido volver="/admin/servicios" /></>;
  if (resultado.estado !== 'ok') return <>{encabezado()}<EstadoError texto="Revisa el periodo o inténtalo de nuevo en unos minutos." /></>;

  const { periodo, bloques } = resultado.datos.data;
  const hayFiltros = cadena !== '';

  return (
    <>
      {encabezado(<AccionesReporteComercial filtros={cadena} />)}

      <div className="space-y-6">
        <FiltrosListado tono="servicios" ruta={RUTA} hayFiltros={hayFiltros}>
          <div>
            <label htmlFor="desde" className="mb-1 block text-xs font-medium text-panel-muted">Desde</label>
            <input id="desde" name="desde" type="date" defaultValue={periodo.desde} className={claseControl('servicios')} />
          </div>
          <div>
            <label htmlFor="hasta" className="mb-1 block text-xs font-medium text-panel-muted">Hasta</label>
            <input id="hasta" name="hasta" type="date" defaultValue={periodo.hasta} className={claseControl('servicios')} />
          </div>
          <SelectorFiltro tono="servicios" nombre="responsable_id" etiqueta="Responsable" valor={parametro(parametros, 'responsable_id')} opciones={opcionesResponsable} todos="Todos" />
        </FiltrosListado>

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {bloques.prospectos ? (
            <TarjetaKpi tono="servicios" etiqueta="Conversión de prospectos" valor={porcentaje(bloques.prospectos.tasa_conversion)} detalle={`${numero(bloques.prospectos.convertidos)} de ${numero(bloques.prospectos.creados)} creados en el periodo`} icono={<TrendingUp className="h-5 w-5" />} />
          ) : null}
          {bloques.oportunidades ? (
            <TarjetaKpi tono="servicios" etiqueta="Valor abierto ponderado" valor={moneda(bloques.oportunidades.abiertas.monto_ponderado)} detalle={`${numero(bloques.oportunidades.abiertas.total)} abiertas por ${moneda(bloques.oportunidades.abiertas.monto)} (hoy)`} icono={<Target className="h-5 w-5" />} />
          ) : null}
          {bloques.soporte ? (
            <TarjetaKpi tono="servicios" etiqueta="Tickets vencidos" valor={numero(bloques.soporte.vencidos)} detalle={`Resolución media: ${bloques.soporte.tiempo_medio_resolucion_horas === null ? 'sin datos' : `${bloques.soporte.tiempo_medio_resolucion_horas.toLocaleString('es-PE')} h`}`} icono={<LifeBuoy className="h-5 w-5" />} />
          ) : null}
          {bloques.proyectos ? (
            <TarjetaKpi tono="servicios" etiqueta="Hitos vencidos" valor={numero(bloques.proyectos.hitos_vencidos)} detalle={`${numero(bloques.proyectos.completados)} proyectos completados en el periodo`} icono={<FolderKanban className="h-5 w-5" />} />
          ) : null}
        </div>

        <div className="grid gap-6 xl:grid-cols-2">
          {bloques.prospectos ? (
            <Seccion id="titulo-prospectos" titulo="Prospectos" descripcion="Creados en el periodo, por su estado actual.">
              <Distribucion titulo={`${numero(bloques.prospectos.creados)} creados`} datos={bloques.prospectos.por_estado} etiquetas={ETIQUETAS.estadoProspecto} />
            </Seccion>
          ) : null}

          {bloques.oportunidades ? (
            <Seccion id="titulo-oportunidades" titulo="Oportunidades" descripcion={`Tasa de éxito del periodo: ${porcentaje(bloques.oportunidades.tasa_exito)} (ganadas sobre decididas).`}>
              <div className="space-y-4">
                <dl className="grid gap-1 text-sm">
                  <div className="flex justify-between gap-4"><dt className="text-panel-muted">Ganadas</dt><dd className="font-medium text-panel-text">{numero(bloques.oportunidades.ganadas.total)} · {moneda(bloques.oportunidades.ganadas.monto)}</dd></div>
                  <div className="flex justify-between gap-4"><dt className="text-panel-muted">Perdidas</dt><dd className="font-medium text-panel-text">{numero(bloques.oportunidades.perdidas.total)} · {moneda(bloques.oportunidades.perdidas.monto)}</dd></div>
                </dl>
                <Distribucion titulo={`${numero(bloques.oportunidades.creadas)} creadas en el periodo, por etapa`} datos={bloques.oportunidades.creadas_por_etapa} etiquetas={ETIQUETAS.etapaOportunidad} />
              </div>
            </Seccion>
          ) : null}

          {bloques.soporte ? (
            <Seccion id="titulo-soporte" titulo="Soporte técnico" descripcion={`${numero(bloques.soporte.creados)} tickets creados y ${numero(bloques.soporte.resueltos)} resueltos en el periodo.`}>
              <div className="grid gap-6 sm:grid-cols-2">
                <Distribucion titulo="Por estado" datos={bloques.soporte.por_estado} etiquetas={ETIQUETAS.estadoTicket} />
                <Distribucion titulo="Por prioridad" datos={bloques.soporte.por_prioridad} etiquetas={ETIQUETAS.prioridadTicket} />
              </div>
            </Seccion>
          ) : null}

          {bloques.proyectos ? (
            <Seccion id="titulo-proyectos" titulo="Proyectos" descripcion={`${numero(bloques.proyectos.creados)} creados en el periodo. El reparto por estado es el de hoy.`}>
              <Distribucion titulo="Por estado" datos={bloques.proyectos.por_estado} etiquetas={ETIQUETAS.estadoProyecto} />
            </Seccion>
          ) : null}
        </div>
      </div>
    </>
  );
}
