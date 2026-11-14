import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, claseControl, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { RegistroAuditoria } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { ExportarAuditoria } from './exportar-auditoria';

type Parametros = Record<string, string | string[] | undefined>;

const PARAMETROS = ['accion', 'modelo', 'usuario', 'desde', 'hasta', 'page'] as const;

/** Auditoría del sistema (solo lectura y exportación). La monta cada panel bajo `/admin/<panel>/auditoria`. */
export function PaginaAuditoria({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Auditoría"
        descripcion="Quién hizo qué y cuándo. Los datos sensibles se muestran enmascarados."
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Auditoría' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando la auditoría" />}>
        <Registros tono={tono} searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Registros({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  const ruta = `/admin/${tono}/auditoria`;
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: RegistroAuditoria[]; meta: MetaListado }>(`compartido/auditoria${cadena}`, `${ruta}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: registros, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const filtrosExportacion = consulta(parametros, ['accion', 'modelo', 'usuario', 'desde', 'hasta']);
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <FiltrosListado tono={tono} ruta={ruta} hayFiltros={hayFiltros}>
        <CampoBusqueda tono={tono} nombre="accion" valor={parametro(parametros, 'accion')} marcador="p. ej. matricula_creada" etiqueta="Acción" />
        <CampoBusqueda tono={tono} nombre="modelo" valor={parametro(parametros, 'modelo')} marcador="p. ej. Curso" etiqueta="Modelo" />
        <CampoBusqueda tono={tono} nombre="usuario" valor={parametro(parametros, 'usuario')} marcador="Nombre o correo" etiqueta="Usuario" />
        {(['desde', 'hasta'] as const).map((nombre) => (
          <div key={nombre}>
            <label htmlFor={nombre} className="mb-1 block text-xs font-medium text-panel-muted">{nombre === 'desde' ? 'Desde' : 'Hasta'}</label>
            <input id={nombre} name={nombre} type="date" defaultValue={parametro(parametros, nombre)} className={claseControl(tono)} />
          </div>
        ))}
      </FiltrosListado>

      <div className="flex justify-end">
        <ExportarAuditoria tono={tono} filtros={filtrosExportacion} />
      </div>

      {registros.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún registro coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay registros de auditoría" texto="Las acciones del personal aparecerán aquí." />
        )
      ) : (
        <Tabla
          titulo={`Registros (${meta.total})`}
          columnas={['Fecha (Lima)', 'Usuario', 'Acción', 'Modelo', 'IP', 'Cambios']}
          pie={<Paginacion meta={meta} ruta={ruta} parametros={actuales} />}
        >
          {registros.map((registro) => (
            <tr key={registro.id} className="align-top">
              <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{registro.fecha_local}</td>
              <td className="px-4 py-3">
                <p className="font-medium text-panel-text">{registro.usuario?.nombre ?? 'Sistema'}</p>
                <p className="text-xs text-panel-muted">{registro.usuario?.email ?? 'N/A'}</p>
              </td>
              <td className="px-4 py-3"><Insignia color="azul">{registro.accion}</Insignia></td>
              <td className="px-4 py-3 text-panel-muted">
                {registro.modelo ?? 'N/A'}
                {registro.modelo_id !== null ? <span className="ml-1 font-mono text-xs">#{registro.modelo_id}</span> : null}
              </td>
              <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-panel-muted">{registro.ip ?? 'N/A'}</td>
              <td className="px-4 py-3">
                {registro.cambios ? (
                  <pre className="max-h-36 max-w-xs overflow-auto whitespace-pre-wrap rounded-lg border border-panel-border bg-panel-bg p-2 text-xs text-panel-text">
                    {JSON.stringify(registro.cambios, null, 2)}
                  </pre>
                ) : (
                  <span className="text-panel-muted">—</span>
                )}
              </td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
