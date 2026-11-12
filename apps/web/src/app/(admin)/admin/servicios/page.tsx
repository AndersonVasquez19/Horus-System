import Link from 'next/link';
import { BellRing, ClipboardList, Globe, Inbox, ServerCog } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';
import type { ResumenServicios } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Inicio · Servicios tecnológicos' };

export default function ServiciosInicioPage() {
  return (
    <>
      <EncabezadoPagina titulo="Panel de servicios tecnológicos" descripcion="Resumen del catálogo y de la actividad comercial." />
      <Suspense fallback={<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">{[0, 1, 2].map((n) => <div key={n} className="h-32 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />)}</div>}>
        <Resumen />
      </Suspense>
    </>
  );
}

async function Resumen() {
  const resultado = await consultarPanel<{ data: ResumenServicios }>('servicios/resumen', '/admin/servicios');
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { indicadores: i, bloques: b } = resultado.datos.data;
  const tarjetas = [
    i.servicios_activos !== undefined && {
      etiqueta: 'Servicios activos', valor: numero(i.servicios_activos), icono: <ServerCog className="h-5 w-5" />,
      detalle: `${numero(i.servicios_total)} servicios en el catálogo interno`,
    },
    i.servicios_publicados !== undefined && {
      etiqueta: 'Fichas publicadas', valor: numero(i.servicios_publicados), icono: <Globe className="h-5 w-5" />,
      detalle: 'Visibles en la web pública.',
    },
    i.cotizaciones_recibidas !== undefined && {
      etiqueta: 'Cotizaciones recibidas', valor: numero(i.cotizaciones_recibidas), icono: <Inbox className="h-5 w-5" />,
      detalle: 'Pendientes de revisión.',
    },
    i.cotizaciones_en_revision !== undefined && {
      etiqueta: 'En revisión', valor: numero(i.cotizaciones_en_revision), icono: <ClipboardList className="h-5 w-5" />,
    },
    i.seguimientos_pendientes !== undefined && {
      etiqueta: 'Seguimientos para hoy o vencidos', valor: numero(i.seguimientos_pendientes), icono: <BellRing className="h-5 w-5" />,
    },
  ].filter((tarjeta) => tarjeta !== false);

  if (tarjetas.length === 0) {
    return <EstadoVacio titulo="Aún no tienes módulos asignados" texto="Tu cuenta puede entrar al panel, pero no tiene permisos sobre ningún módulo." />;
  }

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {tarjetas.map((tarjeta) => (
          <TarjetaKpi key={tarjeta.etiqueta} tono="servicios" {...tarjeta} />
        ))}
      </div>

      {b.ultimas_cotizaciones ? (
        <section aria-labelledby="titulo-cotizaciones" className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
          <div className="flex items-center justify-between px-5 pt-5">
            <h2 id="titulo-cotizaciones" className="font-heading text-base font-semibold text-panel-text">Últimas cotizaciones</h2>
            <Link href="/admin/servicios/cotizaciones" className="text-sm font-semibold text-servicios-700 hover:underline">Ver bandeja</Link>
          </div>
          {b.ultimas_cotizaciones.length === 0 ? (
            <p className="px-5 py-10 text-center text-sm text-panel-muted">Todavía no hay solicitudes de cotización.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="mt-3 w-full text-left text-sm">
                <thead className="border-y border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                  <tr>
                    <th scope="col" className="px-5 py-2.5 font-semibold">Código</th>
                    <th scope="col" className="px-5 py-2.5 font-semibold">Servicio</th>
                    <th scope="col" className="px-5 py-2.5 font-semibold">Estado</th>
                    <th scope="col" className="px-5 py-2.5 font-semibold">Recibida</th>
                    <th scope="col" className="px-5 py-2.5 font-semibold">Próximo seguimiento</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-panel-border">
                  {b.ultimas_cotizaciones.map((cotizacion) => (
                    <tr key={cotizacion.identificador}>
                      <td className="px-5 py-3 font-medium text-panel-text">{cotizacion.codigo}</td>
                      <td className="px-5 py-3 text-panel-muted">{cotizacion.servicio ?? 'Sin servicio'}</td>
                      <td className="px-5 py-3"><Insignia color={colorEstado(cotizacion.estado)}>{etiqueta('estadoCotizacion', cotizacion.estado)}</Insignia></td>
                      <td className="whitespace-nowrap px-5 py-3 text-panel-muted">{fecha(cotizacion.recibida_en)}</td>
                      <td className="whitespace-nowrap px-5 py-3 text-panel-muted">{fecha(cotizacion.proximo_seguimiento)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : null}
    </div>
  );
}
