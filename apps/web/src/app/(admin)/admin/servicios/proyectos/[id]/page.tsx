import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { AccionesProyecto, HitosProyecto } from '@/components/servicios/acciones-proyecto';
import { TRANSICIONES_PROYECTO, type ProyectoDetalle } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Proyecto · Servicios tecnológicos' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/servicios/proyectos';

export default function ProyectoDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando el proyecto" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-servicios-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a proyectos
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="El proyecto no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ProyectoDetalle }>(`servicios/proyectos/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El proyecto no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const p = resultado.datos.data;
  const transiciones = TRANSICIONES_PROYECTO[p.estado];
  const filas: (readonly [string, React.ReactNode])[] = [
    ['Código', p.codigo],
    [
      'Cliente',
      p.cliente ? <Link href={`/admin/servicios/clientes/${p.cliente.identificador}`} className="font-semibold text-servicios-700 hover:underline">{p.cliente.nombre}</Link> : '—',
    ],
    ['Responsable', p.responsable?.nombre ?? 'Sin asignar'],
    ['Presupuesto', moneda(p.presupuesto)],
    ['Inicio', fecha(p.fecha_inicio)],
    ['Fin estimado', fecha(p.fecha_fin_estimada)],
    ['Fin real', fecha(p.fecha_fin_real)],
    ['Avance', p.hitos.avance === null ? 'Sin hitos' : `${p.hitos.avance} % (${p.hitos.completados} de ${p.hitos.total} hitos)`],
    ['Registrado', fecha(p.creado_en)],
  ];

  return (
    <>
      <EncabezadoPagina
        titulo={p.nombre}
        descripcion={p.cliente ? `${p.codigo} · ${p.cliente.nombre}` : p.codigo}
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Proyectos', href: LISTADO }, { etiqueta: p.codigo }]}
        acciones={<AccionesProyecto proyecto={p} transiciones={transiciones} />}
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-datos" titulo="Datos" acciones={<Insignia color={colorEstado(p.estado)}>{etiqueta('estadoProyecto', p.estado)}</Insignia>}>
          <ListaDatos filas={filas} />
        </Seccion>

        <div className="space-y-6 xl:col-span-2">
          {p.descripcion ? (
            <Seccion id="titulo-descripcion" titulo="Descripción">
              <p className="whitespace-pre-line text-sm text-panel-text">{p.descripcion}</p>
            </Seccion>
          ) : null}
          {p.desde_oportunidad ? (
            <Seccion id="titulo-origen" titulo="Origen">
              {p.oportunidad ? (
                <p className="text-sm text-panel-muted">
                  Nació de la oportunidad{' '}
                  <Link href={`/admin/servicios/oportunidades/${p.oportunidad.identificador}`} className="font-semibold text-servicios-700 hover:underline">{p.oportunidad.titulo}</Link>.
                </p>
              ) : (
                <p className="text-sm text-panel-muted">Nació de una oportunidad ganada.</p>
              )}
            </Seccion>
          ) : null}
          <Seccion id="titulo-hitos" titulo="Hitos" descripcion="El avance se calcula con los hitos completados.">
            <HitosProyecto proyecto={p} editable={transiciones.length > 0} />
          </Seccion>
        </div>
      </div>
    </>
  );
}
