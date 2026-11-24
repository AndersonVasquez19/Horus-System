import Link from 'next/link';
import { ArrowLeft, Plus } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesEstudiante } from '@/components/academico/acciones-estudiante';
import { AccionesFilaCertificado } from '@/components/academico/acciones-certificado';
import { HistorialAcademico } from '@/components/academico/historial-academico';
import { FormularioNotaSeguimiento } from '@/components/academico/nota-seguimiento';
import { Alerta } from '@/components/shared/ui/alerta';
import { BotonesContacto, BotonesGrupoWhatsApp } from '@/components/shared/ui/acciones-contacto';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion, Tabla } from '@/components/shared/ui/tabla';
import type { CertificadoResumen, EstudianteDetalle, EventoLineaTiempo, LineaTiempoEstudiante } from '@/features/academico/tipos';
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
              <Tabla plana titulo={`Matrículas de ${nombre}`} columnas={['Curso', 'Fecha', 'Estado']}>
                    {estudiante.matriculas.map((matricula) => (
                      <tr key={matricula.id}>
                        <td className="px-4 py-3">
                          <Link href={`/admin/academico/matriculas/${matricula.id}`} className="font-medium text-panel-text hover:text-academico-800 hover:underline">
                            {matricula.curso?.nombre ?? 'Curso no disponible'}
                          </Link>
                          {matricula.curso ? <p className="text-xs text-panel-muted">{matricula.curso.codigo}</p> : null}
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(matricula.fecha)}</td>
                        <td className="px-4 py-3">
                          <div className="space-y-1.5">
                            <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
                            {matricula.curso?.enlace_grupo_whatsapp ? (
                              <BotonesGrupoWhatsApp enlace={matricula.curso.enlace_grupo_whatsapp} compacto />
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    ))}

</Tabla>
            )}
          </Seccion>
        ) : null}

        <Suspense fallback={null}>
          <CertificadosDelEstudiante dni={estudiante.dni} volver={`${LISTADO}/${estudiante.id}`} />
        </Suspense>

        <Suspense fallback={null}>
          <Seguimiento360 estudianteId={estudiante.id} volver={`${LISTADO}/${estudiante.id}`} />
        </Suspense>

        <Seccion id="titulo-historial" titulo="Historial" descripcion="Operaciones registradas en la auditoría." className="xl:col-span-3">
          <HistorialAcademico entidad="estudiante" eventos={estudiante.historial} />
        </Seccion>
      </div>
    </>
  );
}

const ETIQUETA_EVENTO: Record<EventoLineaTiempo['tipo'], { texto: string; color: 'azul' | 'verde' | 'ambar' | 'rojo' | 'gris' }> = {
  matricula: { texto: 'Matrícula', color: 'azul' },
  nota: { texto: 'Notas', color: 'verde' },
  pago: { texto: 'Pago', color: 'verde' },
  cobranza: { texto: 'Cobranza', color: 'ambar' },
  asistencia: { texto: 'Asistencia', color: 'rojo' },
  certificado: { texto: 'Certificado', color: 'azul' },
  nota_interna: { texto: 'Nota interna', color: 'gris' },
};

/** Línea de tiempo del estudiante y notas internas (cada tipo de evento depende del permiso de su módulo). */
async function Seguimiento360({ estudianteId, volver }: { estudianteId: number; volver: string }) {
  const res = await consultarPanel<{ data: LineaTiempoEstudiante }>(`academico/estudiantes/${estudianteId}/360`, volver);
  if (res.estado !== 'ok') return null;
  const { eventos, truncado } = res.datos.data;

  return (
    <Seccion id="titulo-360" titulo="Seguimiento 360°" descripcion="Matrículas, pagos, asistencia, notas, certificados, contactos de cobranza y notas internas, del más reciente al más antiguo." className="xl:col-span-3">
      <div className="space-y-4">
        <FormularioNotaSeguimiento estudianteId={estudianteId} />
        {eventos.length === 0 ? (
          <p className="text-sm text-panel-muted">Todavía no hay actividad registrada.</p>
        ) : (
          <ol className="space-y-2">
            {eventos.map((e, i) => (
              <li key={`${e.tipo}-${e.fecha}-${i}`} className="flex flex-wrap items-start gap-3 rounded-lg border border-panel-border p-3 text-sm">
                <span className="w-24 shrink-0 text-xs text-panel-muted">{e.fecha ? fecha(e.fecha) : '—'}</span>
                <Insignia color={ETIQUETA_EVENTO[e.tipo].color}>{ETIQUETA_EVENTO[e.tipo].texto}</Insignia>
                <div className="min-w-0 flex-1">
                  {e.enlace ? (
                    <Link href={e.enlace} className="font-medium text-academico-700 hover:underline">{e.titulo}</Link>
                  ) : (
                    <span className="font-medium text-panel-ink">{e.titulo}</span>
                  )}
                  {e.detalle ? <div className="text-panel-text">{e.detalle}</div> : null}
                  {e.autor ? <div className="text-xs text-panel-muted">por {e.autor}</div> : null}
                </div>
              </li>
            ))}
          </ol>
        )}
        {truncado ? <p className="text-xs text-panel-muted">Se muestran los 200 eventos más recientes.</p> : null}
      </div>
    </Seccion>
  );
}

/** Certificados emitidos del estudiante (solo visible si el rol tiene `certificados.ver`). */
async function CertificadosDelEstudiante({ dni, volver }: { dni: string; volver: string }) {
  const res = await consultarPanel<{ data: CertificadoResumen[] }>(
    `academico/certificados?buscar=${encodeURIComponent(dni)}&per_page=20`,
    volver,
  );
  if (res.estado !== 'ok' || res.datos.data.length === 0) return null;

  return (
    <Seccion
      id="titulo-certificados"
      titulo={`Certificados emitidos (${res.datos.data.length})`}
      descripcion="Diplomas emitidos a nombre de este estudiante."
      className="xl:col-span-3"
      sinRelleno
    >
      <Tabla plana titulo="Certificados del estudiante" columnas={['Código', 'Curso', 'Estado', 'Emitido', { texto: 'Acciones', derecha: true }]}>
        {res.datos.data.map((cert) => (
          <tr key={cert.identificador}>
            <td className="whitespace-nowrap px-4 py-3 font-mono text-sm font-semibold text-panel-text">{cert.codigo}</td>
            <td className="px-4 py-3 text-sm text-panel-text">{cert.curso.nombre}</td>
            <td className="whitespace-nowrap px-4 py-3">
              <Insignia color={cert.estado === 'emitido' ? 'verde' : 'rojo'}>{cert.estado_etiqueta}</Insignia>
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-xs text-panel-muted">{fecha(cert.emitido_en)}</td>
            <td className="px-4 py-3">
              <AccionesFilaCertificado identificador={cert.identificador} codigo={cert.codigo} anulado={cert.estado === 'anulado'} />
            </td>
          </tr>
        ))}
      </Tabla>
    </Seccion>
  );
}
