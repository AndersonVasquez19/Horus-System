import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { AccionesOportunidad } from '@/components/servicios/acciones-oportunidad';
import type { OportunidadDetalle } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Oportunidad · Servicios tecnológicos' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/servicios/oportunidades';

export default function OportunidadDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando la oportunidad" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-servicios-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver al pipeline
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="La oportunidad no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: OportunidadDetalle }>(`servicios/oportunidades/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La oportunidad no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const o = resultado.datos.data;
  const filas: (readonly [string, React.ReactNode])[] = [
    ['Monto estimado', moneda(o.monto_estimado)],
    ['Probabilidad', `${o.probabilidad} %`],
    ['Cierre estimado', fecha(o.fecha_cierre_estimada)],
    ['Servicio', o.servicio?.nombre ?? '—'],
    ['Responsable', o.responsable?.nombre ?? 'Sin asignar'],
    [
      'Cliente',
      o.cliente ? (
        <Link href={`/admin/servicios/clientes/${o.cliente.identificador}`} className="font-semibold text-servicios-700 hover:underline">{o.cliente.nombre}</Link>
      ) : (
        '—'
      ),
    ],
    ['Registrada', fecha(o.creado_en)],
  ];
  if (o.cerrada_en) filas.push(['Cerrada', fecha(o.cerrada_en)]);

  return (
    <>
      <EncabezadoPagina
        titulo={o.titulo}
        descripcion={o.cliente ? `Cliente: ${o.cliente.nombre}` : 'Oportunidad comercial.'}
        migas={[{ etiqueta: 'Inicio', href: '/admin/servicios' }, { etiqueta: 'Pipeline', href: LISTADO }, { etiqueta: o.titulo }]}
        acciones={<AccionesOportunidad oportunidad={o} />}
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-datos" titulo="Datos" acciones={<Insignia color={colorEstado(o.etapa)}>{etiqueta('etapaOportunidad', o.etapa)}</Insignia>}>
          <ListaDatos filas={filas} />
        </Seccion>

        <div className="space-y-6 xl:col-span-2">
          {o.etapa === 'perdida' ? (
            <Seccion id="titulo-perdida" titulo="Motivo de la pérdida" descripcion="Se puede reabrir desde las acciones.">
              <p className="whitespace-pre-line text-sm text-panel-text">{o.motivo_perdida ?? '—'}</p>
            </Seccion>
          ) : null}
          {o.desde_prospecto ? (
            <Seccion id="titulo-origen" titulo="Origen">
              <p className="text-sm text-panel-muted">Esta oportunidad se abrió al convertir un prospecto en cliente.</p>
            </Seccion>
          ) : null}
          <Seccion id="titulo-historial" titulo="Historial de etapas" descripcion="Cada cambio de etapa queda registrado con su autor.">
            <ol className="space-y-2 text-sm">
              {o.historial.map((fila, n) => (
                <li key={`${fila.fecha}-${n}`} className="flex flex-wrap items-center gap-2">
                  {fila.anterior ? (
                    <>
                      <Insignia color={colorEstado(fila.anterior)}>{etiqueta('etapaOportunidad', fila.anterior)}</Insignia>
                      <span aria-hidden="true">→</span>
                    </>
                  ) : (
                    <span className="text-panel-muted">Alta en</span>
                  )}
                  <Insignia color={colorEstado(fila.etapa)}>{etiqueta('etapaOportunidad', fila.etapa)}</Insignia>
                  <span className="text-panel-muted">
                    {fecha(fila.fecha)}
                    {fila.autor ? ` · ${fila.autor}` : ''}
                  </span>
                </li>
              ))}
            </ol>
          </Seccion>
        </div>
      </div>
    </>
  );
}
