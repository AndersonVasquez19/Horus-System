import Link from 'next/link';
import { CalendarClock, ClipboardCheck, CircleDollarSign, Gauge, Hourglass, Landmark, MessageCircle, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { Insignia } from '@/components/shared/ui/insignia';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';
import type { ResumenAcademico } from '@/features/academico/tipos';
import { fecha, moneda, numero } from '@/lib/panel/formato';
import { enlaceWaMe, mensajeRecordatorioCuota } from '@/lib/panel/whatsapp-plantillas';

type Datos = ResumenAcademico;

function GrupoTablero({ id, titulo, descripcion, children }: { id: string; titulo: string; descripcion?: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="space-y-3">
      <div>
        <h2 id={id} className="font-heading text-base font-semibold text-panel-text">{titulo}</h2>
        {descripcion ? <p className="text-xs text-panel-muted">{descripcion}</p> : null}
      </div>
      {children}
    </section>
  );
}

/** Tarjeta que además lleva a donde se resuelve lo pendiente. */
function TarjetaAccion({ href, alerta, ...tarjeta }: { href: string; alerta: boolean; etiqueta: string; valor: string; detalle?: string; icono: ReactNode }) {
  return (
    <Link
      href={href}
      className={`block rounded-xl outline-offset-2 transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-academico-700 ${alerta ? 'ring-2 ring-amber-400/70' : ''}`}
    >
      <TarjetaKpi tono="academico" {...tarjeta} />
    </Link>
  );
}

/**
 * Cifras del tablero de Horus Academic. Cada bloque llega del servidor SOLO si la persona tiene el permiso de su módulo, así que aquí
 * basta con mostrar lo que venga: nunca se calcula ni se «oculta» nada en el navegador.
 */
export function TableroAcademico({ datos }: { datos: Datos }) {
  const a = datos.acciones ?? {};
  const n = datos.negocio ?? {};
  const ac = datos.academico ?? {};
  const sg = datos.seguimiento ?? {};
  const sal = datos.salud_grupo ?? {};
  const i = datos.indicadores;

  const acciones = [
    a.pagos_por_confirmar && (
      <TarjetaAccion
        key="confirmar"
        href="/admin/academico/pagos/comprobantes?estado=por_confirmar"
        alerta={a.pagos_por_confirmar.cantidad > 0}
        etiqueta="Pagos por confirmar"
        valor={numero(a.pagos_por_confirmar.cantidad)}
        detalle={a.pagos_por_confirmar.cantidad > 0 ? `${moneda(a.pagos_por_confirmar.monto)} esperando confirmación.` : 'Sin pagos pendientes.'}
        icono={<Hourglass className="h-5 w-5" />}
      />
    ),
    a.matriculas_con_saldo && (
      <TarjetaAccion
        key="saldo"
        href="/admin/academico/pagos"
        alerta={(a.cuotas?.vencidas ?? 0) > 0}
        etiqueta="Saldo por cobrar"
        valor={moneda(a.matriculas_con_saldo.monto)}
        detalle={`${numero(a.matriculas_con_saldo.cantidad)} matrícula(s) con saldo · ${detalleSaldo(a.matriculas_con_saldo.monto, a.cuotas)}`}
        icono={<CircleDollarSign className="h-5 w-5" />}
      />
    ),
    n.cobrado_mes !== undefined && (
      <TarjetaAccion
        key="cobrado"
        href="/admin/academico/pagos/completos"
        alerta={false}
        etiqueta="Cobrado en el mes"
        valor={moneda(n.cobrado_mes)}
        detalle="Pagos confirmados menos reversos."
        icono={<Landmark className="h-5 w-5" />}
      />
    ),
    i.matriculas_mes !== undefined && (
      <TarjetaAccion
        key="matriculas"
        href="/admin/academico/matriculas"
        alerta={false}
        etiqueta="Matrículas del mes"
        valor={numero(i.matriculas_mes)}
        detalle={tendencia(i.matriculas_mes, i.matriculas_mes_anterior)}
        icono={<Users className="h-5 w-5" />}
      />
    ),
    a.ediciones && (a.ediciones.por_cerrar > 0 || a.ediciones.por_iniciar > 0) && (
      <TarjetaAccion
        key="ediciones"
        href="/admin/academico/convocatorias"
        alerta
        etiqueta="Ediciones por atender"
        valor={numero(a.ediciones.por_cerrar + a.ediciones.por_iniciar)}
        detalle={`${numero(a.ediciones.por_cerrar)} por cerrar acta · ${numero(a.ediciones.por_iniciar)} por iniciar.`}
        icono={<CalendarClock className="h-5 w-5" />}
      />
    ),
    a.reclamaciones && (
      <TarjetaAccion
        key="reclamaciones"
        href="/admin/academico/reclamaciones?estado=abierto"
        alerta={a.reclamaciones.vencidas + a.reclamaciones.vencen_hoy > 0}
        etiqueta="Plazos de reclamaciones"
        valor={numero(a.reclamaciones.vencidas + a.reclamaciones.vencen_hoy + a.reclamaciones.por_vencer)}
        detalle={
          a.reclamaciones.vencidas + a.reclamaciones.vencen_hoy + a.reclamaciones.por_vencer === 0
            ? 'Todas dentro del plazo.'
            : `${numero(a.reclamaciones.vencidas)} vencida(s) · ${numero(a.reclamaciones.vencen_hoy)} vencen hoy · ${numero(a.reclamaciones.por_vencer)} por vencer.`
        }
        icono={<MessageCircle className="h-5 w-5" />}
      />
    ),
    ac.satisfaccion && (
      <TarjetaAccion
        key="satisfaccion"
        href="/admin/academico/convocatorias"
        alerta={ac.satisfaccion.felices_pct !== null && ac.satisfaccion.felices_pct < 60}
        etiqueta="Satisfacción (90 días)"
        valor={ac.satisfaccion.felices_pct === null ? '—' : `${numero(ac.satisfaccion.felices_pct)} %`}
        detalle={
          ac.satisfaccion.respondidas === 0
            ? `Sin respuestas aún (${numero(ac.satisfaccion.emitidas)} encuestas emitidas).`
            : `Alumnos satisfechos · ${numero(ac.satisfaccion.respondidas)} de ${numero(ac.satisfaccion.emitidas)} respondieron (${numero(ac.satisfaccion.tasa_respuesta ?? 0)} %).`
        }
        icono={<Gauge className="h-5 w-5" />}
      />
    ),
    sg.aprobaciones_pendientes !== undefined && sg.aprobaciones_pendientes > 0 && (
      <TarjetaAccion
        key="aprobaciones"
        href="/admin/academico/aprobaciones"
        alerta
        etiqueta="Aprobaciones pendientes"
        valor={numero(sg.aprobaciones_pendientes)}
        detalle="Solicitudes esperando tu decisión."
        icono={<ClipboardCheck className="h-5 w-5" />}
      />
    ),
  ].filter(Boolean);

  const sinContenido = acciones.length === 0 && !ac.cupos && !ac.asistencia && !sal.cuotas_vencidas && !sal.inasistencias;
  if (sinContenido) return null;

  return (
    <div className="space-y-6">
      {acciones.length > 0 ? (
        <section aria-label="Indicadores principales" className="space-y-2">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{acciones}</div>
          {a.ediciones && a.ediciones.lista_por_cerrar.length > 0 ? (
            <p className="text-xs text-panel-muted">
              Actas por cerrar:{' '}
              {a.ediciones.lista_por_cerrar.map((e, k) => (
                <span key={e.identificador}>
                  {k > 0 ? ', ' : ''}
                  <Link href={`/admin/academico/convocatorias/${e.identificador}`} className="font-semibold text-academico-700 underline-offset-4 hover:underline">
                    {e.codigo}
                  </Link>
                </span>
              ))}
              .
            </p>
          ) : null}
        </section>
      ) : null}

      {ac.cupos || ac.asistencia ? (
        <GrupoTablero id="titulo-academico" titulo="Calidad académica" descripcion="Cupos y asistencia.">
          <div className="grid gap-6 xl:grid-cols-2">
            {ac.cupos ? (
              <Lista titulo="Cupos por edición" vacio="No hay ediciones abiertas ni en curso.">
                {ac.cupos.map((c) => (
                  <li key={c.identificador} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <Link href={`/admin/academico/convocatorias/${c.identificador}`} className="min-w-0 truncate font-medium text-panel-text hover:text-academico-800 hover:underline">
                      <span className="font-mono text-xs text-panel-muted">{c.codigo}</span> {c.curso}
                    </Link>
                    <span className="shrink-0 tabular-nums text-panel-muted">
                      {c.ocupados}/{c.maximo} · <strong className={c.libres === 0 ? 'text-estado-error' : 'text-panel-text'}>{c.libres} libres</strong>
                    </span>
                  </li>
                ))}
              </Lista>
            ) : null}

            {ac.asistencia ? (
              <Lista titulo={`Asistencia de ediciones en curso (mínimo ${ac.asistencia_minima ?? 80} %)`} vacio="No hay ediciones en curso.">
                {ac.asistencia.map((e) => (
                  <li key={e.identificador} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <Link href={`/admin/academico/convocatorias/${e.identificador}`} className="min-w-0 truncate font-medium text-panel-text hover:text-academico-800 hover:underline">
                      <span className="font-mono text-xs text-panel-muted">{e.codigo}</span> {e.curso}
                    </Link>
                    <span className="flex shrink-0 items-center gap-2 tabular-nums">
                      {e.promedio === null ? <span className="text-panel-muted">Sin sesiones realizadas</span> : <span>{e.promedio} %</span>}
                      {e.bajo_minimo ? <Insignia color="ambar">Bajo el mínimo</Insignia> : null}
                    </span>
                  </li>
                ))}
              </Lista>
            ) : null}
          </div>
        </GrupoTablero>
      ) : null}

      {sal.cuotas_vencidas || sal.inasistencias ? (
        <GrupoTablero id="tablero-salud-grupo" titulo="Salud del grupo" descripcion="Alertas accionables de mora e inasistencias reiteradas con contacto directo por WhatsApp.">
          <div className="grid gap-4 lg:grid-cols-2">
            {sal.cuotas_vencidas ? (
              <Lista titulo="Cuotas vencidas" vacio="No hay cuotas vencidas.">
                {sal.cuotas_vencidas.map((c) => {
                  const wa = enlaceWaMe(c.telefono, mensajeRecordatorioCuota({ estudiante: c.estudiante, curso: c.concepto, saldo: c.saldo, vence: fecha(c.vence) }));
                  return (
                    <li key={`${c.obligacion}-${c.vence}`} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                      <div className="min-w-0">
                        <Link href={`/admin/academico/pagos/${c.obligacion}`} className="block truncate font-medium text-panel-text hover:text-academico-800 hover:underline">
                          {c.estudiante}
                        </Link>
                        <p className="truncate text-xs text-panel-muted">{c.concepto} · venció {fecha(c.vence)}</p>
                      </div>
                      <div className="flex shrink-0 items-center gap-2">
                        <Insignia color="rojo">{moneda(c.saldo)}</Insignia>
                        {wa ? (
                          <a href={wa} target="_blank" rel="noopener noreferrer" title="Recordar por WhatsApp" className="rounded-lg border border-emerald-600/30 bg-emerald-50 p-1.5 text-emerald-800 hover:bg-emerald-100">
                            <MessageCircle className="h-4 w-4" aria-hidden="true" />
                          </a>
                        ) : null}
                      </div>
                    </li>
                  );
                })}
              </Lista>
            ) : null}

            {sal.inasistencias ? (
              <Lista titulo="Alumnos con 2+ faltas seguidas" vacio="Sin alertas de inasistencia en ediciones en curso.">
                {sal.inasistencias.map((a) => {
                  const wa = enlaceWaMe(a.telefono, `Hola ${a.estudiante}, te saludamos de Horus Group 👋. Notamos que faltaste a las últimas ${a.faltas_seguidas} sesiones de ${a.edicion}. ¿Todo bien?`);
                  return (
                    <li key={a.matricula_id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                      <div className="min-w-0">
                        <Link href={`/admin/academico/matriculas/${a.matricula_id}`} className="block truncate font-medium text-panel-text hover:text-academico-800 hover:underline">
                          {a.estudiante}
                        </Link>
                        <p className="truncate text-xs text-panel-muted">Edición {a.edicion}</p>
                      </div>
                      <div className="flex shrink-0 items-center gap-2">
                        <Insignia color="ambar">{a.faltas_seguidas} faltas</Insignia>
                        {wa ? (
                          <a href={wa} target="_blank" rel="noopener noreferrer" title="Escribir por WhatsApp" className="rounded-lg border border-emerald-600/30 bg-emerald-50 p-1.5 text-emerald-800 hover:bg-emerald-100">
                            <MessageCircle className="h-4 w-4" aria-hidden="true" />
                          </a>
                        ) : null}
                      </div>
                    </li>
                  );
                })}
              </Lista>
            ) : null}
          </div>
        </GrupoTablero>
      ) : null}
    </div>
  );
}

function Lista({ titulo, vacio, children }: { titulo: string; vacio: string; children: ReactNode[] }) {
  return (
    <div className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm">
      <h3 className="flex items-center gap-2 px-4 pt-4 text-sm font-semibold text-panel-text">
        <Gauge className="h-4 w-4 text-panel-muted" aria-hidden="true" />
        {titulo}
      </h3>
      {children.length === 0 ? <p className="px-4 py-8 text-center text-sm text-panel-muted">{vacio}</p> : <ul className="mt-2 divide-y divide-panel-border border-t border-panel-border">{children}</ul>}
    </div>
  );
}

function detalleSaldo(monto: string, cuotas?: NonNullable<Datos['acciones']>['cuotas']): string {
  const base = `${moneda(monto)} por cobrar`;
  if (!cuotas) return `${base}.`;
  const partes: string[] = [];
  if (cuotas.vencidas > 0) {
    partes.push(`${numero(cuotas.vencidas)} ${cuotas.vencidas === 1 ? 'cuota vencida' : 'cuotas vencidas'} (${moneda(cuotas.monto_vencido)})`);
  }
  if (cuotas.por_vencer > 0) {
    partes.push(`${numero(cuotas.por_vencer)} por vencer (${moneda(cuotas.monto_por_vencer)})`);
  }
  return partes.length > 0 ? `${base} · ${partes.join(' · ')}.` : `${base}.`;
}

/** «+3 frente al mes anterior (5)»; sin referencia anterior, solo el dato disponible. */
function tendencia(actual: number, anterior: number | undefined): string {
  if (anterior === undefined) return 'Mes en curso (Lima).';
  const diferencia = actual - anterior;
  const texto = diferencia === 0 ? 'Igual que el mes anterior' : `${diferencia > 0 ? '+' : ''}${diferencia} frente al mes anterior`;
  return `${texto} (${anterior}).`;
}

