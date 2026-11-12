import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { AccionesServicio } from '@/components/servicios/acciones-servicio';
import { FichaServicioPanel } from '@/components/servicios/ficha-servicio';
import type { ServicioDetalle } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Servicio · Servicios tecnológicos' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/servicios/catalogo';

export default function ServicioDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando el servicio" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-servicios-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a servicios
    </Link>
  );

  if (!/^\d{1,10}$/.test(id)) return <EstadoSinResultados titulo="El servicio no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ServicioDetalle }>(`servicios/servicios/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El servicio no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const servicio = resultado.datos.data;

  return (
    <>
      <EncabezadoPagina
        titulo={servicio.nombre}
        descripcion={servicio.codigo}
        migas={[
          { etiqueta: 'Inicio', href: '/admin/servicios' },
          { etiqueta: 'Servicios', href: LISTADO },
          { etiqueta: servicio.codigo },
        ]}
        acciones={<AccionesServicio servicio={servicio} />}
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion
          id="titulo-datos"
          titulo="Datos del servicio"
          acciones={<Insignia color={colorEstado(servicio.estado)}>{etiqueta('estadoServicio', servicio.estado)}</Insignia>}
        >
          <ListaDatos
            filas={[
              ['Código', servicio.codigo],
              ['Categoría', servicio.categoria ?? '—'],
              ['Estado', etiqueta('estadoServicio', servicio.estado)],
              ['Ficha pública', etiqueta('estadoEditorial', servicio.estado_editorial)],
              ['Última actualización', fecha(servicio.actualizado_en)],
            ]}
          />
        </Seccion>

        <div className="xl:col-span-2">
          <FichaServicioPanel servicio={servicio} />
        </div>
      </div>
    </>
  );
}
