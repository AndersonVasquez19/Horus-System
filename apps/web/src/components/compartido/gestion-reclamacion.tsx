'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { ExpedienteReclamacion, MetaExpediente } from '@/features/notificaciones/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

type Operacion = 'responsable' | 'tipo' | 'nota' | 'propuesta' | 'rechazo' | 'aceptacion' | 'respuesta';

const ETIQUETAS: Record<Operacion, string> = {
  responsable: 'Asignar responsable',
  tipo: 'Reclasificar tipo',
  nota: 'Agregar nota interna',
  propuesta: 'Enviar propuesta de solución',
  rechazo: 'Registrar rechazo de la propuesta',
  aceptacion: 'Registrar aceptación de la propuesta',
  respuesta: 'Registrar respuesta formal',
};

const GESTIONAR: readonly Operacion[] = ['responsable', 'tipo', 'nota', 'propuesta', 'rechazo'];
const RESPONDER: readonly Operacion[] = ['aceptacion', 'respuesta'];

const PLANTILLAS_PROPUESTA = [
  {
    etiqueta: 'Reprogramación sin costo',
    asunto: 'Propuesta de solución · Reprogramación de matrícula',
    cuerpo: 'Estimado(a),\n\nEn atención a su solicitud, proponemos reprogramar su matrícula a la próxima edición disponible sin costo adicional.\n\nAgradecemos confirmarnos su conformidad por este medio.',
  },
  {
    etiqueta: 'Devolución de pago',
    asunto: 'Propuesta de solución · Devolución de importe abonado',
    cuerpo: 'Estimado(a),\n\nTras revisar su caso, proponemos proceder con la devolución del importe correspondiente dentro de los próximos 5 días hábiles.\n\nSírvase confirmar su conformidad para completar el trámite.',
  },
] as const;

const PLANTILLAS_RESPUESTA = [
  {
    etiqueta: 'Procedente con solución aplicada',
    texto: 'Luego de evaluar los hechos y documentos presentados, declaramos su solicitud PROCEDENTE y confirmamos la ejecución de la medida correctiva correspondiente.',
  },
  {
    etiqueta: 'Procedente por reprogramación / crédito',
    texto: 'Luego de evaluar su caso, declaramos su solicitud PROCEDENTE y otorgamos el saldo a favor / reprogramación académica solicitada conforme a nuestros términos vigentes.',
  },
  {
    etiqueta: 'Improcedente con sustento',
    texto: 'Tras revisar los registros y condiciones aceptadas al momento de la contratación del servicio, informamos que su solicitud resulta IMPROCEDENTE debido a que el servicio se brindó según lo pactado.',
  },
] as const;

/** Mensaje legible de un error del panel: el primero de validación si lo hay (Laravel ya lo redacta sin datos personales). */
function mensajeDe(error: ErrorPanel): string {
  const primero = error.errors ? Object.values(error.errors)[0]?.[0] : undefined;
  return primero ?? error.message;
}

interface GestionProps {
  expediente: ExpedienteReclamacion;
  meta: MetaExpediente;
  tono: TonoUi;
}

/**
 * Gestión del caso. Laravel decide qué operación admite el estado actual (un 409 se muestra tal cual, sin datos
 * personales) y vuelve a comprobar el permiso de cada una; aquí solo se ofrecen las que corresponden a los permisos.
 * Las operaciones que mandan un correo real al consumidor exigen confirmación explícita.
 */
export function GestionReclamacion({ expediente, meta, tono }: GestionProps) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const permitidas = [...(meta.puede_gestionar ? GESTIONAR : []), ...(meta.puede_responder ? RESPONDER : [])];
  const [operacion, setOperacion] = useState<Operacion | ''>('');
  const [campos, setCampos] = useState<Record<string, string>>({});
  const [confirmar, setConfirmar] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [enviando, setEnviando] = useState(false);

  if (permitidas.length === 0) return null;

  const valor = (campo: string) => campos[campo] ?? '';
  const poner = (campo: string) => (e: { target: { value: string } }) => setCampos((actual) => ({ ...actual, [campo]: e.target.value }));
  const err = (campo: string) => errorDeCampo(error, campo);
  const enviaCorreo = meta.correo_activo && (operacion === 'propuesta' || (operacion === 'respuesta' && valor('canal') !== 'carta'));

  function elegir(nueva: Operacion | '') {
    setOperacion(nueva);
    setCampos(nueva === 'tipo' ? { tipo: expediente.tipo } : nueva === 'responsable' ? { responsable_id: String(expediente.responsable?.id ?? '') } : nueva === 'respuesta' ? { canal: 'correo' } : nueva === 'aceptacion' ? { medio: 'correo' } : {});
    setConfirmar(false);
    setError(null);
  }

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (operacion === '') return;
    setEnviando(true);
    setError(null);

    const cuerpo: Record<string, unknown> = { operacion, ...campos };
    if (operacion === 'responsable') cuerpo.responsable_id = valor('responsable_id') === '' ? null : Number(valor('responsable_id'));
    if (operacion === 'rechazo' && valor('nota') === '') delete cuerpo.nota;
    if (operacion === 'respuesta' && valor('constancia') === '') delete cuerpo.constancia;
    if (enviaCorreo) cuerpo.confirmar_envio = confirmar;

    const resultado = await ejecutar<{ message: string }>(`compartido/reclamaciones/${expediente.id}/gestion`, 'POST', cuerpo);
    setEnviando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    mostrar('exito', resultado.datos.message);
    elegir('');
    router.refresh();
  }

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      <CampoSeleccion
        tono={tono}
        etiqueta="Operación"
        value={operacion}
        onChange={(e) => elegir(e.target.value as Operacion | '')}
        opciones={{ '': 'Elige una operación…', ...Object.fromEntries(permitidas.map((clave) => [clave, ETIQUETAS[clave]])) }}
      />

      {operacion !== '' ? (
        <div className="space-y-4">
          {error && !error.errors ? <Alerta tipo="error">{error.message}</Alerta> : null}
          {error?.errors && Object.keys(error.errors).some((campo) => !(campo in campos) && campo !== 'confirmar_envio') ? <Alerta tipo="error">{mensajeDe(error)}</Alerta> : null}

          {operacion === 'responsable' ? (
            <CampoSeleccion
              tono={tono}
              etiqueta="Responsable"
              value={valor('responsable_id')}
              onChange={poner('responsable_id')}
              error={err('responsable_id')}
              opciones={{ '': 'Sin asignar', ...Object.fromEntries(meta.responsables.map((u) => [String(u.id), u.name])) }}
            />
          ) : null}
          {operacion === 'tipo' ? (
            <CampoSeleccion tono={tono} etiqueta="Tipo de registro" value={valor('tipo')} onChange={poner('tipo')} error={err('tipo')} opciones={{ reclamo: 'Reclamo', queja: 'Queja' }} />
          ) : null}
          {operacion === 'nota' ? <CampoArea tono={tono} etiqueta="Nota interna" value={valor('nota')} onChange={poner('nota')} error={err('nota')} maxLength={2000} rows={4} /> : null}
          {operacion === 'propuesta' ? (
            <>
              <div className="space-y-1.5">
                <p className="text-xs font-medium text-panel-muted">Plantillas rápidas</p>
                <div className="flex flex-wrap gap-1.5">
                  {PLANTILLAS_PROPUESTA.map((p) => (
                    <button
                      key={p.etiqueta}
                      type="button"
                      onClick={() => setCampos((actual) => ({ ...actual, asunto: p.asunto, cuerpo: p.cuerpo }))}
                      className="rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1 text-xs font-medium text-panel-text hover:bg-panel-surface"
                    >
                      {p.etiqueta}
                    </button>
                  ))}
                </div>
              </div>
              <Campo tono={tono} etiqueta="Asunto" value={valor('asunto')} onChange={poner('asunto')} error={err('asunto')} maxLength={200} />
              <CampoArea tono={tono} etiqueta="Texto de la propuesta" value={valor('cuerpo')} onChange={poner('cuerpo')} error={err('cuerpo')} maxLength={10000} rows={6} />
            </>
          ) : null}
          {operacion === 'rechazo' ? (
            <>
              <Campo tono={tono} etiqueta="Fecha de recepción del rechazo" type="date" value={valor('fecha_recepcion')} onChange={poner('fecha_recepcion')} error={err('fecha_recepcion')} />
              <CampoArea tono={tono} etiqueta="Nota (opcional)" value={valor('nota')} onChange={poner('nota')} error={err('nota')} maxLength={2000} rows={3} />
            </>
          ) : null}
          {operacion === 'aceptacion' ? (
            <>
              <Campo tono={tono} etiqueta="Fecha de recepción de la aceptación" type="date" value={valor('fecha_recepcion')} onChange={poner('fecha_recepcion')} error={err('fecha_recepcion')} />
              <CampoSeleccion tono={tono} etiqueta="Medio" value={valor('medio')} onChange={poner('medio')} error={err('medio')} opciones={{ correo: 'Correo', otro: 'Otro' }} />
              <CampoArea tono={tono} etiqueta="Constancia" value={valor('constancia')} onChange={poner('constancia')} error={err('constancia')} maxLength={2000} rows={3} />
            </>
          ) : null}
          {operacion === 'respuesta' ? (
            <>
              <CampoSeleccion tono={tono} etiqueta="Canal" value={valor('canal')} onChange={poner('canal')} error={err('canal')} opciones={{ correo: 'Correo electrónico', carta: 'Carta' }} />
              <div className="space-y-1.5">
                <p className="text-xs font-medium text-panel-muted">Plantillas de respuesta</p>
                <div className="flex flex-wrap gap-1.5">
                  {PLANTILLAS_RESPUESTA.map((p) => (
                    <button
                      key={p.etiqueta}
                      type="button"
                      onClick={() => setCampos((actual) => ({ ...actual, respuesta: p.texto }))}
                      className="rounded-lg border border-panel-border bg-panel-bg px-2.5 py-1 text-xs font-medium text-panel-text hover:bg-panel-surface"
                    >
                      {p.etiqueta}
                    </button>
                  ))}
                </div>
              </div>
              <CampoArea tono={tono} etiqueta="Respuesta formal" value={valor('respuesta')} onChange={poner('respuesta')} error={err('respuesta')} maxLength={10000} rows={6} />
              {valor('canal') === 'carta' ? (
                <Campo tono={tono} etiqueta="Constancia de la carta" value={valor('constancia')} onChange={poner('constancia')} error={err('constancia')} maxLength={255} ayuda="Fecha, medio y cargo o guía." />
              ) : null}
            </>
          ) : null}

          {enviaCorreo ? (
            <label className="flex items-start gap-2 text-sm text-panel-text">
              <input type="checkbox" checked={confirmar} onChange={(e) => setConfirmar(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-panel-border" />
              <span>Confirmo que deseo enviar un correo real al consumidor.</span>
            </label>
          ) : null}
          {err('confirmar_envio') ? <p role="alert" className="text-sm text-estado-error">{err('confirmar_envio')}</p> : null}

          <div className="flex justify-end">
            <Boton tono={tono} type="submit" cargando={enviando}>{ETIQUETAS[operacion]}</Boton>
          </div>
        </div>
      ) : null}
    </form>
  );
}

/** Reintento de un envío por correo fallido (solo con `reclamaciones.gestionar`). */
export function BotonReintento({ expedienteId, envioId, correoActivo, tono }: { expedienteId: string; envioId: number; correoActivo: boolean; tono: TonoUi }) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [confirmar, setConfirmar] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  async function reintentar() {
    setEnviando(true);
    setError(null);
    const cuerpo: Record<string, unknown> = { operacion: 'reintento', envio_id: envioId };
    if (correoActivo) cuerpo.confirmar_envio = confirmar;
    const resultado = await ejecutar<{ message: string }>(`compartido/reclamaciones/${expedienteId}/gestion`, 'POST', cuerpo);
    setEnviando(false);
    if (!resultado.ok) {
      setError(mensajeDe(resultado.error));
      return;
    }
    setAbierto(false);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <>
      <Boton tono={tono} variante="secundario" onClick={() => setAbierto(true)}>Reintentar</Boton>
      <Dialogo abierto={abierto} titulo="Reintentar el envío" onCerrar={() => setAbierto(false)} ancho="sm" bloqueado={enviando}>
        <div className="space-y-4 text-sm">
          <p className="text-panel-muted">Se reprogramará el envío con el mismo texto que se guardó.</p>
          {correoActivo ? (
            <label className="flex items-start gap-2 text-panel-text">
              <input type="checkbox" checked={confirmar} onChange={(e) => setConfirmar(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-panel-border" />
              <span>Confirmo que deseo enviar un correo real al consumidor.</span>
            </label>
          ) : null}
          {error ? <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-estado-error">{error}</p> : null}
          <div className="pie-acciones">
            <Boton tono={tono} variante="secundario" onClick={() => setAbierto(false)} disabled={enviando}>Cancelar</Boton>
            <Boton tono={tono} onClick={reintentar} cargando={enviando}>Reintentar envío</Boton>
          </div>
        </div>
      </Dialogo>
    </>
  );
}
