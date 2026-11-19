import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesDocente } from '@/components/academico/acciones-docente';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import type { DocenteDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Docente · Gestión académica' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/docentes';

export default function DocenteDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando el docente" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a docentes
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="El docente no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: DocenteDetalle }>(`academico/docentes/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El docente no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const d = resultado.datos.data;

  return (
    <>
      <EncabezadoPagina
        titulo={`${d.nombres} ${d.apellidos}`}
        descripcion={`DNI ${d.dni}`}
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Docentes', href: LISTADO }, { etiqueta: `${d.nombres} ${d.apellidos}` }]}
        acciones={<AccionesDocente docente={d} />}
      />
      <div className="max-w-3xl">
        <Seccion
          id="titulo-datos"
          titulo="Datos"
          acciones={
            <div className="flex gap-1">
              <Insignia color={colorEstado(d.estado)}>{etiqueta('estadoActivo', d.estado)}</Insignia>
              {d.eliminado ? <Insignia color="rojo">Eliminado</Insignia> : null}
            </div>
          }
        >
          <ListaDatos
            filas={[
              ['DNI', <span key="dni" className="font-mono">{d.dni}</span>],
              ['Nombres', d.nombres],
              ['Apellidos', d.apellidos],
              ['Correo', d.email ?? '—'],
              ['Teléfono', d.telefono ?? '—'],
              ['Especialidad', d.especialidad ?? '—'],
              ['Ediciones vigentes', String(d.ediciones_vigentes)],
              ['Registrado', fecha(d.creado_en)],
            ]}
          />
        </Seccion>
      </div>
    </>
  );
}
