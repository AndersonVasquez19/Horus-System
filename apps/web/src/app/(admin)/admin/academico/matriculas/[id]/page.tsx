import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesMatricula } from '@/components/academico/acciones-matricula';
import { BotonCrearObligacion, COLOR_COBRO, ComprobantesDeLaMatricula, ETIQUETA_COBRO, EnlaceObligacion } from '@/components/academico/paneles-pagos';
import { Alerta } from '@/components/shared/ui/alerta';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import type { EventoHistorial, MatriculaDetalle, ObligacionDetalle } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha, moneda, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Matrícula · Gestión académica' };

// Ver la nota de cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/academico/matriculas';
const FECHA_HORA = new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Lima' });

export default function MatriculaDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando la matrícula" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
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
        acciones={<AccionesMatricula matricula={matricula} />}
      />

      {matricula.eliminado ? <Alerta tipo="aviso" className="mb-6">Esta matrícula está eliminada y no ocupa cupo.</Alerta> : null}

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion
          id="titulo-matricula"
          titulo="Datos de la matrícula"
          acciones={
            <div className="flex flex-wrap gap-1">
              <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
              {matricula.eliminado ? <Insignia color="rojo">Eliminada</Insignia> : null}
            </div>
          }
        >
          <ListaDatos
            filas={[
              [
                'Estudiante',
                matricula.estudiante ? (
                  <Link href={`/admin/academico/estudiantes/${matricula.estudiante.id}`} className="text-academico-800 hover:underline">
                    {matricula.estudiante.nombre} <span className="font-mono text-xs text-panel-muted">({matricula.estudiante.dni})</span>
                  </Link>
                ) : '—',
              ],
              [
                'Curso',
                matricula.curso ? (
                  <Link href={`/admin/academico/cursos/${matricula.curso.id}`} className="text-academico-800 hover:underline">
                    {matricula.curso.nombre} <span className="text-xs text-panel-muted">({matricula.curso.codigo})</span>
                  </Link>
                ) : '—',
              ],
              [
                'Convocatoria',
                matricula.convocatoria ? (
                  <Link href={`/admin/academico/convocatorias/${matricula.convocatoria.identificador}`} className="font-mono text-academico-800 hover:underline">
                    {matricula.convocatoria.codigo}
                  </Link>
                ) : 'Sin convocatoria (el cupo es el del curso)',
              ],
              ['Cupos libres del curso', numero(matricula.curso_cupos_disponibles)],
              ['Fecha', fecha(matricula.fecha)],
              ['Monto pagado', moneda(matricula.monto_pagado)],
              ['Observaciones', matricula.observaciones ? <span className="whitespace-pre-line font-normal">{matricula.observaciones}</span> : '—'],
              ['Registrada', fecha(matricula.creado_en)],
              ['Última actualización', fecha(matricula.actualizado_en)],
            ]}
          />
        </Seccion>

        {matricula.convocatoria && !matricula.eliminado && matricula.estado !== 'anulada' ? (
          <Suspense fallback={null}>
            <PagosDeLaMatricula matriculaId={matricula.id} />
          </Suspense>
        ) : null}

        <Seccion id="titulo-historial" titulo="Historial" descripcion="Operaciones registradas en la auditoría." className="xl:col-span-2">
          <Historial eventos={matricula.historial ?? []} />
        </Seccion>
      </div>
    </>
  );
}

/** Obligación de pago de la matrícula: solo se muestra a quien tiene `pagos.ver`. */
async function PagosDeLaMatricula({ matriculaId }: { matriculaId: number }) {
  const r = await consultarPanel<{ data: ObligacionDetalle | null }>(`academico/matriculas/${matriculaId}/obligacion`, `${LISTADO}/${matriculaId}`);
  if (r.estado !== 'ok') return null;
  const o = r.datos.data;
  return (
    <>
    <Seccion id="titulo-pagos" titulo="Pagos" descripcion="Obligación de pago en soles (PEN).">
      {o === null ? (
        <div className="space-y-3">
          <p className="text-sm text-panel-muted">Esta matrícula todavía no tiene una obligación de pago.</p>
          <BotonCrearObligacion matriculaId={matriculaId} />
        </div>
      ) : (
        <div className="space-y-2">
          <Insignia color={COLOR_COBRO[o.estado]}>{ETIQUETA_COBRO[o.estado]}</Insignia>
          <p className="text-sm text-panel-text">{o.concepto}: pagado {moneda(o.pagado)} de {moneda(o.monto_total)}; saldo {moneda(o.saldo)}.</p>
          <EnlaceObligacion identificador={o.identificador} />
        </div>
      )}
    </Seccion>
    {o !== null ? (
      <Seccion id="titulo-comprobante" titulo="Comprobante de pago" descripcion="Captura de cada pago: ábrela, descárgala o adjúntala si el pago aún está por confirmar.">
        <ComprobantesDeLaMatricula obligacion={o} />
      </Seccion>
    ) : null}
    </>
  );
}

function Historial({ eventos }: { eventos: EventoHistorial[] }) {
  if (eventos.length === 0) return <p className="py-8 text-center text-sm text-panel-muted">Sin operaciones registradas.</p>;

  return (
    <ol className="relative space-y-5 border-l border-panel-border pl-5">
      {eventos.map((evento) => {
        const campos = evento.campos.map((campo) => etiqueta('campoMatricula', campo));
        return (
          <li key={evento.id} className="relative">
            <span className="absolute -left-[1.6rem] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-panel-surface bg-academico-600" aria-hidden="true" />
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
