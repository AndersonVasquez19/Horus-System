import Link from 'next/link';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, SelectorPorPagina, claseControl, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { OpcionAuditoria, RegistroAuditoria } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { hoyLima, sumarDias } from '@/lib/panel/formato';
import { ExportarAuditoria } from './exportar-auditoria';

type Parametros = Record<string, string | string[] | undefined>;

const PARAMETROS = ['accion', 'modelo', 'usuario', 'desde', 'hasta', 'sort', 'page', 'per_page'] as const;

function enlaceEntidadAuditoria(modelo: string | null, modeloId: number | null, tono: TonoUi): string | null {
  if (!modelo || modeloId === null) return null;
  const base = modelo.split('\\').pop() ?? modelo;
  switch (base) {
    case 'Estudiante':
      return `/admin/academico/estudiantes/${modeloId}`;
    case 'Curso':
      return `/admin/academico/cursos/${modeloId}`;
    case 'Matricula':
      return `/admin/academico/matriculas/${modeloId}`;
    case 'Convocatoria':
      return '/admin/academico/convocatorias';
    case 'ObligacionPago':
      return '/admin/academico/pagos';
    case 'Certificado':
      return '/admin/academico/certificados';
    case 'User':
      return `/admin/${tono}/usuarios`;
    case 'AprobacionInterna':
      return `/admin/${tono}/aprobaciones`;
    default:
      return null;
  }
}

/** Auditoría del sistema (solo lectura y exportación). La monta cada panel bajo `/admin/<panel>/auditoria`. */
export function PaginaAuditoria({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Historial de actividad"
        descripcion="Quién hizo qué y cuándo. Los datos sensibles se muestran ocultos."
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Historial de actividad' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando el historial" />}>
        <Registros tono={tono} searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Registros({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  const ruta = `/admin/${tono}/auditoria`;
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: RegistroAuditoria[]; meta: MetaListado & { acciones?: OpcionAuditoria[]; secciones?: OpcionAuditoria[] } }>(`compartido/auditoria${cadena}`, `${ruta}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: registros, meta } = resultado.datos;
  const sort = parametro(parametros, 'sort');
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const actualesOrden = Object.fromEntries(
    Object.entries(actuales).filter(([clave]) => clave !== 'sort'),
  );
  const filtrosExportacion = consulta(parametros, ['accion', 'modelo', 'usuario', 'desde', 'hasta']);
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const hoy = hoyLima();
  const haceSieteDias = sumarDias(hoy, -6);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 text-xs" role="group" aria-label="Filtros rápidos">
        <span className="font-medium text-panel-muted">Atajos:</span>
        <Link href={`${ruta}?desde=${hoy}&hasta=${hoy}`} className="rounded-lg border border-panel-border bg-panel-surface px-2.5 py-1 font-medium text-panel-text hover:bg-panel-bg">
          Hoy
        </Link>
        <Link href={`${ruta}?desde=${haceSieteDias}&hasta=${hoy}`} className="rounded-lg border border-panel-border bg-panel-surface px-2.5 py-1 font-medium text-panel-text hover:bg-panel-bg">
          Últimos 7 días
        </Link>
        <Link href={`${ruta}?accion=login_failed`} className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 font-medium text-red-900 hover:bg-red-100">
          Accesos fallidos
        </Link>
      </div>

      <FiltrosListado tono={tono} ruta={ruta} hayFiltros={hayFiltros}>
        <SelectorFiltro tono={tono} nombre="accion" etiqueta="Qué se hizo" valor={parametro(parametros, 'accion')} opciones={Object.fromEntries((meta.acciones ?? []).map((o) => [o.clave, o.etiqueta]))} todos="Todo" />
        <SelectorFiltro tono={tono} nombre="modelo" etiqueta="Sobre qué" valor={parametro(parametros, 'modelo')} opciones={Object.fromEntries((meta.secciones ?? []).map((o) => [o.clave, o.etiqueta]))} todos="Todo" />
        <CampoBusqueda tono={tono} nombre="usuario" valor={parametro(parametros, 'usuario')} marcador="Nombre o correo" etiqueta="Usuario" />
        {(['desde', 'hasta'] as const).map((nombre) => (
          <div key={nombre}>
            <label htmlFor={nombre} className="mb-1 block text-xs font-medium text-panel-muted">{nombre === 'desde' ? 'Desde' : 'Hasta'}</label>
            <input id={nombre} name={nombre} type="date" defaultValue={parametro(parametros, nombre)} className={claseControl(tono)} />
          </div>
        ))}
        <SelectorPorPagina tono={tono} valor={parametro(parametros, 'per_page')} />
      </FiltrosListado>

      <div className="flex justify-end">
        <ExportarAuditoria tono={tono} filtros={filtrosExportacion} />
      </div>

      {registros.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún registro coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay actividad registrada" texto="Las acciones del personal aparecerán aquí." />
        )
      ) : (
        <Tabla
          titulo={`Registros (${meta.total})`}
          columnas={[{ texto: 'Fecha y hora', orden: 'fecha' }, 'Persona', 'IP', 'Qué hizo', 'Sobre qué', 'Detalle']}
          ordenActual={sort || '-fecha'}
          rutaOrden={ruta}
          parametrosOrden={actualesOrden}
          pie={<Paginacion meta={meta} ruta={ruta} parametros={actuales} />}
        >
          {registros.map((registro) => {
            const enlace = enlaceEntidadAuditoria(registro.modelo, registro.modelo_id, tono);
            return (
              <tr key={registro.id} className="align-top">
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{registro.fecha_local}</td>
                <td className="px-4 py-3">
                  <p className="font-medium text-panel-text">{registro.usuario?.nombre ?? 'Sistema'}</p>
                  {registro.usuario ? <p className="text-xs text-panel-muted">{registro.usuario.email}</p> : null}
                </td>
                <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-panel-muted">{registro.ip ?? '—'}</td>
                <td className="px-4 py-3"><Insignia color="azul">{registro.accion_etiqueta}</Insignia></td>
                <td className="px-4 py-3 text-panel-muted">
                  {registro.modelo_etiqueta ?? '—'}
                  {registro.modelo_id !== null ? (
                    enlace ? (
                      <Link href={enlace} className="ml-1 text-xs font-medium text-academico-700 hover:underline">
                        n.º {registro.modelo_id}
                      </Link>
                    ) : (
                      <span className="ml-1 text-xs">n.º {registro.modelo_id}</span>
                    )
                  ) : null}
                </td>
                <td className="px-4 py-3">
                  {registro.resumen.length > 0 ? (
                    <ul className="max-w-sm space-y-0.5 text-sm text-panel-text">
                      {registro.resumen.map((frase) => (
                        <li key={frase}>{frase}</li>
                      ))}
                    </ul>
                  ) : (
                    <span className="text-panel-muted">—</span>
                  )}
                </td>
              </tr>
            );
          })}
        </Tabla>
      )}
    </div>
  );
}
