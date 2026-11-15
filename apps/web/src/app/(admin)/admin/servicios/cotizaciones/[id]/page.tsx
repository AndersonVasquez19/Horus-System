import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { AccionesCotizacion } from '@/components/servicios/acciones-cotizacion';
import type { CotizacionDetalle } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Solicitud de cotización · Servicios tecnológicos' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/servicios/cotizaciones';
const FECHA_HORA = new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Lima' });

export default function CotizacionDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando la solicitud" />}>
      <Expediente params={params} />
    </Suspense>
  );
}

async function Expediente({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-servicios-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a cotizaciones
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="La solicitud no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: CotizacionDetalle }>(`servicios/cotizaciones/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La solicitud no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const cotizacion = resultado.datos.data;

  return (
    <>
      <EncabezadoPagina
        titulo={cotizacion.codigo}
        descripcion="Solicitud de cotización. Es la necesidad del cliente: no incluye precios ni compromisos."
        migas={[
          { etiqueta: 'Inicio', href: '/admin/servicios' },
          { etiqueta: 'Cotizaciones', href: LISTADO },
          { etiqueta: cotizacion.codigo },
        ]}
        acciones={<AccionesCotizacion cotizacion={cotizacion} />}
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion
          id="titulo-solicitante"
          titulo="Solicitante"
          acciones={
            <div className="flex flex-wrap gap-1">
              <Insignia color={colorEstado(cotizacion.estado)}>{etiqueta('estadoCotizacion', cotizacion.estado)}</Insignia>
              <Insignia color={cotizacion.origen === 'publico' ? 'azul' : 'gris'}>{etiqueta('origenCotizacion', cotizacion.origen)}</Insignia>
            </div>
          }
        >
          <ListaDatos
            filas={[
              ['Nombre', cotizacion.contacto.nombre],
              ['Correo', cotizacion.contacto.email ?? '—'],
              ['Teléfono', cotizacion.contacto.telefono ?? '—'],
              ['Servicio', cotizacion.servicio ?? 'Sin servicio asociado'],
              ['Recibida', fecha(cotizacion.recibida_en)],
              ['Responsable', cotizacion.responsable?.nombre ?? 'Sin asignar'],
              [
                'Cliente',
                cotizacion.cliente ? (
                  <Link href={`/admin/servicios/clientes/${cotizacion.cliente.identificador}`} className="font-semibold text-servicios-700 hover:underline">
                    {cotizacion.cliente.nombre}
                  </Link>
                ) : (
                  'Sin vincular'
                ),
              ],
              ['Último seguimiento', fecha(cotizacion.ultimo_seguimiento)],
              ['Próximo seguimiento', fecha(cotizacion.proximo_seguimiento)],
            ]}
          />
        </Seccion>

        <div className="space-y-6 xl:col-span-2">
          <Seccion id="titulo-necesidad" titulo="Necesidad del cliente">
            <p className="whitespace-pre-line text-sm text-panel-text">{cotizacion.necesidad}</p>
          </Seccion>

          <Seccion id="titulo-historial" titulo="Historial y seguimientos" descripcion="Cada cambio queda registrado.">
            {cotizacion.historial.length === 0 ? (
              <p className="py-8 text-center text-sm text-panel-muted">Sin movimientos registrados.</p>
            ) : (
              <ol className="relative space-y-5 border-l border-panel-border pl-5">
                {cotizacion.historial.map((evento) => (
                  <li key={evento.revision} className="relative">
                    <span className="absolute -left-[1.6rem] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-panel-surface bg-servicios-600" aria-hidden="true" />
                    <p className="text-sm font-semibold text-panel-text">{etiqueta('accionCotizacion', evento.evento)}</p>
                    <p className="text-xs text-panel-muted">
                      {evento.fecha ? <time dateTime={evento.fecha}>{FECHA_HORA.format(new Date(evento.fecha))}</time> : null}
                      {evento.actor ? ` · ${evento.actor}` : ''}
                    </p>
                    {evento.estado_anterior && evento.estado_nuevo ? (
                      <p className="mt-1 text-xs text-panel-muted">
                        {ETIQUETAS.estadoCotizacion[evento.estado_anterior as keyof typeof ETIQUETAS.estadoCotizacion] ?? evento.estado_anterior} →{' '}
                        {ETIQUETAS.estadoCotizacion[evento.estado_nuevo as keyof typeof ETIQUETAS.estadoCotizacion] ?? evento.estado_nuevo}
                      </p>
                    ) : null}
                    {evento.nota ? <p className="mt-1.5 whitespace-pre-line rounded-lg bg-panel-bg px-3 py-2 text-sm text-panel-text">{evento.nota}</p> : null}
                  </li>
                ))}
              </ol>
            )}
          </Seccion>
        </div>
      </div>
    </>
  );
}
