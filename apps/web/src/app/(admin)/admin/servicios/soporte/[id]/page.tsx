import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { AccionesTicket, NotasTicket } from '@/components/servicios/acciones-ticket';
import { TRANSICIONES_TICKET, type TicketDetalle } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Ticket · Servicios tecnológicos' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/servicios/soporte';

export default function TicketDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando el ticket" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-servicios-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a soporte
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="El ticket no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: TicketDetalle }>(`servicios/tickets/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El ticket no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const t = resultado.datos.data;
  const filas: (readonly [string, React.ReactNode])[] = [
    ['Código', t.codigo],
    ['Prioridad', <Insignia key="prioridad" color={colorEstado(t.prioridad)}>{etiqueta('prioridadTicket', t.prioridad)}</Insignia>],
    ['Canal', etiqueta('canalTicket', t.canal)],
    [
      'Cliente',
      t.cliente ? <Link href={`/admin/servicios/clientes/${t.cliente.identificador}`} className="font-semibold text-servicios-700 hover:underline">{t.cliente.nombre}</Link> : '—',
    ],
    [
      'Proyecto',
      t.proyecto ? <Link href={`/admin/servicios/proyectos/${t.proyecto.identificador}`} className="font-semibold text-servicios-700 hover:underline">{t.proyecto.codigo}</Link> : '—',
    ],
    ['Atiende', t.asignado?.nombre ?? 'Sin asignar'],
    ['Vence', <span key="vence">{fecha(t.vence_en)} {t.vencido ? <Insignia color="rojo">Vencido</Insignia> : null}</span>],
    ['Registrado', fecha(t.creado_en)],
  ];
  if (t.resuelto_en) filas.push(['Resuelto', fecha(t.resuelto_en)]);
  if (t.cerrado_en) filas.push(['Cerrado', fecha(t.cerrado_en)]);

  return (
    <>
      <EncabezadoPagina
        titulo={t.asunto}
        descripcion={t.cliente ? `${t.codigo} · ${t.cliente.nombre}` : t.codigo}
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Soporte técnico', href: LISTADO }, { etiqueta: t.codigo }]}
        acciones={<AccionesTicket ticket={t} transiciones={TRANSICIONES_TICKET[t.estado]} />}
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-datos" titulo="Datos" acciones={<Insignia color={colorEstado(t.estado)}>{etiqueta('estadoTicket', t.estado)}</Insignia>}>
          <ListaDatos filas={filas} />
        </Seccion>

        <div className="space-y-6 xl:col-span-2">
          {t.descripcion ? (
            <Seccion id="titulo-descripcion" titulo="Descripción">
              <p className="whitespace-pre-line text-sm text-panel-text">{t.descripcion}</p>
            </Seccion>
          ) : null}
          <Seccion id="titulo-notas" titulo="Notas" descripcion="Solo se añaden: una nota guardada no se edita.">
            <NotasTicket ticket={t} />
          </Seccion>
        </div>
      </div>
    </>
  );
}
