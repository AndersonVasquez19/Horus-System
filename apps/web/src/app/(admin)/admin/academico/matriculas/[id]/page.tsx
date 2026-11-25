import Link from 'next/link';
import { ArrowLeft, ArrowUpRight } from 'lucide-react';
import { Suspense } from 'react';
import {
  AccionesMatricula,
  ETIQUETAS_OPERATIVAS,
  SelectorEtiquetaMatricula,
  TarjetaContactoMatricula,
} from '@/components/academico/acciones-matricula';
import { BotonEmitirCertificadoMatricula } from '@/components/academico/acciones-certificado';
import {
  BotonCrearObligacion,
  BotonReciboPdf,
  COLOR_COBRO,
  ETIQUETA_COBRO,
  EnlaceObligacion,
  ResumenFinancieroMatricula,
} from '@/components/academico/paneles-pagos';
import { Alerta } from '@/components/shared/ui/alerta';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import type { EventoHistorial, MatriculaDetalle, ObligacionDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeBienvenida } from '@/lib/panel/contacto';
import { etiqueta, fecha, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Matrícula · Gestión académica' };

// Ver la nota de cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/matriculas';
const FECHA_HORA = new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Lima' });

type Parametros = Record<string, string | string[] | undefined>;

export default function MatriculaDetallePage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<Parametros>;
}) {
  return (
    <Suspense fallback={<EsqueletoDetalleMatricula />}>
      <Ficha params={params} searchParams={searchParams} />
    </Suspense>
  );
}

async function Ficha({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<Parametros> }) {
  const { id } = await params;
  const query = await searchParams;
  const abrirEditarInicial = parametro(query, 'editar') === '1';

  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-academico-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a matrículas
    </Link>
  );

  if (!/^\d{1,10}$/.test(id)) return <EstadoSinResultados titulo="La matrícula no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: MatriculaDetalle }>(`academico/matriculas/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La matrícula no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const matricula = resultado.datos.data;
  const mostrarPagos = (!matricula.eliminado && matricula.estado !== 'anulada') || Boolean(matricula.obligacion_identificador);
  const textoBienvenida = mensajeBienvenida({
    nombre: matricula.estudiante?.nombre ?? 'estudiante',
    curso: matricula.curso?.nombre ?? 'nuestro curso',
    edicion: matricula.convocatoria?.codigo,
    enlaceGrupo: matricula.curso?.enlace_grupo_whatsapp,
  });
  const etiquetaOp = matricula.etiqueta ? (ETIQUETAS_OPERATIVAS[matricula.etiqueta] ?? matricula.etiqueta) : null;

  return (
    <>
      <EncabezadoPagina
        titulo={`Matrícula #${matricula.id}`}
        descripcion={[matricula.estudiante?.nombre, matricula.curso?.nombre].filter(Boolean).join(' · ')}
        migas={[
          { etiqueta: 'Inicio', href: '/admin/academico' },
          { etiqueta: 'Matrículas', href: LISTADO },
          { etiqueta: `#${matricula.id}` },
        ]}
        acciones={
          <AccionesMatricula
            matricula={matricula}
            abrirEditarInicial={abrirEditarInicial}
            accionCertificado={
              !matricula.eliminado && matricula.estado !== 'anulada' ? (
                <BotonEmitirCertificadoMatricula matriculaId={matricula.id} />
              ) : null
            }
          />
        }
      />

      {/* Banda compacta de estado e indicadores clave */}
      <div className="mb-5 flex flex-wrap items-center gap-2">
        <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
        {matricula.estado_cobro && matricula.estado_cobro !== 'sin_obligacion' ? (
          <Insignia color={COLOR_COBRO[matricula.estado_cobro] ?? 'gris'}>
            Cobro: {ETIQUETA_COBRO[matricula.estado_cobro] ?? matricula.estado_cobro}
          </Insignia>
        ) : (
          <Insignia color="gris">Sin obligación</Insignia>
        )}
        {etiquetaOp ? <Insignia color="azul">{etiquetaOp}</Insignia> : null}
        {matricula.eliminado ? <Insignia color="rojo">Eliminada</Insignia> : null}
      </div>

      {/* Alertas contextuales de estado excepcional */}
      {matricula.eliminado || matricula.motivo_anulacion ? (
        <div className="mb-6 space-y-3">
          {matricula.eliminado ? (
            <Alerta tipo="aviso">Esta matrícula está eliminada y no ocupa cupo.</Alerta>
          ) : null}
          {matricula.motivo_anulacion ? (
            <Alerta tipo="aviso" titulo={matricula.eliminado ? 'Motivo de eliminación' : 'Motivo de anulación'}>
              {matricula.motivo_anulacion}
            </Alerta>
          ) : null}
        </div>
      ) : null}

      {/* Layout balanceado de 2 columnas (7 / 5) sin huecos huérfanos */}
      <div className="grid items-start gap-6 xl:grid-cols-12">
        <div className="space-y-6 xl:col-span-7">
          {mostrarPagos ? (
            <Suspense fallback={<EsqueletoPagosMatricula />}>
              <PagosDeLaMatricula matriculaId={matricula.id} permiteCrear={!matricula.eliminado && matricula.estado !== 'anulada'} />
            </Suspense>
          ) : null}

          <Seccion id="titulo-matricula" titulo="Detalle académico y notas">
            <ListaDatos
              filas={[
                [
                  'Curso',
                  matricula.curso ? (
                    <Link href={`/admin/academico/cursos/${matricula.curso.id}`} className="inline-flex items-center gap-1 text-academico-800 hover:underline">
                      <span>{matricula.curso.nombre}</span>
                      <span className="font-mono text-xs text-panel-muted">({matricula.curso.codigo})</span>
                    </Link>
                  ) : (
                    '—'
                  ),
                ],
                [
                  'Edición',
                  matricula.convocatoria ? (
                    <Link
                      href={`/admin/academico/convocatorias/${matricula.convocatoria.identificador}`}
                      className="font-mono text-academico-800 hover:underline"
                    >
                      {matricula.convocatoria.codigo}
                    </Link>
                  ) : (
                    <span className="font-normal text-panel-muted">Sin edición (el cupo es el del curso)</span>
                  ),
                ],
                ['Cupos libres del curso', <span key="cupos" className="tabular-nums">{numero(matricula.curso_cupos_disponibles)}</span>],
                ['Fecha de matrícula', <span key="fecha" className="tabular-nums">{fecha(matricula.fecha)}</span>],
                [
                  'Etiqueta operativa',
                  <SelectorEtiquetaMatricula key="etiqueta" matriculaId={matricula.id} inicial={matricula.etiqueta ?? ''} />,
                ],
                [
                  'Observaciones',
                  matricula.observaciones ? (
                    <span className="whitespace-pre-line font-normal text-panel-text">{matricula.observaciones}</span>
                  ) : (
                    <span className="font-normal text-panel-muted">Sin observaciones registradas.</span>
                  ),
                ],
              ]}
            />
          </Seccion>
        </div>

        <div className="space-y-6 xl:col-span-5">
          <Seccion
            id="titulo-estudiante"
            titulo="Estudiante y contacto"
            acciones={
              matricula.estudiante ? (
                <Link
                  href={`/admin/academico/estudiantes/${matricula.estudiante.id}`}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-academico-700 hover:underline"
                >
                  Ver perfil
                  <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
                </Link>
              ) : null
            }
          >
            <TarjetaContactoMatricula
              estudiante={matricula.estudiante}
              mensajeWa={textoBienvenida}
              asuntoCorreo={`Bienvenida · ${matricula.curso?.nombre ?? 'Horus Group'}`}
              cuerpoCorreo={textoBienvenida}
              enlaceGrupo={matricula.curso?.enlace_grupo_whatsapp}
            />
          </Seccion>

          <Seccion
            id="titulo-historial"
            titulo="Historial de actividad"
            descripcion={`Registrada ${fecha(matricula.creado_en)} · Actualizada ${fecha(matricula.actualizado_en)}`}
          >
            <Historial eventos={matricula.historial ?? []} />
          </Seccion>
        </div>
      </div>
    </>
  );
}

/** Obligación de pago y comprobantes de la matrícula (solo se muestra a quien tiene `pagos.ver`). */
async function PagosDeLaMatricula({ matriculaId, permiteCrear }: { matriculaId: number; permiteCrear: boolean }) {
  const r = await consultarPanel<{ data: ObligacionDetalle | null }>(`academico/matriculas/${matriculaId}/obligacion`, `${LISTADO}/${matriculaId}`);
  if (r.estado !== 'ok') return null;
  const o = r.datos.data;

  if (o === null) {
    if (!permiteCrear) return null;
    return (
      <Seccion id="titulo-pagos" titulo="Pagos y comprobantes" descripcion="Obligación de pago en soles (PEN).">
        <div className="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
          <p className="text-sm text-panel-muted">Esta matrícula todavía no tiene una obligación de pago asignada.</p>
          <BotonCrearObligacion matriculaId={matriculaId} lado="centro" ancho="lg" />
        </div>
      </Seccion>
    );
  }

  return (
    <Seccion
      id="titulo-pagos"
      titulo="Pagos y comprobantes"
      descripcion="Estado financiero en soles (PEN) y comprobantes registrados."
      acciones={
        <div className="flex flex-wrap items-center gap-2">
          <BotonReciboPdf identificador={o.identificador} />
          <EnlaceObligacion identificador={o.identificador} etiqueta="Ver pagos y cuotas" />
        </div>
      }
    >
      <ResumenFinancieroMatricula obligacion={o} />
    </Seccion>
  );
}

function EsqueletoPagosMatricula() {
  return (
    <div className="h-56 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando pagos y comprobantes" />
  );
}

function EsqueletoDetalleMatricula() {
  return (
    <div className="space-y-6" aria-busy="true" aria-label="Cargando la matrícula">
      <div className="h-16 animate-pulse rounded-xl bg-panel-surface" />
      <div className="grid items-start gap-6 xl:grid-cols-12">
        <div className="space-y-6 xl:col-span-7">
          <div className="h-56 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
          <div className="h-64 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
        </div>
        <div className="space-y-6 xl:col-span-5">
          <div className="h-52 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
          <div className="h-64 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
        </div>
      </div>
    </div>
  );
}

function Historial({ eventos }: { eventos: EventoHistorial[] }) {
  if (eventos.length === 0) return <p className="py-6 text-center text-sm text-panel-muted">Sin operaciones registradas.</p>;

  return (
    <ol className="relative ml-1.5 space-y-5 border-l border-panel-border pl-5">
      {eventos.map((evento) => {
        const campos = evento.campos.map((campo) => etiqueta('campoMatricula', campo));
        return (
          <li key={evento.id} className="relative">
            <span
              className="absolute -left-[25px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-panel-surface bg-academico-600"
              aria-hidden="true"
            />
            <p className="text-sm font-semibold text-panel-text">{etiqueta('accionMatricula', evento.accion)}</p>
            <p className="text-xs text-panel-muted">
              <time dateTime={evento.fecha}>{FECHA_HORA.format(new Date(evento.fecha))}</time>
              {evento.usuario ? ` · ${evento.usuario}` : ''}
            </p>
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-panel-muted">
              {evento.estado_anterior && evento.estado_nuevo ? (
                <span>
                  {etiqueta('estadoMatricula', evento.estado_anterior)} → {etiqueta('estadoMatricula', evento.estado_nuevo)}
                </span>
              ) : evento.estado_nuevo ? (
                <span>Estado: {etiqueta('estadoMatricula', evento.estado_nuevo)}</span>
              ) : null}
              {evento.efecto_cupo ? <Insignia color="gris">{etiqueta('efectoCupo', evento.efecto_cupo)}</Insignia> : null}
              {campos.length > 0 && evento.accion === 'matricula_actualizada' ? <span>Cambios en: {campos.join(', ')}</span> : null}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
