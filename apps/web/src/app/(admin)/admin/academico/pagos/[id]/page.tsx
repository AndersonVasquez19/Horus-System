import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesObligacion, COLOR_COBRO, ETIQUETA_COBRO } from '@/components/academico/paneles-pagos';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion, Tabla } from '@/components/shared/ui/tabla';
import type { ObligacionDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Obligación de pago · Gestión académica' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/pagos';

export default function ObligacionPage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando la obligación" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a pagos
    </Link>
  );
  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="La obligación no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ObligacionDetalle }>(`academico/pagos/obligaciones/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La obligación no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const o = resultado.datos.data;
  const filas: [string, React.ReactNode][] = [
    ['Matrícula', <Link key="m" href={`/admin/academico/matriculas/${o.matricula_id}`} className="text-academico-800 hover:underline">#{o.matricula_id}</Link>],
    ['Edición', o.edicion ?? '—'],
    ['Plan', o.plan === 'completo' ? 'Pago completo' : 'Fraccionado'],
    ['Total', moneda(o.monto_total)],
    ['Pagado', moneda(o.pagado)],
    ['Por confirmar', moneda(o.pendiente_confirmar)],
    ['Saldo', moneda(o.saldo)],
  ];
  if (o.motivo_anulacion) filas.push(['Motivo de anulación', o.motivo_anulacion]);

  return (
    <>
      <EncabezadoPagina
        titulo={o.concepto}
        descripcion={`${o.estudiante.nombre} · DNI ${o.estudiante.dni}`}
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Pagos', href: LISTADO }, { etiqueta: o.estudiante.nombre }]}
      />
      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-resumen" titulo="Resumen" acciones={<Insignia color={COLOR_COBRO[o.estado]}>{ETIQUETA_COBRO[o.estado]}</Insignia>}>
          <ListaDatos filas={filas} />
        </Seccion>
        <div className="space-y-6 xl:col-span-2">
          <Seccion id="titulo-cuotas" titulo="Cuotas">
            <Tabla titulo="Cuotas" columnas={['N.º', 'Vence', 'Monto', 'Pagado', 'Saldo']}>
              {o.cuotas.map((c) => (
                <tr key={c.numero}>
                  <td className="px-4 py-3">{c.numero}</td>
                  <td className="px-4 py-3">{fecha(c.vence)}</td>
                  <td className="px-4 py-3">{moneda(c.monto)}</td>
                  <td className="px-4 py-3">{moneda(c.pagado)}</td>
                  <td className="px-4 py-3">{moneda(c.saldo)}</td>
                </tr>
              ))}
            </Tabla>
          </Seccion>
          <Seccion id="titulo-pagos" titulo="Pagos" descripcion="Libro inmutable: un pago confirmado solo se corrige con un reverso.">
            <AccionesObligacion obligacion={o} />
          </Seccion>
        </div>
      </div>
    </>
  );
}
