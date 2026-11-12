import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { FormularioMatricula } from '@/components/academico/formulario-matricula';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import type { MatriculaDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Editar matrícula · Gestión académica' };

// Ver la nota de cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/matriculas';

export default function EditarMatriculaPage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando la matrícula" />}>
      <Edicion params={params} />
    </Suspense>
  );
}

async function Edicion({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a matrículas
    </Link>
  );

  if (!/^\d{1,10}$/.test(id)) return <EstadoSinResultados titulo="La matrícula no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: MatriculaDetalle }>(`academico/matriculas/${id}`, `${LISTADO}/${id}/editar`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La matrícula no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const matricula = resultado.datos.data;
  if (matricula.eliminado) {
    return <EstadoSinResultados titulo="La matrícula está eliminada" texto="Restáurala antes de editarla." accion={volver} />;
  }

  return (
    <>
      <EncabezadoPagina
        titulo={`Editar matrícula #${matricula.id}`}
        descripcion="Cambiar el curso traslada el cupo; pasar a «Anulada» lo libera. Laravel vuelve a comprobarlo todo al guardar."
        migas={[
          { etiqueta: 'Inicio', href: '/admin/academico' },
          { etiqueta: 'Matrículas', href: LISTADO },
          { etiqueta: `#${matricula.id}`, href: `${LISTADO}/${matricula.id}` },
          { etiqueta: 'Editar' },
        ]}
      />
      <FormularioMatricula matricula={matricula} />
    </>
  );
}
