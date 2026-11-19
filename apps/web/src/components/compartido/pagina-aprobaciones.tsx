import Link from 'next/link';
import { Suspense } from 'react';
import { BotonNuevaAprobacion, AccionesAprobacion } from '@/components/compartido/acciones-aprobacion';
import { BotonExportarListado } from '@/components/shared/ui/boton-exportar-listado';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, type ColorInsignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import { ETIQUETAS_AUTORIDAD, ETIQUETAS_TIPO_APROBACION } from '@/features/notificaciones/aprobaciones';
import type { AprobacionFila } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';

type Parametros = Record<string, string | string[] | undefined>;

const PARAMETROS = ['busqueda', 'estado', 'tipo', 'alcance', 'page'] as const;

function enlaceDeReferencia(referencia: string, tono: TonoUi): string | null {
  const limpia = referencia.trim();
  const matricula = /^MAT-?#?(\d+)$/i.exec(limpia);
  if (matricula) return `/admin/${tono}/matriculas/${matricula[1]}`;
  const estudiante = /^EST-?#?(\d+)$/i.exec(limpia);
  if (estudiante) return `/admin/${tono}/estudiantes/${estudiante[1]}`;
  if (/^\d{8}$/.test(limpia)) return `/admin/${tono}/estudiantes?busqueda=${encodeURIComponent(limpia)}`;
  if (/^REC-[\w-]+$/i.test(limpia)) return `/admin/${tono}/reclamaciones?busqueda=${encodeURIComponent(limpia)}`;
  if (/^(?:CNV|ED)-[\w-]+$/i.test(limpia)) return `/admin/${tono}/convocatorias?busqueda=${encodeURIComponent(limpia)}`;
  return null;
}

const ESTADOS: Record<string, { texto: string; color: ColorInsignia }> = {
  pendiente: { texto: 'Pendiente', color: 'ambar' },
  aprobado: { texto: 'Aprobada', color: 'verde' },
  rechazado: { texto: 'Rechazada', color: 'rojo' },
};

/** Aprobaciones internas. La monta cada panel bajo `/admin/<panel>/aprobaciones`. */
export function PaginaAprobaciones({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Aprobaciones internas"
        descripcion="Solicitudes de compra y operativas. Cada tipo lo resuelve su autoridad; nadie decide sobre su propia solicitud."
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Aprobaciones' }]}
        acciones={<BotonNuevaAprobacion tono={tono} />}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando las aprobaciones" />}>
        <Lista tono={tono} searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Lista({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  const ruta = `/admin/${tono}/aprobaciones`;
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: AprobacionFila[]; meta: MetaListado }>(`compartido/aprobaciones${cadena}`, `${ruta}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: filas, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const alcanceActual = parametro(parametros, 'alcance') || 'todas';

  return (
    <div className="space-y-4">
      <nav aria-label="Alcance de solicitudes" className="flex flex-wrap gap-2 border-b border-panel-border">
        {([
          ['para_mi', 'Para mí', `${ruta}?alcance=para_mi`],
          ['mias', 'Mías', `${ruta}?alcance=mias`],
          ['todas', 'Todas', ruta],
        ] as const).map(([clave, texto, href]) => (
          <Link
            key={clave}
            href={href}
            aria-current={alcanceActual === clave ? 'page' : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${alcanceActual === clave ? 'border-panel-text text-panel-text' : 'border-transparent text-panel-muted hover:text-panel-text'}`}
          >
            {texto}
          </Link>
        ))}
      </nav>

      <FiltrosListado tono={tono} ruta={ruta} hayFiltros={hayFiltros}>
        {parametro(parametros, 'alcance') ? <input type="hidden" name="alcance" value={parametro(parametros, 'alcance')} /> : null}
        <CampoBusqueda tono={tono} nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="Título, descripción o referencia" />
        <SelectorFiltro tono={tono} nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={Object.fromEntries(Object.entries(ESTADOS).map(([clave, { texto }]) => [clave, texto]))} />
        <SelectorFiltro tono={tono} nombre="tipo" etiqueta="Tipo" valor={parametro(parametros, 'tipo')} opciones={ETIQUETAS_TIPO_APROBACION} />
      </FiltrosListado>

      <div className="flex justify-end">
        <BotonExportarListado
          ruta="compartido/aprobaciones/exportar"
          filtros={consulta(parametros, ['busqueda', 'estado', 'tipo', 'alcance'])}
          permiso="aprobaciones.ver"
        />
      </div>

      {filas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna solicitud coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay solicitudes" texto="Las solicitudes de aprobación aparecerán aquí." />
        )
      ) : (
        <ul className="space-y-3" aria-label={`Solicitudes (${meta.total})`}>
          {filas.map((fila) => {
            const estado = ESTADOS[fila.estado] ?? { texto: fila.estado, color: 'gris' as const };
            const autoridad = fila.rol_autorizado === null ? 'Sin autoridad asignada' : (ETIQUETAS_AUTORIDAD[fila.rol_autorizado] ?? fila.rol_autorizado);
            return (
              <li key={fila.id} className="rounded-xl border border-panel-border bg-panel-surface p-4 shadow-sm sm:p-5">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                      <Insignia color={fila.tipo === 'compra' ? 'azul' : fila.tipo === 'operativo' ? 'verde' : 'gris'}>{ETIQUETAS_TIPO_APROBACION[fila.tipo] ?? fila.tipo}</Insignia>
                      {fila.referencia ? (
                        (() => {
                          const destinoRef = enlaceDeReferencia(fila.referencia, tono);
                          return destinoRef ? (
                            <Link href={destinoRef} className="rounded-md border border-panel-border bg-panel-bg px-2 py-0.5 font-mono text-academico-700 hover:underline">
                              {fila.referencia}
                            </Link>
                          ) : (
                            <span className="rounded-md border border-panel-border bg-panel-bg px-2 py-0.5 font-mono text-panel-muted">{fila.referencia}</span>
                          );
                        })()
                      ) : null}
                      <span className="text-panel-muted">Creada el {fila.creada_local}</span>
                    </div>
                    <h2 className="mt-2 break-words font-heading text-lg font-semibold text-panel-text">{fila.titulo}</h2>
                  </div>
                  <Insignia color={estado.color}>{estado.texto}</Insignia>
                </div>

                <p className="mt-3 break-words text-sm text-panel-muted">{fila.descripcion || 'Sin descripción adicional.'}</p>
                {fila.comentario ? (
                  <p className={`mt-2 break-words text-sm ${fila.estado === 'rechazado' ? 'rounded-lg border border-red-200 bg-red-50 p-3 text-red-800' : 'text-panel-muted'}`}>
                    <span className="font-semibold">{fila.estado === 'rechazado' ? 'Motivo de rechazo: ' : 'Comentario: '}</span>
                    {fila.comentario}
                  </p>
                ) : null}

                <dl className="mt-4 grid gap-3 rounded-lg border border-panel-border bg-panel-bg p-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-panel-muted">Solicitante</dt><dd className="mt-0.5 font-medium text-panel-text">{fila.solicitante?.nombre ?? 'Sin registrar'}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-panel-muted">Autoridad competente</dt><dd className="mt-0.5 font-medium text-panel-text">{autoridad}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-panel-muted">Decidido por</dt><dd className="mt-0.5 font-medium text-panel-text">{fila.aprobador?.nombre ?? (fila.estado === 'pendiente' ? 'Pendiente de decisión' : 'Sin registrar')}</dd></div>
                  <div><dt className="text-xs font-semibold uppercase tracking-wide text-panel-muted">Última actualización</dt><dd className="mt-0.5 text-xs text-panel-muted">{fila.actualizada_local}</dd></div>
                </dl>

                <AccionesAprobacion fila={fila} tono={tono} autoridad={autoridad} />
              </li>
            );
          })}
        </ul>
      )}
      {filas.length > 0 ? <Paginacion meta={meta} ruta={ruta} parametros={actuales} /> : null}
    </div>
  );
}
