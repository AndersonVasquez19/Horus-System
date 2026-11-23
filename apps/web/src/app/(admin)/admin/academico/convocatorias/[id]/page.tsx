import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesConvocatoria, EditorHorarios } from '@/components/academico/acciones-convocatoria';
import { AccionesListadoMatriculas } from '@/components/academico/acciones-matricula';
import { PanelCalificaciones } from '@/components/academico/acciones-calificaciones';
import { BotonMatricularConPago } from '@/components/academico/matricular-con-pago';
import { ResumenAsistenciaEdicion } from '@/components/academico/paneles-asistencia';
import { PanelSesionesEdicion } from '@/components/academico/paneles-sesiones';
import { BotonesContacto, BotonesGrupoWhatsApp } from '@/components/shared/ui/acciones-contacto';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado, type ColorInsignia } from '@/components/shared/ui/insignia';
import { Pestanas, type ElementoPestana } from '@/components/shared/ui/pestanas';
import { EsqueletoFicha, ListaDatos, Seccion, Tabla } from '@/components/shared/ui/tabla';
import { AccionesFilaCertificado, BotonEmitirLoteCertificados } from '@/components/academico/acciones-certificado';
import { TRANSICIONES_CONVOCATORIA, type ActaCalificaciones, type CertificadoResumen, type ConvocatoriaDetalle, type EstadoCobro, type ResumenAsistenciaFila, type SesionClase } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeBienvenida } from '@/lib/panel/contacto';
import { etiqueta, fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Edición · Gestión académica' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/convocatorias';
const ESTADOS_COBRO: Record<EstadoCobro, { texto: string; color: ColorInsignia }> = {
  pendiente: { texto: 'Pendiente', color: 'ambar' },
  parcial: { texto: 'Parcial', color: 'azul' },
  pagada: { texto: 'Pagada', color: 'verde' },
  anulada: { texto: 'Anulada', color: 'rojo' },
};

export default function ConvocatoriaDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando la edición" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a ediciones
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="La edición no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ConvocatoriaDetalle }>(`academico/convocatorias/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La edición no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const c = resultado.datos.data;
  const transiciones = TRANSICIONES_CONVOCATORIA[c.estado];

  return (
    <>
      <EncabezadoPagina
        titulo={c.nombre ?? c.codigo}
        descripcion={c.curso ? `${c.codigo} · ${c.curso.nombre}` : c.codigo}
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Ediciones', href: LISTADO }, { etiqueta: c.codigo }]}
        acciones={
          <div className="flex flex-wrap items-center gap-2">
            <BotonMatricularConPago convocatoria={c.identificador} disponible={c.estado === 'abierta' || c.estado === 'en_curso'} />
            <AccionesConvocatoria convocatoria={c} transiciones={transiciones} />
          </div>
        }
      />

      <Pestanas
        etiquetaAria={`Secciones de la edición ${c.codigo}`}
        elementos={[
          {
            id: 'resumen',
            etiqueta: 'Resumen y horario',
            contenido: (
              <div className="grid gap-6 xl:grid-cols-3">
                <Seccion id="titulo-datos" titulo="Datos" acciones={<Insignia color={colorEstado(c.estado)}>{etiqueta('estadoConvocatoria', c.estado)}</Insignia>}>
                  <ListaDatos
                    filas={[
                      ['Código', c.codigo],
                      [
                        'Curso',
                        c.curso ? <Link href={`/admin/academico/cursos/${c.curso.id}`} className="font-semibold text-academico-700 hover:underline">{c.curso.nombre}</Link> : '—',
                      ],
                      ['Modalidad', etiqueta('modalidad', c.modalidad)],
                      ['Inicio', fecha(c.fecha_inicio)],
                      ['Fin', fecha(c.fecha_fin)],
                      ['Cupos', `${c.cupos.disponibles} disponibles de ${c.cupos.maximos} (${c.cupos.ocupados} ocupados)`],
                      ['Registrada', fecha(c.creado_en)],
                    ]}
                  />
                  {c.curso?.enlace_grupo_whatsapp ? (
                    <div className="mt-4 border-t border-panel-border pt-3">
                      <p className="mb-2 text-xs font-medium text-panel-muted">Grupo de WhatsApp del curso</p>
                      <BotonesGrupoWhatsApp enlace={c.curso.enlace_grupo_whatsapp} />
                    </div>
                  ) : null}
                </Seccion>
                <div className="xl:col-span-2">
                  <Seccion id="titulo-horario" titulo="Horario" descripcion="Los bloques se guardan juntos; dos bloques del mismo día no pueden solaparse.">
                    <EditorHorarios convocatoria={c} editable={transiciones.length > 0} />
                  </Seccion>
                </div>
              </div>
            ),
          },
          ...(c.matriculados !== undefined
            ? [
                {
                  id: 'matriculados',
                  etiqueta: 'Matriculados',
                  insignia: c.matriculados.length,
                  contenido: (
                    <Seccion
                      id="titulo-matriculados"
                      titulo={`Matriculados (${c.matriculados.length})`}
                      descripcion="Estudiantes inscritos en esta edición, estado de cobro y contacto rápido."
                      acciones={<AccionesListadoMatriculas filtros={`?convocatoria=${encodeURIComponent(c.identificador)}`} mostrarNueva={false} />}
                    >
                      {c.matriculados.length === 0 ? (
                        <p className="text-sm text-panel-muted">Todavía no hay estudiantes matriculados en esta edición.</p>
                      ) : (
                        <Tabla plana titulo={`Matriculados en ${c.codigo}`} columnas={['Estudiante', 'Matrícula', 'Cobro', 'Fecha', { texto: 'Contacto', derecha: true }]}>
                          {c.matriculados.map((m) => {
                            const estCobro = m.obligacion ? ESTADOS_COBRO[m.obligacion.estado] : null;
                            return (
                              <tr key={m.id}>
                                <td className="px-4 py-3">
                                  <Link href={`/admin/academico/matriculas/${m.id}`} className="font-semibold text-academico-700 hover:underline">
                                    #{m.id} · {m.estudiante?.nombre ?? 'Sin estudiante'}
                                  </Link>
                                  {m.estudiante ? (
                                    <div className="text-xs text-panel-muted">
                                      DNI <span className="font-mono">{m.estudiante.dni}</span> ·{' '}
                                      <Link href={`/admin/academico/estudiantes/${m.estudiante.id}`} className="hover:underline">Ver ficha</Link>
                                    </div>
                                  ) : null}
                                </td>
                                <td className="px-4 py-3">
                                  <Insignia color={colorEstado(m.estado)}>{etiqueta('estadoMatricula', m.estado)}</Insignia>
                                </td>
                                <td className="px-4 py-3 text-sm">
                                  {m.obligacion && estCobro ? (
                                    <Link href={`/admin/academico/pagos/${m.obligacion.identificador}`} className="inline-flex flex-col gap-0.5 hover:underline">
                                      <span><Insignia color={estCobro.color}>{estCobro.texto}</Insignia></span>
                                      {Number.parseFloat(m.obligacion.saldo) > 0 ? (
                                        <span className="text-xs text-panel-muted">Saldo: {moneda(m.obligacion.saldo)}</span>
                                      ) : null}
                                    </Link>
                                  ) : (
                                    <span className="text-xs text-panel-muted">Sin obligación</span>
                                  )}
                                </td>
                                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(m.fecha)}</td>
                                <td className="px-4 py-3">
                                  <div className="flex justify-end">
                                    {m.estudiante ? (
                                      <BotonesContacto
                                        compacto
                                        telefono={m.estudiante.telefono}
                                        email={m.estudiante.email}
                                        mensajeWa={mensajeBienvenida({
                                          nombre: m.estudiante.nombre,
                                          curso: c.curso?.nombre ?? c.nombre ?? c.codigo,
                                          edicion: c.codigo,
                                          enlaceGrupo: c.curso?.enlace_grupo_whatsapp,
                                        })}
                                        asuntoCorreo={`Edición ${c.codigo}`}
                                        cuerpoCorreo={mensajeBienvenida({
                                          nombre: m.estudiante.nombre,
                                          curso: c.curso?.nombre ?? c.nombre ?? c.codigo,
                                          edicion: c.codigo,
                                          enlaceGrupo: c.curso?.enlace_grupo_whatsapp,
                                        })}
                                      />
                                    ) : '—'}
                                  </div>
                                </td>
                              </tr>
                            );
                          })}
                        </Tabla>
                      )}
                    </Seccion>
                  ),
                } satisfies ElementoPestana,
              ]
            : []),
          {
            id: 'sesiones',
            etiqueta: 'Sesiones y asistencia',
            contenido: (
              <div className="space-y-6">
                <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando sesiones" />}>
                  <SesionesDeLaEdicion convocatoria={c} editable={transiciones.length > 0} />
                </Suspense>
                <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando asistencia" />}>
                  <AsistenciaDeLaEdicion convocatoria={c} />
                </Suspense>
              </div>
            ),
          },
          {
            id: 'calificaciones',
            etiqueta: 'Calificaciones y acta',
            contenido: (
              <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando calificaciones" />}>
                <Calificaciones identificador={id} codigo={c.codigo} />
              </Suspense>
            ),
          },
          {
            id: 'certificados',
            etiqueta: 'Certificados',
            contenido: (
              <Suspense fallback={<div className="h-40 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando certificados" />}>
                <CertificadosDeLaEdicion convocatoria={c} />
              </Suspense>
            ),
          },
        ]}
      />
    </>
  );
}

/** Sesiones de clase de la edición (hasta 100). */
async function SesionesDeLaEdicion({ convocatoria: c, editable }: { convocatoria: ConvocatoriaDetalle; editable: boolean }) {
  const volverA = `${LISTADO}/${c.identificador}`;
  const sesiones = await consultarPanel<{ data: SesionClase[]; meta: { total: number } }>(`academico/convocatorias/${c.identificador}/sesiones?per_page=100&sort=numero`, volverA);
  return (
    <Seccion id="titulo-sesiones" titulo="Sesiones de clase" descripcion="Calendario de clases de la edición.">
      <PanelSesionesEdicion
        convocatoriaId={c.identificador}
        sesiones={sesiones.estado === 'ok' ? sesiones.datos.data : null}
        total={sesiones.estado === 'ok' ? sesiones.datos.meta.total : 0}
        editable={editable}
      />
    </Seccion>
  );
}

/** Resumen de asistencia: solo se muestra a quien tiene `asistencias.ver`. */
async function AsistenciaDeLaEdicion({ convocatoria: c }: { convocatoria: ConvocatoriaDetalle }) {
  const resultado = await consultarPanel<{ data: { sesiones_realizadas: number; matriculas: ResumenAsistenciaFila[] } }>(
    `academico/convocatorias/${c.identificador}/asistencias/resumen`,
    `${LISTADO}/${c.identificador}`,
  );
  if (resultado.estado !== 'ok') return null;
  return (
    <Seccion id="titulo-asistencia" titulo="Asistencia" descripcion="Porcentaje por matrícula sobre las sesiones realizadas.">
      <ResumenAsistenciaEdicion convocatoriaId={c.identificador} sesionesRealizadas={resultado.datos.data.sesiones_realizadas} filas={resultado.datos.data.matriculas} />
    </Seccion>
  );
}

/** Notas y acta: solo se muestra a quien tiene `calificaciones.ver` (Laravel responde 403 al resto). */
async function Calificaciones({ identificador, codigo }: { identificador: string; codigo: string }) {
  const resultado = await consultarPanel<{ data: ActaCalificaciones }>(`academico/convocatorias/${identificador}/calificaciones`, `${LISTADO}/${identificador}#calificaciones`);
  if (resultado.estado === 'prohibido') return null;
  if (resultado.estado !== 'ok') return <EstadoError texto="No se pudieron cargar las calificaciones. Inténtalo de nuevo en unos minutos." />;

  return (
    <div className="mt-6">
      <Seccion id="calificaciones" titulo="Calificaciones" descripcion={`Evaluaciones, notas y acta de ${codigo}. Las notas son datos personales: no se comparten fuera del panel.`}>
        <PanelCalificaciones acta={resultado.datos.data} />
      </Seccion>
    </div>
  );
}

/** Certificados emitidos de esta edición + emisión en lote (solo si el usuario tiene `certificados.ver`). */
async function CertificadosDeLaEdicion({ convocatoria: c }: { convocatoria: ConvocatoriaDetalle }) {
  const resultado = await consultarPanel<{ data: CertificadoResumen[] }>(
    `academico/certificados?convocatoria=${encodeURIComponent(c.identificador)}&per_page=100`,
    `${LISTADO}/${c.identificador}`,
  );
  if (resultado.estado === 'prohibido') return null;
  if (resultado.estado !== 'ok') {
    return <EstadoError texto="No se pudieron cargar los certificados de esta edición." />;
  }

  const lista = resultado.datos.data;

  return (
    <Seccion
      id="titulo-certificados"
      titulo={`Certificados (${lista.length})`}
      descripcion="Emite diplomas en lote para los matriculados que cumplen asistencia y nota aprobatoria, o descarga cada PDF."
      acciones={<BotonEmitirLoteCertificados convocatoriaId={c.identificador} codigoEdicion={c.codigo} />}
    >
      {lista.length === 0 ? (
        <p className="text-sm text-panel-muted">
          Todavía no se han emitido certificados para esta edición. Usa el botón superior para emitir en lote a los estudiantes elegibles.
        </p>
      ) : (
        <Tabla
          plana
          titulo={`Certificados de ${c.codigo}`}
          columnas={['Código', 'Estudiante', 'Estado', 'Emitido', { texto: 'Acciones', derecha: true }]}
        >
          {lista.map((cert) => (
            <tr key={cert.identificador}>
              <td className="whitespace-nowrap px-4 py-3 font-mono text-sm font-semibold text-panel-ink">
                {cert.codigo}
              </td>
              <td className="px-4 py-3">
                <div className="font-medium text-panel-ink">{cert.estudiante.nombre}</div>
                <div className="font-mono text-xs text-panel-muted">DNI {cert.estudiante.dni}</div>
              </td>
              <td className="whitespace-nowrap px-4 py-3">
                <Insignia color={cert.estado === 'emitido' ? 'verde' : 'rojo'}>
                  {cert.estado_etiqueta}
                </Insignia>
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-xs text-panel-muted">
                {cert.emitido_en}
              </td>
              <td className="px-4 py-3">
                <AccionesFilaCertificado
                  identificador={cert.identificador}
                  codigo={cert.codigo}
                  anulado={cert.estado === 'anulado'}
                />
              </td>
            </tr>
          ))}
        </Tabla>
      )}
    </Seccion>
  );
}
