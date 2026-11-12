import { Suspense } from 'react';
import { FormularioMatricula } from '@/components/academico/formulario-matricula';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import type { EstudianteDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Nueva matrícula · Gestión académica' };

type Parametros = Record<string, string | string[] | undefined>;

export default function NuevaMatriculaPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Nueva matrícula"
        descripcion="El cupo se reserva al guardar, después de comprobar disponibilidad y duplicados."
        migas={[
          { etiqueta: 'Inicio', href: '/admin/academico' },
          { etiqueta: 'Matrículas', href: '/admin/academico/matriculas' },
          { etiqueta: 'Nueva' },
        ]}
      />
      <Suspense fallback={<div className="h-96 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando el formulario" />}>
        <Formulario searchParams={searchParams} />
      </Suspense>
    </>
  );
}

/** Preselecciona el estudiante de `?estudiante_id=` si existe y está activo; si no, el formulario empieza vacío. */
async function Formulario({ searchParams }: { searchParams: Promise<Parametros> }) {
  const { estudiante_id: id } = await searchParams;
  let inicial = null;

  if (typeof id === 'string' && /^\d{1,10}$/.test(id)) {
    const resultado = await consultarPanel<{ data: EstudianteDetalle }>(`academico/estudiantes/${id}`, `/admin/academico/matriculas/nueva?estudiante_id=${id}`);
    if (resultado.estado === 'ok' && resultado.datos.data.estado === 'activo' && !resultado.datos.data.eliminado) {
      const estudiante = resultado.datos.data;
      inicial = { id: estudiante.id, texto: `${estudiante.dni} · ${estudiante.nombres} ${estudiante.apellidos}` };
    }
  }

  // Tras leer searchParams la página ya es dinámica: la hora actual se puede leer en el servidor.
  const hoy = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());

  return <FormularioMatricula estudianteInicial={inicial} fechaInicial={hoy} />;
}
