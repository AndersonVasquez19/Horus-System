import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { AccionesProspecto } from '@/components/servicios/acciones-prospecto';
import type { ProspectoDetalle } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Prospecto · Servicios tecnológicos' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/servicios/prospectos';

export default function ProspectoDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando el prospecto" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-servicios-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a prospectos
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="El prospecto no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ProspectoDetalle }>(`servicios/prospectos/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El prospecto no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const prospecto = resultado.datos.data;

  return (
    <>
      <EncabezadoPagina
        titulo={prospecto.nombre_contacto}
        descripcion={prospecto.empresa ?? 'Prospecto sin empresa indicada.'}
        migas={[
          { etiqueta: 'Inicio', href: '/admin/servicios' },
          { etiqueta: 'Prospectos', href: LISTADO },
          { etiqueta: prospecto.nombre_contacto },
        ]}
        acciones={<AccionesProspecto prospecto={prospecto} />}
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-datos" titulo="Datos" acciones={<Insignia color={colorEstado(prospecto.estado)}>{etiqueta('estadoProspecto', prospecto.estado)}</Insignia>}>
          <ListaDatos
            filas={[
              ['Correo', prospecto.email ?? '—'],
              ['Teléfono', prospecto.telefono ?? '—'],
              ['Origen', etiqueta('origenProspecto', prospecto.origen)],
              ['Servicio de interés', prospecto.servicio_interes?.nombre ?? '—'],
              ['Responsable', prospecto.responsable?.nombre ?? 'Sin asignar'],
              ['Próximo seguimiento', fecha(prospecto.proximo_seguimiento)],
              ['Registrado', fecha(prospecto.creado_en)],
            ]}
          />
        </Seccion>

        <div className="space-y-6 xl:col-span-2">
          {prospecto.estado === 'descartado' ? (
            <Seccion id="titulo-descarte" titulo="Motivo del descarte" descripcion="Se puede reabrir desde las acciones.">
              <p className="whitespace-pre-line text-sm text-panel-text">{prospecto.motivo_descarte ?? '—'}</p>
            </Seccion>
          ) : null}
          {prospecto.estado === 'convertido' ? (
            <Seccion id="titulo-cliente" titulo="Cliente" descripcion="Un prospecto convertido ya no se edita: los datos viven en el cliente.">
              {prospecto.cliente ? (
                <Link href={`/admin/servicios/clientes/${prospecto.cliente.identificador}`} className="text-sm font-semibold text-servicios-700 hover:underline">
                  {prospecto.cliente.nombre}
                </Link>
              ) : (
                <p className="text-sm text-panel-muted">Convertido en cliente.</p>
              )}
            </Seccion>
          ) : null}
          {prospecto.desde_cotizacion ? (
            <Seccion id="titulo-origen" titulo="Origen">
              <p className="text-sm text-panel-muted">Este prospecto se creó a partir de una solicitud de cotización.</p>
            </Seccion>
          ) : null}
        </div>
      </div>
    </>
  );
}
