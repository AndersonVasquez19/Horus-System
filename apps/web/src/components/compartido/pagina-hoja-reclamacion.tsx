import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { EsqueletoFicha } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { HojaReclamacionDatos } from '@/features/notificaciones/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { HojaReclamacion } from './hoja-reclamacion';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Hoja imprimible de una reclamación. La monta cada panel bajo `/admin/<panel>/reclamaciones/[id]/hoja`. */
export function PaginaHojaReclamacion({ tono, params }: { tono: TonoUi; params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Preparando la hoja" />}>
      <Hoja tono={tono} params={params} />
    </Suspense>
  );
}

async function Hoja({ tono, params }: { tono: TonoUi; params: Promise<{ id: string }> }) {
  const { id } = await params;
  const listado = `/admin/${tono}/reclamaciones`;
  const volver = (
    <Link href={listado} className="inline-flex items-center gap-1 text-sm font-semibold text-panel-text hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver al libro de reclamaciones
    </Link>
  );

  if (!UUID.test(id)) return <EstadoSinResultados titulo="La reclamación no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: HojaReclamacionDatos }>(`compartido/reclamaciones/${id}/hoja`, `${listado}/${id}/hoja`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La reclamación no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const hoja = resultado.datos.data;

  return (
    <>
      <div className="no-imprimir">
        <EncabezadoPagina
          titulo={`Hoja de reclamación ${hoja.numero}`}
          descripcion="Vista de impresión en A4."
          migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Reclamaciones', href: listado }, { etiqueta: hoja.numero, href: `${listado}/${hoja.id}` }, { etiqueta: 'Hoja' }]}
        />
      </div>
      <HojaReclamacion hoja={hoja} tono={tono} />
    </>
  );
}
