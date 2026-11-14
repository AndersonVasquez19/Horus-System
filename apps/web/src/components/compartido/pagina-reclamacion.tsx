import Link from 'next/link';
import { ArrowLeft, Printer } from 'lucide-react';
import { Suspense } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { ExpedienteReclamacion, MetaExpediente } from '@/features/notificaciones/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { fecha } from '@/lib/panel/formato';
import { BotonReintento, GestionReclamacion } from './gestion-reclamacion';
import { ESTADOS_RECLAMACION } from './pagina-reclamaciones';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Expediente de una reclamación. La monta cada panel bajo `/admin/<panel>/reclamaciones/[id]`. */
export function PaginaReclamacion({ tono, params }: { tono: TonoUi; params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando la reclamación" />}>
      <Expediente tono={tono} params={params} />
    </Suspense>
  );
}

async function Expediente({ tono, params }: { tono: TonoUi; params: Promise<{ id: string }> }) {
  const { id } = await params;
  const listado = `/admin/${tono}/reclamaciones`;
  const volver = (
    <Link href={listado} className="inline-flex items-center gap-1 text-sm font-semibold text-panel-text hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver al libro de reclamaciones
    </Link>
  );

  if (!UUID.test(id)) return <EstadoSinResultados titulo="La reclamación no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ExpedienteReclamacion; meta: MetaExpediente }>(`compartido/reclamaciones/${id}`, `${listado}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="La reclamación no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const { data: r, meta } = resultado.datos;
  const estado = ESTADOS_RECLAMACION[r.estado] ?? { texto: r.estado, color: 'gris' as const };
  const c = r.consumidor;

  return (
    <>
      <EncabezadoPagina
        titulo={`Reclamación ${r.numero}`}
        descripcion={`${r.tipo === 'reclamo' ? 'Reclamo' : 'Queja'} recibido el ${r.recibida_local} (Lima)`}
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Reclamaciones', href: listado }, { etiqueta: r.numero }]}
        acciones={
          <Link href={`${listado}/${r.id}/hoja`} className="inline-flex items-center gap-2 rounded-lg border border-panel-border bg-panel-surface px-4 py-2.5 text-sm font-semibold text-panel-text hover:bg-panel-bg">
            <Printer className="h-4 w-4" aria-hidden="true" /> Hoja imprimible
          </Link>
        }
      />

      <div className="mb-6 space-y-3">
        {meta.propuesta_vencida ? (
          <Alerta tipo="info">La ventana de la propuesta de solución terminó el {fecha(r.suspension_hasta)}. El proceso diario de vencimientos reanudará el plazo.</Alerta>
        ) : meta.aviso_plazo === 'vence' ? (
          <Alerta tipo="error">El plazo legal de respuesta vence hoy o ya está vencido ({fecha(r.fecha_limite)}).</Alerta>
        ) : meta.aviso_plazo === 'por_vencer' ? (
          <Alerta tipo="aviso">El plazo legal de respuesta está por vencer ({fecha(r.fecha_limite)}).</Alerta>
        ) : null}
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-resumen" titulo="Resumen" acciones={<Insignia color={estado.color}>{estado.texto}</Insignia>}>
          <ListaDatos
            filas={[
              ['Fecha límite', fecha(r.fecha_limite)],
              ['Canal de recepción', r.canal === 'respaldo' ? `Libro físico de respaldo (#${r.numero_hoja_respaldo ?? '—'})` : 'Plataforma web'],
              ['Respuesta preferida', r.canal_respuesta === 'carta' ? 'Carta' : 'Correo electrónico'],
              ['Responsable', r.responsable ? (r.responsable.nombre ?? `Usuario N.º ${r.responsable.id}`) : 'Sin asignar'],
              ...(r.suspension_desde && r.suspension_hasta ? [['Plazo suspendido', `Del ${fecha(r.suspension_desde)} al ${fecha(r.suspension_hasta)}`] as const] : []),
              ...(r.respondida_local ? [['Respondida', `${r.respondida_local} (Lima)`] as const] : []),
            ]}
          />
        </Seccion>

        <Seccion id="titulo-consumidor" titulo="Consumidor" descripcion="Datos personales: solo para atender este caso.">
          <ListaDatos
            filas={[
              ['Nombre', `${c.nombres} ${c.apellidos}`],
              ['Documento', `${c.tipo_documento.toUpperCase()} ${c.numero_documento}`],
              ['Domicilio', c.domicilio],
              ['Teléfono', c.telefono],
              ['Correo', c.email],
              ...(c.representante
                ? [
                    ['Representante', c.representante.nombre ?? '—'] as const,
                    ['Domicilio del representante', c.representante.domicilio ?? '—'] as const,
                    ['Teléfono del representante', c.representante.telefono ?? '—'] as const,
                    ['Correo del representante', c.representante.email ?? '—'] as const,
                  ]
                : []),
            ]}
          />
        </Seccion>

        <Seccion id="titulo-bien" titulo="Bien contratado">
          <ListaDatos filas={[['Tipo', r.bien.tipo ?? '—'], ['Descripción', r.bien.descripcion ?? '—'], ['Monto', r.bien.monto ? `S/ ${r.bien.monto}` : '—']]} />
        </Seccion>

        <Seccion id="titulo-detalle" titulo="Detalle y pedido del consumidor" className="xl:col-span-3">
          <div className="grid gap-4 text-sm md:grid-cols-2">
            <div>
              <h3 className="mb-1 font-semibold text-panel-text">Detalle</h3>
              <p className="whitespace-pre-line text-panel-text">{r.detalle}</p>
            </div>
            <div>
              <h3 className="mb-1 font-semibold text-panel-text">Pedido</h3>
              <p className="whitespace-pre-line text-panel-text">{r.pedido}</p>
            </div>
          </div>
          {r.respuesta ? (
            <div className="mt-4 border-t border-panel-border pt-4 text-sm">
              <h3 className="mb-1 font-semibold text-panel-text">Respuesta formal</h3>
              <p className="whitespace-pre-line text-panel-text">{r.respuesta}</p>
            </div>
          ) : null}
        </Seccion>

        {meta.puede_gestionar || meta.puede_responder ? (
          <Seccion id="titulo-gestion" titulo="Gestión del caso" descripcion="Cada operación queda en el historial y en la auditoría." className="xl:col-span-3">
            <GestionReclamacion expediente={r} meta={meta} tono={tono} />
          </Seccion>
        ) : null}

        <Seccion id="titulo-historial" titulo="Historial" descripcion={`${r.historial.length} ${r.historial.length === 1 ? 'actuación' : 'actuaciones'}`} className="xl:col-span-2">
          {r.historial.length === 0 ? (
            <p className="text-sm text-panel-muted">No hay actuaciones registradas.</p>
          ) : (
            <ol className="space-y-4 border-l-2 border-panel-border pl-5">
              {r.historial.map((actuacion, indice) => (
                <li key={`${actuacion.evento}-${indice}`} className="text-sm">
                  <p className="font-semibold text-panel-text">{actuacion.etiqueta}</p>
                  <p className="text-xs text-panel-muted">{actuacion.fecha_local} (Lima) · {actuacion.actor}</p>
                  {actuacion.transicion ? <p className="text-xs text-panel-text">Estado: {actuacion.transicion.de} → {actuacion.transicion.a}</p> : null}
                  {actuacion.detalles.length > 0 ? <p className="text-xs text-panel-muted">{actuacion.detalles.join(' · ')}</p> : null}
                  {actuacion.nota ? <p className="mt-1 whitespace-pre-line rounded-lg border border-panel-border bg-panel-bg p-2 text-panel-text">{actuacion.nota}</p> : null}
                </li>
              ))}
            </ol>
          )}
        </Seccion>

        <Seccion id="titulo-envios" titulo="Envíos al consumidor" descripcion="Constancia de lo remitido." sinRelleno>
          {r.envios.length === 0 ? (
            <p className="px-5 pb-5 text-sm text-panel-muted">No hay envíos registrados.</p>
          ) : (
            <ul className="divide-y divide-panel-border">
              {r.envios.map((envio) => (
                <li key={envio.id} className="space-y-1 px-5 py-3 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-medium text-panel-text">{envio.tipo} · {envio.canal}</p>
                    <Insignia color={envio.estado === 'enviado' ? 'verde' : envio.estado === 'fallido' ? 'rojo' : 'azul'}>{envio.estado_etiqueta}</Insignia>
                  </div>
                  <p className="text-xs text-panel-muted">{envio.destinatario}</p>
                  <p className="text-xs text-panel-muted">
                    {envio.fecha_local ? `${envio.fecha_es_de_envio ? 'Enviado' : 'Registrado'} ${envio.fecha_local} (Lima)` : '—'}
                    {envio.intentos > 0 ? ` · ${envio.intentos} ${envio.intentos === 1 ? 'intento' : 'intentos'}` : ''}
                  </p>
                  {envio.error_codigo ? <p className="text-xs text-estado-error">Código: {envio.error_codigo}</p> : null}
                  {envio.constancia ? <p className="text-xs text-panel-muted">Constancia: {envio.constancia}</p> : null}
                  {envio.reintentable && meta.puede_gestionar ? (
                    <div className="pt-1"><BotonReintento expedienteId={r.id} envioId={envio.id} correoActivo={meta.correo_activo} tono={tono} /></div>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </Seccion>
      </div>
    </>
  );
}
