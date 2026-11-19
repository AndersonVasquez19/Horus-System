import Link from 'next/link';
import { Bell, CalendarClock, ClipboardCheck, CircleDollarSign, Gauge, Hourglass, Landmark, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { Insignia } from '@/components/shared/ui/insignia';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';
import type { ResumenAcademico } from '@/features/academico/tipos';
import { moneda, numero } from '@/lib/panel/formato';

type Datos = ResumenAcademico;

function Seccion({ id, titulo, descripcion, children }: { id: string; titulo: string; descripcion?: string; children: ReactNode }) {
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
  const i = datos.indicadores;

  const acciones = [
    a.pagos_por_confirmar && (
      <TarjetaAccion
        key="confirmar"
        href="/admin/academico/pagos"
        alerta={a.pagos_por_confirmar.cantidad > 0}
        etiqueta="Pagos por confirmar"
        valor={numero(a.pagos_por_confirmar.cantidad)}
        detalle={a.pagos_por_confirmar.cantidad > 0 ? `${moneda(a.pagos_por_confirmar.monto)} esperando tu confirmación.` : 'No hay pagos esperando confirmación.'}
        icono={<Hourglass className="h-5 w-5" />}
      />
    ),
    a.matriculas_con_saldo && (
      <TarjetaAccion
        key="saldo"
        href="/admin/academico/pagos?estado=con_saldo"
        alerta={(a.cuotas?.vencidas ?? 0) > 0}
        etiqueta="Matrículas con saldo pendiente"
        valor={numero(a.matriculas_con_saldo.cantidad)}
        detalle={detalleSaldo(a.matriculas_con_saldo.monto, a.cuotas)}
        icono={<CircleDollarSign className="h-5 w-5" />}
      />
    ),
    a.ediciones && (
      <TarjetaAccion
        key="ediciones"
        href="/admin/academico/convocatorias"
        alerta={a.ediciones.por_cerrar > 0 || a.ediciones.por_iniciar > 0}
        etiqueta="Ediciones que requieren acción"
        valor={numero(a.ediciones.por_cerrar + a.ediciones.por_iniciar)}
        detalle={`${numero(a.ediciones.por_cerrar)} por cerrar el acta · ${numero(a.ediciones.por_iniciar)} por iniciar.`}
        icono={<CalendarClock className="h-5 w-5" />}
      />
    ),
  ].filter(Boolean);

  const negocio = [
    n.cobrado_mes !== undefined && (
      <TarjetaKpi key="cobrado" tono="academico" etiqueta="Cobrado en el mes" valor={moneda(n.cobrado_mes)} icono={<Landmark className="h-5 w-5" />} detalle="Pagos confirmados menos reversos (Lima)." />
    ),
    n.saldo_por_cobrar !== undefined && (
      <TarjetaKpi key="porcobrar" tono="academico" etiqueta="Saldo total por cobrar" valor={moneda(n.saldo_por_cobrar)} icono={<CircleDollarSign className="h-5 w-5" />} detalle="De las obligaciones vigentes." />
    ),
    i.matriculas_mes !== undefined && (
      <TarjetaKpi
        key="matriculas"
        tono="academico"
        etiqueta="Matrículas del mes"
        valor={numero(i.matriculas_mes)}
        icono={<Users className="h-5 w-5" />}
        detalle={tendencia(i.matriculas_mes, i.matriculas_mes_anterior)}
      />
    ),
  ].filter(Boolean);

  const haySeguimiento = sg.aprobaciones_pendientes !== undefined || (sg.notificaciones_sin_leer ?? 0) > 0 || (sg.auditoria_reciente?.length ?? 0) > 0;
  const sinContenido = acciones.length === 0 && negocio.length === 0 && !ac.cupos && !ac.asistencia && !haySeguimiento;
  if (sinContenido) return null;

  return (
    <div className="space-y-8">
      {acciones.length > 0 ? (
        <Seccion id="titulo-acciones" titulo="Requiere tu atención" descripcion="Lo pendiente de resolver hoy.">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{acciones}</div>
          {a.ediciones && a.ediciones.lista_por_cerrar.length > 0 ? (
            <p className="text-sm text-panel-muted">
              Por cerrar:{' '}
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
        </Seccion>
      ) : null}

      {haySeguimiento ? (
        <Seccion id="titulo-seguimiento" titulo="Seguimiento" descripcion="Pendientes propios y actividad reciente del sistema.">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {sg.aprobaciones_pendientes !== undefined ? (
              <TarjetaAccion
                href="/admin/academico/aprobaciones"
                alerta={sg.aprobaciones_pendientes > 0}
                etiqueta="Aprobaciones pendientes"
                valor={numero(sg.aprobaciones_pendientes)}
                detalle={sg.aprobaciones_pendientes > 0 ? 'Solicitudes internas esperando una decisión.' : 'No hay aprobaciones pendientes.'}
                icono={<ClipboardCheck className="h-5 w-5" />}
              />
            ) : null}
            <TarjetaAccion
              href="/admin/academico/notificaciones"
              alerta={(sg.notificaciones_sin_leer ?? 0) > 0}
              etiqueta="Notificaciones sin leer"
              valor={numero(sg.notificaciones_sin_leer ?? 0)}
              detalle="Avisos internos dirigidos a ti."
              icono={<Bell className="h-5 w-5" />}
            />
          </div>
          {sg.auditoria_reciente && sg.auditoria_reciente.length > 0 ? (
            <Lista titulo="Actividad reciente" vacio="Sin actividad reciente.">
              {sg.auditoria_reciente.map((a) => (
                <li key={a.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                  <span className="min-w-0 truncate text-panel-text">
                    <span className="font-medium">{a.usuario ?? 'Sistema'}</span> <span className="text-panel-muted">{a.accion_etiqueta ?? a.accion}</span>
                  </span>
                  <span className="shrink-0 tabular-nums text-panel-muted">{a.fecha_local}</span>
                </li>
              ))}
            </Lista>
          ) : null}
        </Seccion>
      ) : null}

      {negocio.length > 0 ? (
        <Seccion id="titulo-negocio" titulo="Negocio" descripcion="Cobranza y captación.">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{negocio}</div>
        </Seccion>
      ) : null}

      {ac.cupos || ac.asistencia ? (
        <Seccion id="titulo-academico" titulo="Calidad académica" descripcion="Cupos y asistencia.">
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
        </Seccion>
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

