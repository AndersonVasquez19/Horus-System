'use client';

import { CircleCheck, CircleX, Copy, Lock, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { ETIQUETAS_TIPO_APROBACION } from '@/features/notificaciones/aprobaciones';
import type { AprobacionFila, EventoAprobacion } from '@/features/notificaciones/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

const TITULOS_EVENTO: Record<string, string> = {
  aprobacion_creada: 'Solicitud registrada',
  aprobacion_actualizada: 'Datos actualizados',
  aprobacion_aprobada: 'Aprobada',
  aprobacion_rechazada: 'Rechazada',
  aprobacion_reabierta: 'Reabierta',
  aprobacion_eliminada: 'Eliminada',
};

type Respuesta = { message: string };

/** «Nueva solicitud»: solo con `aprobaciones.gestionar`. El solicitante es siempre la sesión (lo fija Laravel). */
export function BotonNuevaAprobacion({ tono }: { tono: TonoUi }) {
  const puede = usePuede('aprobaciones.gestionar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [campos, setCampos] = useState({ titulo: '', tipo: 'compra', descripcion: '', referencia: '' });

  if (!puede) return null;

  const poner = (campo: keyof typeof campos) => (e: { target: { value: string } }) => setCampos((actual) => ({ ...actual, [campo]: e.target.value }));
  const opcional = (valor: string) => (valor.trim() === '' ? null : valor.trim());

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>('compartido/aprobaciones', 'POST', {
      titulo: campos.titulo.trim(),
      tipo: campos.tipo,
      descripcion: opcional(campos.descripcion),
      referencia: opcional(campos.referencia),
    });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setAbierto(false);
    setCampos({ titulo: '', tipo: 'compra', descripcion: '', referencia: '' });
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <>
      <Boton tono={tono} icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setAbierto(true); }}>Nueva solicitud</Boton>
      <Dialogo abierto={abierto} lado="derecha" titulo="Nueva solicitud" descripcion="Se enviará a la autoridad competente según el tipo." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <form onSubmit={(e) => void enviar(e)} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <Campo tono={tono} etiqueta="Título" maxLength={255} value={campos.titulo} onChange={poner('titulo')} error={errorDeCampo(error, 'titulo')} required autoFocus />
          <CampoSeleccion tono={tono} etiqueta="Tipo" value={campos.tipo} onChange={poner('tipo')} error={errorDeCampo(error, 'tipo')} opciones={ETIQUETAS_TIPO_APROBACION} ayuda="Compras las resuelve Gerencia; las operativas, Coordinación." />
          <CampoArea tono={tono} etiqueta="Descripción (opcional)" maxLength={5000} rows={4} value={campos.descripcion} onChange={poner('descripcion')} error={errorDeCampo(error, 'descripcion')} />
          <Campo tono={tono} etiqueta="Referencia (opcional)" maxLength={255} value={campos.referencia} onChange={poner('referencia')} error={errorDeCampo(error, 'referencia')} />
          <div className="pie-acciones">
            <Boton tono={tono} variante="secundario" onClick={() => setAbierto(false)} disabled={ocupado}>Cancelar</Boton>
            <Boton tono={tono} type="submit" cargando={ocupado}>Registrar solicitud</Boton>
          </div>
        </form>
      </Dialogo>
    </>
  );
}

type Panel = 'aprobar' | 'rechazar' | 'reabrir' | 'eliminar' | 'editar' | null;

/**
 * Acciones de una solicitud. Solo se ofrecen las que Laravel aceptaría para esta persona (`puede_*`); aun así Laravel las
 * vuelve a comprobar bajo bloqueo y un 409 (ya resuelta por otra persona) se muestra tal cual y relee la lista.
 */
export function AccionesAprobacion({ fila, tono, autoridad }: { fila: AprobacionFila; tono: TonoUi; autoridad: string }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [panel, setPanel] = useState<Panel>(null);
  const [motivo, setMotivo] = useState('');
  const [comentarioAprobar, setComentarioAprobar] = useState('');
  const [edicion, setEdicion] = useState({
    titulo: fila.titulo,
    descripcion: fila.descripcion ?? '',
    referencia: fila.referencia ?? '',
    comentario: fila.comentario ?? '',
  });
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [historial, setHistorial] = useState(false);

  const cerrar = () => {
    setPanel(null);
    setMotivo('');
    setError(null);
  };

  function abrirEdicion() {
    setError(null);
    setEdicion({
      titulo: fila.titulo,
      descripcion: fila.descripcion ?? '',
      referencia: fila.referencia ?? '',
      comentario: fila.comentario ?? '',
    });
    setPanel('editar');
  }

  async function operar(ruta: string, metodo: 'POST' | 'PUT' | 'DELETE', cuerpo?: Record<string, string | null>) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`compartido/aprobaciones/${fila.id}${ruta}`, metodo, cuerpo);
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 409) router.refresh();
      return;
    }
    cerrar();
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const pendiente = fila.estado === 'pendiente';

  return (
    <div className="mt-4 space-y-3 border-t border-panel-border pt-4">
      {fila.historial.length > 0 ? (
        <div>
          <button type="button" onClick={() => setHistorial((v) => !v)} aria-expanded={historial} className="text-xs font-semibold text-panel-text underline-offset-2 hover:underline">
            {historial ? 'Ocultar historial' : `Ver historial de decisiones (${fila.historial.length})`}
          </button>
          {historial ? <ol className="mt-2 divide-y divide-panel-border rounded-lg border border-panel-border bg-panel-surface text-xs">{fila.historial.map((e, i) => <EventoFila key={`${e.fecha_local}-${i}`} evento={e} />)}</ol> : null}
        </div>
      ) : null}

      <div className="flex flex-wrap items-center gap-2">
        {pendiente && fila.es_propia ? (
          <span className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">
            <Lock className="h-3.5 w-3.5" aria-hidden="true" /> Tu solicitud espera la decisión de {fila.rol_autorizado === null ? 'la autoridad competente' : autoridad}.
          </span>
        ) : null}
        {pendiente && fila.puede_resolver ? (
          <>
            <Boton tono={tono} variante="secundario" icono={<CircleCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => setPanel('aprobar')}>Aprobar</Boton>
            <Boton tono={tono} variante="secundario" icono={<CircleX className="h-4 w-4" aria-hidden="true" />} onClick={() => setPanel('rechazar')}>Rechazar</Boton>
          </>
        ) : null}
        {pendiente && !fila.es_propia && !fila.puede_resolver ? (
          <span className="text-xs text-panel-muted">
            {fila.rol_autorizado === null ? 'Tipo sin autoridad asignada: nadie puede resolverla hasta que se reclasifique.' : `Resolución reservada a ${autoridad}.`}
          </span>
        ) : null}
        {!pendiente && fila.puede_reabrir ? (
          <Boton tono={tono} variante="secundario" icono={<RotateCcw className="h-4 w-4" aria-hidden="true" />} onClick={() => setPanel('reabrir')}>Reabrir solicitud</Boton>
        ) : null}
        {!pendiente ? <span className="text-xs italic text-panel-muted sm:ml-auto">Decisión registrada.</span> : null}
        <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
          {fila.puede_editar ? (
            <Boton tono={tono} variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={abrirEdicion}>Editar</Boton>
          ) : null}
          {fila.puede_eliminar ? (
            <Boton tono={tono} variante="secundario" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setPanel('eliminar')}>Eliminar</Boton>
          ) : null}
          {fila.referencia ? (
            <Boton
              tono={tono}
              variante="fantasma"
              icono={<Copy className="h-4 w-4" aria-hidden="true" />}
              onClick={() => {
                void navigator.clipboard?.writeText(fila.referencia ?? '').then(() => mostrar('exito', 'Referencia copiada.'));
              }}
            >
              Copiar ref.
            </Boton>
          ) : null}
        </div>
      </div>

      <Dialogo abierto={panel === 'editar'} lado="derecha" titulo="Editar solicitud" descripcion="Solo se actualizan las solicitudes pendientes. El tipo no puede cambiarse." onCerrar={cerrar} bloqueado={ocupado}>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            const opcional = (valor: string) => (valor.trim() === '' ? null : valor.trim());
            void operar('', 'PUT', {
              titulo: edicion.titulo.trim(),
              tipo: fila.tipo,
              descripcion: opcional(edicion.descripcion),
              referencia: opcional(edicion.referencia),
              comentario: opcional(edicion.comentario),
            });
          }}
          noValidate
          className="space-y-4"
        >
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <Campo tono={tono} etiqueta="Título" maxLength={255} value={edicion.titulo} onChange={(e) => setEdicion((a) => ({ ...a, titulo: e.target.value }))} error={errorDeCampo(error, 'titulo')} required autoFocus />
          <CampoArea tono={tono} etiqueta="Descripción (opcional)" maxLength={5000} rows={4} value={edicion.descripcion} onChange={(e) => setEdicion((a) => ({ ...a, descripcion: e.target.value }))} error={errorDeCampo(error, 'descripcion')} />
          <Campo tono={tono} etiqueta="Referencia (opcional)" maxLength={255} value={edicion.referencia} onChange={(e) => setEdicion((a) => ({ ...a, referencia: e.target.value }))} error={errorDeCampo(error, 'referencia')} />
          <CampoArea tono={tono} etiqueta="Comentario (opcional)" maxLength={2000} rows={2} value={edicion.comentario} onChange={(e) => setEdicion((a) => ({ ...a, comentario: e.target.value }))} error={errorDeCampo(error, 'comentario')} />
          <div className="pie-acciones">
            <Boton tono={tono} variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono={tono} type="submit" cargando={ocupado}>Guardar cambios</Boton>
          </div>
        </form>
      </Dialogo>

      <Dialogo abierto={panel === 'aprobar'} titulo="Aprobar solicitud" descripcion={fila.titulo} onCerrar={() => { setComentarioAprobar(''); cerrar(); }} bloqueado={ocupado} ancho="sm">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            const limpio = comentarioAprobar.trim();
            void operar('/aprobar', 'POST', limpio === '' ? {} : { comentario: limpio });
          }}
          noValidate
          className="space-y-4"
        >
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <p className="text-sm text-panel-muted">¿Confirmas que deseas aprobar esta solicitud? La decisión quedará registrada.</p>
          <CampoArea
            tono={tono}
            etiqueta="Comentario (opcional)"
            rows={2}
            maxLength={1000}
            value={comentarioAprobar}
            onChange={(e) => setComentarioAprobar(e.target.value)}
            error={errorDeCampo(error, 'comentario')}
            autoFocus
          />
          <div className="pie-acciones">
            <Boton tono={tono} variante="secundario" onClick={() => { setComentarioAprobar(''); cerrar(); }} disabled={ocupado}>Cancelar</Boton>
            <Boton tono={tono} type="submit" cargando={ocupado}>Aprobar</Boton>
          </div>
        </form>
      </Dialogo>
      <DialogoConfirmacion
        abierto={panel === 'eliminar'}
        titulo="Eliminar solicitud"
        texto="¿Deseas eliminar esta solicitud pendiente? Quedará constancia en la auditoría."
        confirmar="Eliminar"
        peligro
        tono={tono}
        cargando={ocupado}
        error={error?.message ?? null}
        onConfirmar={() => void operar('', 'DELETE')}
        onCerrar={cerrar}
      />
      <Dialogo abierto={panel === 'rechazar' || panel === 'reabrir'} titulo={panel === 'rechazar' ? 'Rechazar solicitud' : 'Reabrir solicitud'} descripcion={fila.titulo} onCerrar={cerrar} bloqueado={ocupado} ancho="sm">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void operar(panel === 'rechazar' ? '/rechazar' : '/reabrir', 'POST', { motivo: motivo.trim() });
          }}
          noValidate
          className="space-y-4"
        >
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <CampoArea
            tono={tono}
            etiqueta={panel === 'rechazar' ? 'Motivo del rechazo' : 'Motivo de la reapertura'}
            rows={3}
            minLength={3}
            maxLength={1000}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            error={errorDeCampo(error, 'motivo')}
            ayuda={panel === 'rechazar' ? 'El solicitante lo verá.' : 'La decisión anterior se conserva en el historial.'}
            required
          />
          <div className="pie-acciones">
            <Boton tono={tono} variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono={tono} variante={panel === 'rechazar' ? 'peligro' : 'primario'} type="submit" cargando={ocupado}>{panel === 'rechazar' ? 'Rechazar' : 'Reabrir'}</Boton>
          </div>
        </form>
      </Dialogo>
    </div>
  );
}

function EventoFila({ evento }: { evento: EventoAprobacion }) {
  return (
    <li className="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0">
        <span className="font-semibold text-panel-text">{TITULOS_EVENTO[evento.accion] ?? evento.accion}</span>{' '}
        <span className="text-panel-muted">por {evento.usuario ?? 'usuario eliminado'}</span>
        {evento.estado_anterior && evento.estado_nuevo && evento.estado_anterior !== evento.estado_nuevo ? (
          <span className="text-panel-muted"> ({evento.estado_anterior} → {evento.estado_nuevo})</span>
        ) : null}
        {evento.motivo ? <p className="mt-0.5 break-words text-amber-800">Motivo: «{evento.motivo}»</p> : evento.comentario ? <p className="mt-0.5 break-words text-panel-muted">Comentario: «{evento.comentario}»</p> : null}
      </div>
      <span className="whitespace-nowrap text-[11px] text-panel-muted">{evento.fecha_local}</span>
    </li>
  );
}
