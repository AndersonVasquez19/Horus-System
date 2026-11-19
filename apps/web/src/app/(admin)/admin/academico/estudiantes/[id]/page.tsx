import Link from 'next/link';
import { ArrowLeft, Plus } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesEstudiante } from '@/components/academico/acciones-estudiante';
import { HistorialAcademico } from '@/components/academico/historial-academico';
import { Alerta } from '@/components/shared/ui/alerta';
import { BotonesContacto, BotonesGrupoWhatsApp } from '@/components/shared/ui/acciones-contacto';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import type { EstudianteDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeEstudiante } from '@/lib/panel/contacto';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Ficha del estudiante · Gestión académica' };

// Ver la nota de cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/estudiantes';

export default function EstudianteDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando el estudiante" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a estudiantes
    </Link>
  );

  if (!/^\d{1,10}$/.test(id)) return <EstadoSinResultados titulo="El estudiante no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: EstudianteDetalle }>(`academico/estudiantes/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El estudiante no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const estudiante = resultado.datos.data;
  const nombre = `${estudiante.nombres} ${estudiante.apellidos}`;

  return (
    <>
      <EncabezadoPagina
        titulo={nombre}
        descripcion={`DNI ${estudiante.dni}`}
        migas={[
          { etiqueta: 'Inicio', href: '/admin/academico' },
          { etiqueta: 'Estudiantes', href: LISTADO },
          { etiqueta: estudiante.dni },
        ]}
        acciones={<AccionesEstudiante estudiante={estudiante} />}
      />

      {estudiante.eliminado ? (
        <Alerta tipo="aviso" className="mb-6">Este estudiante está desactivado: no aparece en los listados ni puede matricularse.</Alerta>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion
          id="titulo-datos"
          titulo="Datos personales"
          acciones={
            <div className="flex flex-wrap gap-1">
              <Insignia color={colorEstado(estudiante.estado)}>{etiqueta('estadoEstudiante', estudiante.estado)}</Insignia>
              {estudiante.eliminado ? <Insignia color="rojo">Desactivado</Insignia> : null}
            </div>
          }
        >
          <ListaDatos
            filas={[
              ['DNI', estudiante.dni],
              ['Correo', estudiante.email ?? '—'],
              ['Teléfono', estudiante.telefono ?? '—'],
              ['Nacimiento', fecha(estudiante.fecha_nacimiento)],
              ['Dirección', estudiante.direccion ?? '—'],
              ['Registrado', fecha(estudiante.creado_en)],
              ['Última actualización', fecha(estudiante.actualizado_en)],
            ]}
          />
          <div className="mt-4 border-t border-panel-border pt-3">
            <BotonesContacto
              telefono={estudiante.telefono}
              email={estudiante.email}
              mensajeWa={mensajeEstudiante({ nombre })}
              asuntoCorreo="Horus Group · Contacto académico"
            />
          </div>
        </Seccion>

        {estudiante.matriculas ? (
          <Seccion
            id="titulo-matriculas"
            titulo="Matrículas"
            descripcion="Historial de matrículas del estudiante, incluidas las anuladas."
            className="xl:col-span-2"
            sinRelleno
            acciones={
              !estudiante.eliminado && estudiante.estado === 'activo' ? (
                <Link
                  href={`/admin/academico/matriculas/nueva?estudiante_id=${estudiante.id}`}
                  className="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-academico-800 hover:bg-academico-50"
                >
                  <Plus className="h-4 w-4" aria-hidden="true" /> Matricular
                </Link>
              ) : null
            }
          >
            {estudiante.matriculas.length === 0 ? (
              <p className="px-5 py-12 text-center text-sm text-panel-muted">Este estudiante todavía no tiene matrículas.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">Matrículas de {nombre}</caption>
                  <thead className="border-y border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
                    <tr>
                      <th scope="col" className="px-5 py-2.5 font-semibold">Curso</th>
                      <th scope="col" className="px-5 py-2.5 font-semibold">Fecha</th>
                      <th scope="col" className="px-5 py-2.5 font-semibold">Estado</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-panel-border">
                    {estudiante.matriculas.map((matricula) => (
                      <tr key={matricula.id}>
                        <td className="px-5 py-3">
                          <Link href={`/admin/academico/matriculas/${matricula.id}`} className="font-medium text-panel-text hover:text-academico-800 hover:underline">
                            {matricula.curso?.nombre ?? 'Curso no disponible'}
                          </Link>
                          {matricula.curso ? <p className="text-xs text-panel-muted">{matricula.curso.codigo}</p> : null}
                        </td>
                        <td className="whitespace-nowrap px-5 py-3 text-panel-muted">{fecha(matricula.fecha)}</td>
                        <td className="px-5 py-3">
                          <div className="space-y-1.5">
                            <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
                            {matricula.curso?.enlace_grupo_whatsapp ? (
                              <BotonesGrupoWhatsApp enlace={matricula.curso.enlace_grupo_whatsapp} compacto />
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Seccion>
        ) : null}

        <Seccion id="titulo-historial" titulo="Historial" descripcion="Operaciones registradas en la auditoría." className="xl:col-span-3">
          <HistorialAcademico entidad="estudiante" eventos={estudiante.historial} />
        </Seccion>
      </div>
    </>
  );
}
