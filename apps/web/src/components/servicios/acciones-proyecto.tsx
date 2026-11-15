'use client';

import { CircleX, Pause, Pencil, Play, Plus, Trash2, UserCheck, CheckCheck } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { useOpciones } from '@/components/servicios/acciones-prospecto';
import type { EstadoProyecto, OportunidadResumen, ProyectoDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { fecha } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const LISTADO = '/admin/servicios/proyectos';
type Respuesta = { message: string; data: ProyectoDetalle };

interface ClienteOpcion {
  identificador: string;
  nombre: string;
  documento: string | null;
  documento_tipo: string | null;
}

const opcional = (texto: string) => (texto.trim() === '' ? null : texto.trim());

interface DatosProyecto {
  nombre: string;
  descripcion: string;
  fecha_inicio: string;
  fecha_fin_estimada: string;
  presupuesto: string;
}

function FormularioProyecto({ proyecto, onGuardado, onCancelar, onOcupado }: {
  proyecto?: ProyectoDetalle;
  onGuardado: (mensaje: string, identificador: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
}) {
  const { ejecutar } = useOperacionPanel();
  const puedeVerClientes = usePuede('clientes.ver');
  const puedeVerPipeline = usePuede('pipeline.ver');
  const [datos, setDatos] = useState<DatosProyecto>({
    nombre: proyecto?.nombre ?? '',
    descripcion: proyecto?.descripcion ?? '',
    fecha_inicio: proyecto?.fecha_inicio ?? '',
    fecha_fin_estimada: proyecto?.fecha_fin_estimada ?? '',
    presupuesto: proyecto?.presupuesto ?? '',
  });
  const [origen, setOrigen] = useState<'cliente' | 'oportunidad'>(puedeVerClientes ? 'cliente' : 'oportunidad');
  const [busqueda, setBusqueda] = useState('');
  const [clientes, setClientes] = useState<ClienteOpcion[] | null>(null);
  const [cliente, setCliente] = useState('');
  const [ganadas, setGanadas] = useState<OportunidadResumen[] | null>(null);
  const [oportunidad, setOportunidad] = useState('');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const cambiar = (campo: keyof DatosProyecto) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  useEffect(() => {
    if (proyecto || origen !== 'oportunidad' || !puedeVerPipeline) return;
    let vigente = true;
    void ejecutar<{ data: OportunidadResumen[] }>('servicios/oportunidades?etapa=ganada').then((resultado) => {
      if (vigente && resultado.ok) setGanadas(resultado.datos.data);
    });
    return () => {
      vigente = false;
    };
  }, [proyecto, origen, puedeVerPipeline, ejecutar]);

  async function buscarClientes() {
    setGuardando(true);
    setError(null);
    const consulta = new URLSearchParams({ busqueda: busqueda.trim(), estado: 'activo' }).toString();
    const resultado = await ejecutar<{ data: ClienteOpcion[] }>(`servicios/clientes?${consulta}`);
    setGuardando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setCliente('');
    setClientes(resultado.datos.data);
  }

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado(true);
    setError(null);
    const cuerpo: Record<string, unknown> = {
      nombre: opcional(datos.nombre),
      descripcion: opcional(datos.descripcion),
      fecha_inicio: opcional(datos.fecha_inicio),
      fecha_fin_estimada: opcional(datos.fecha_fin_estimada),
      presupuesto: opcional(datos.presupuesto),
    };
    const resultado = proyecto
      ? await ejecutar<Respuesta>(`servicios/proyectos/${proyecto.identificador}`, 'PUT', { ...cuerpo, _revision: proyecto.revision })
      : await ejecutar<Respuesta>('servicios/proyectos', 'POST', origen === 'cliente' ? { ...cuerpo, cliente } : { ...cuerpo, oportunidad });
    setGuardando(false);
    onOcupado(false);
    if (resultado.ok) onGuardado(resultado.datos.message, resultado.datos.data.identificador);
    else setError(resultado.error);
  }

  const err = (campo: string) => errorDeCampo(error, campo);
  const sinOrigen = !proyecto && (origen === 'cliente' ? cliente === '' : oportunidad === '');

  return (
    <form onSubmit={(evento) => void enviar(evento)} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      {proyecto ? null : (
        <section aria-labelledby="titulo-proyecto-origen" className="space-y-3">
          <h3 id="titulo-proyecto-origen" className="text-sm font-semibold text-panel-text">¿De dónde nace el proyecto?</h3>
          <CampoSeleccion tono="servicios" etiqueta="Origen" value={origen} onChange={(evento) => setOrigen(evento.target.value as 'cliente' | 'oportunidad')} opciones={{ ...(puedeVerClientes ? { cliente: 'De un cliente' } : {}), ...(puedeVerPipeline ? { oportunidad: 'De una oportunidad ganada' } : {}) }} />
          {origen === 'cliente' ? (
            <>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <Campo tono="servicios" etiqueta="Buscar cliente por nombre, correo o documento" value={busqueda} onChange={(evento) => setBusqueda(evento.target.value)} maxLength={200} autoComplete="off" className="flex-1" onKeyDown={(evento) => { if (evento.key === 'Enter') { evento.preventDefault(); void buscarClientes(); } }} />
                <Boton tono="servicios" variante="secundario" cargando={guardando} onClick={() => void buscarClientes()}>Buscar</Boton>
              </div>
              {clientes === null ? null : clientes.length === 0 ? (
                <p className="text-sm text-panel-muted">Ningún cliente activo coincide.</p>
              ) : (
                <fieldset className="space-y-1">
                  <legend className="sr-only">Clientes</legend>
                  {clientes.map((c) => (
                    <label key={c.identificador} className="flex cursor-pointer items-center gap-2 rounded-md border border-panel-border px-3 py-2 text-sm text-panel-text">
                      <input type="radio" name="cliente-proyecto" value={c.identificador} checked={cliente === c.identificador} onChange={() => setCliente(c.identificador)} className="h-4 w-4 accent-servicios-700" />
                      <span className="font-medium">{c.nombre}</span>
                    </label>
                  ))}
                </fieldset>
              )}
              {err('cliente') ? <p role="alert" className="text-sm text-estado-error">{err('cliente')}</p> : null}
            </>
          ) : ganadas === null ? (
            <p className="text-sm text-panel-muted" aria-busy="true">Cargando oportunidades ganadas…</p>
          ) : ganadas.length === 0 ? (
            <p className="text-sm text-panel-muted">No hay oportunidades ganadas.</p>
          ) : (
            <fieldset className="space-y-1">
              <legend className="sr-only">Oportunidades ganadas</legend>
              {ganadas.map((o) => (
                <label key={o.identificador} className="flex cursor-pointer items-center gap-2 rounded-md border border-panel-border px-3 py-2 text-sm text-panel-text">
                  <input type="radio" name="oportunidad-proyecto" value={o.identificador} checked={oportunidad === o.identificador} onChange={() => setOportunidad(o.identificador)} className="h-4 w-4 accent-servicios-700" />
                  <span className="font-medium">{o.titulo}</span>
                  {o.cliente ? <span className="text-panel-muted">{o.cliente.nombre}</span> : null}
                </label>
              ))}
              <p className="text-xs text-panel-muted">Si la oportunidad ya tiene proyecto, se abre ese mismo. El nombre y el presupuesto vacíos se toman de la oportunidad.</p>
            </fieldset>
          )}
        </section>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo tono="servicios" etiqueta={proyecto || origen === 'cliente' ? 'Nombre' : 'Nombre (opcional)'} value={datos.nombre} onChange={cambiar('nombre')} error={err('nombre')} maxLength={160} className="sm:col-span-2" />
        <CampoArea tono="servicios" etiqueta="Descripción (opcional)" value={datos.descripcion} onChange={cambiar('descripcion')} error={err('descripcion')} rows={3} maxLength={5000} className="sm:col-span-2" />
        <Campo tono="servicios" etiqueta="Inicio (opcional)" type="date" value={datos.fecha_inicio} onChange={cambiar('fecha_inicio')} error={err('fecha_inicio')} />
        <Campo tono="servicios" etiqueta="Fin estimado (opcional)" type="date" value={datos.fecha_fin_estimada} onChange={cambiar('fecha_fin_estimada')} error={err('fecha_fin_estimada')} />
        <Campo tono="servicios" etiqueta="Presupuesto (S/)" type="number" inputMode="decimal" min="0" step="0.01" value={datos.presupuesto} onChange={cambiar('presupuesto')} error={err('presupuesto')} />
      </div>
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono="servicios" variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono="servicios" type="submit" cargando={guardando} disabled={sinOrigen}>{proyecto ? 'Guardar cambios' : 'Registrar proyecto'}</Boton>
      </div>
    </form>
  );
}

/** «Nuevo proyecto» del listado. */
export function BotonNuevoProyecto() {
  const puede = usePuede('proyectos.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono="servicios" icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nuevo proyecto</Boton>
      <Dialogo abierto={abierto} titulo="Registrar proyecto" descripcion="Un trabajo para un cliente, con sus hitos." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioProyecto
          onOcupado={setOcupado}
          onCancelar={() => setAbierto(false)}
          onGuardado={(mensaje, identificador) => {
            setAbierto(false);
            mostrar('exito', mensaje);
            router.push(`${LISTADO}/${identificador}`);
          }}
        />
      </Dialogo>
    </>
  );
}

/** Crea (o abre) el proyecto de una oportunidad ganada desde la ficha de la oportunidad. */
export function BotonProyectoDeOportunidad({ oportunidad }: { oportunidad: string }) {
  const puede = usePuede('proyectos.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  if (!puede) return null;

  async function crear() {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>('servicios/proyectos', 'POST', { oportunidad });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    mostrar('exito', resultado.datos.message);
    router.push(`${LISTADO}/${resultado.datos.data.identificador}`);
  }

  return (
    <>
      <Boton tono="servicios" cargando={ocupado} onClick={() => void crear()}>Crear proyecto</Boton>
      {error ? <p role="alert" className="basis-full text-sm text-estado-error">{error.message}</p> : null}
    </>
  );
}

type Modo = 'editar' | 'responsable' | null;

const ACCIONES_ESTADO: Record<EstadoProyecto, { texto: string; icono: 'play' | 'pause' | 'check' | 'cancel'; secundaria: boolean }> = {
  planificado: { texto: 'Planificado', icono: 'play', secundaria: true },
  en_curso: { texto: 'Iniciar', icono: 'play', secundaria: false },
  pausado: { texto: 'Pausar', icono: 'pause', secundaria: true },
  completado: { texto: 'Marcar como completado', icono: 'check', secundaria: false },
  cancelado: { texto: 'Cancelar proyecto', icono: 'cancel', secundaria: true },
};

/** Acciones del detalle: cambios de estado permitidos, responsable y edición. */
export function AccionesProyecto({ proyecto, transiciones }: { proyecto: ProyectoDetalle; transiciones: readonly EstadoProyecto[] }) {
  const puede = usePuede('proyectos.editar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [modo, setModo] = useState<Modo>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [responsable, setResponsable] = useState('');
  const [confirmar, setConfirmar] = useState(false);
  const responsables = useOpciones('servicios/proyectos/opciones/responsables', modo === 'responsable');

  if (!puede) return null;

  const activo = transiciones.length > 0;
  const cerrar = () => setModo(null);

  async function operar(ruta: string, cuerpo: Record<string, unknown>, alCerrar: boolean) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/proyectos/${proyecto.identificador}/${ruta}`, 'POST', { ...cuerpo, _revision: proyecto.revision });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      // La revisión quedó obsoleta: se relee para que el siguiente intento use la vigente.
      if (resultado.estado === 412) router.refresh();
      return;
    }
    if (alCerrar) cerrar();
    setConfirmar(false);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const icono = (clave: 'play' | 'pause' | 'check' | 'cancel') => {
    const clase = 'h-4 w-4';
    if (clave === 'pause') return <Pause className={clase} aria-hidden="true" />;
    if (clave === 'check') return <CheckCheck className={clase} aria-hidden="true" />;
    if (clave === 'cancel') return <CircleX className={clase} aria-hidden="true" />;
    return <Play className={clase} aria-hidden="true" />;
  };

  return (
    <>
      {transiciones.map((estado) => {
        const accion = ACCIONES_ESTADO[estado];
        const texto = estado === 'en_curso' && proyecto.estado === 'pausado' ? 'Reanudar' : accion.texto;
        return (
          <Boton
            key={estado}
            tono="servicios"
            variante={accion.secundaria ? 'secundario' : 'primario'}
            icono={icono(accion.icono)}
            cargando={ocupado}
            onClick={() => (estado === 'cancelado' ? setConfirmar(true) : void operar('estado', { estado }, false))}
          >
            {texto}
          </Boton>
        );
      })}
      <Boton tono="servicios" variante="secundario" icono={<UserCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setResponsable(proyecto.responsable === null ? '' : String(proyecto.responsable.id)); setModo('responsable'); }}>Responsable</Boton>
      {activo ? <Boton tono="servicios" variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setModo('editar'); }}>Editar</Boton> : null}
      {error && modo === null && !confirmar ? <p role="alert" className="basis-full text-sm text-estado-error">{error.message}</p> : null}

      <Dialogo abierto={modo === 'editar'} titulo="Editar proyecto" descripcion={proyecto.codigo} onCerrar={cerrar} bloqueado={ocupado}>
        <FormularioProyecto
          proyecto={proyecto}
          onOcupado={setOcupado}
          onCancelar={cerrar}
          onGuardado={(mensaje) => {
            cerrar();
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <Dialogo abierto={modo === 'responsable'} titulo="Asignar responsable" descripcion={proyecto.codigo} onCerrar={cerrar} bloqueado={ocupado} ancho="sm">
        <form onSubmit={(evento) => { evento.preventDefault(); void operar('responsable', { responsable_id: responsable === '' ? null : Number(responsable) }, true); }} noValidate className="space-y-4">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'responsable_id') ?? error.message}</Alerta> : null}
          <CampoSeleccion tono="servicios" etiqueta="Responsable" value={responsable} onChange={(evento) => setResponsable(evento.target.value)} opciones={{ '': 'Sin asignar', ...Object.fromEntries((responsables ?? []).map((o) => [o.valor, o.texto])) }} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Guardar</Boton>
          </div>
        </form>
      </Dialogo>

      <Dialogo abierto={confirmar} titulo="Cancelar proyecto" descripcion={proyecto.codigo} onCerrar={() => setConfirmar(false)} bloqueado={ocupado} ancho="sm">
        <div className="space-y-4">
          {error ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <p className="text-sm text-panel-text">Un proyecto cancelado no se puede editar ni reabrir. ¿Quieres cancelarlo?</p>
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setConfirmar(false)} disabled={ocupado}>Volver</Boton>
            <Boton tono="servicios" cargando={ocupado} onClick={() => void operar('estado', { estado: 'cancelado' }, false)}>Cancelar proyecto</Boton>
          </div>
        </div>
      </Dialogo>
    </>
  );
}

/** Hitos del proyecto: marcar, agregar, renombrar y quitar. Cada cambio avanza la revisión del proyecto. */
export function HitosProyecto({ proyecto, editable }: { proyecto: ProyectoDetalle; editable: boolean }) {
  const puede = usePuede('proyectos.editar') && editable;
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [nombre, setNombre] = useState('');
  const [objetivo, setObjetivo] = useState('');
  const [editando, setEditando] = useState<{ identificador: string; nombre: string; fecha: string } | null>(null);

  async function llamar(ruta: string, metodo: 'POST' | 'PUT', cuerpo: Record<string, unknown>): Promise<boolean> {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/proyectos/${proyecto.identificador}/${ruta}`, metodo, { ...cuerpo, _revision: proyecto.revision });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 412) router.refresh();
      return false;
    }
    mostrar('exito', resultado.datos.message);
    router.refresh();
    return true;
  }

  async function agregar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (await llamar('hitos', 'POST', { nombre: nombre.trim(), fecha_objetivo: opcional(objetivo) })) {
      setNombre('');
      setObjetivo('');
    }
  }

  async function guardarEdicion(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (editando && (await llamar(`hitos/${editando.identificador}`, 'PUT', { nombre: editando.nombre.trim(), fecha_objetivo: opcional(editando.fecha) }))) setEditando(null);
  }

  return (
    <div className="space-y-4">
      {error ? <Alerta tipo="error">{errorDeCampo(error, 'nombre') ?? error.message}</Alerta> : null}
      {proyecto.hitos_detalle.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay hitos.</p>
      ) : (
        <ul className="divide-y divide-panel-border rounded-lg border border-panel-border">
          {proyecto.hitos_detalle.map((hito) => (
            <li key={hito.identificador} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
              <label className="flex flex-1 items-center gap-2">
                <input
                  type="checkbox"
                  checked={hito.completado_en !== null}
                  disabled={!puede || ocupado}
                  onChange={(evento) => void llamar(`hitos/${hito.identificador}/completar`, 'POST', { completado: evento.target.checked })}
                  className="h-4 w-4 accent-servicios-700"
                />
                <span className={hito.completado_en ? 'text-panel-muted line-through' : 'font-medium text-panel-text'}>{hito.nombre}</span>
              </label>
              <span className="text-xs text-panel-muted">{hito.completado_en ? `Completado ${fecha(hito.completado_en)}` : hito.fecha_objetivo ? `Objetivo ${fecha(hito.fecha_objetivo)}` : 'Sin fecha'}</span>
              {puede ? (
                <span className="flex gap-1">
                  <Boton tono="servicios" variante="secundario" className="px-2 py-1" aria-label={`Editar el hito ${hito.nombre}`} icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando({ identificador: hito.identificador, nombre: hito.nombre, fecha: hito.fecha_objetivo ?? '' })} />
                  <Boton tono="servicios" variante="secundario" className="px-2 py-1" aria-label={`Quitar el hito ${hito.nombre}`} icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} disabled={ocupado} onClick={() => void llamar(`hitos/${hito.identificador}/eliminar`, 'POST', {})} />
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {puede ? (
        <form onSubmit={(evento) => void agregar(evento)} noValidate className="flex flex-col gap-2 sm:flex-row sm:items-end">
          <Campo tono="servicios" etiqueta="Nuevo hito" value={nombre} onChange={(evento) => setNombre(evento.target.value)} maxLength={160} className="flex-1" />
          <Campo tono="servicios" etiqueta="Fecha objetivo (opcional)" type="date" value={objetivo} onChange={(evento) => setObjetivo(evento.target.value)} />
          <Boton tono="servicios" type="submit" cargando={ocupado} disabled={nombre.trim() === ''} icono={<Plus className="h-4 w-4" aria-hidden="true" />}>Agregar</Boton>
        </form>
      ) : null}
      <Dialogo abierto={editando !== null} titulo="Editar hito" onCerrar={() => setEditando(null)} bloqueado={ocupado} ancho="sm">
        {editando ? (
          <form onSubmit={(evento) => void guardarEdicion(evento)} noValidate className="space-y-4">
            {error ? <Alerta tipo="error">{errorDeCampo(error, 'nombre') ?? error.message}</Alerta> : null}
            <Campo tono="servicios" etiqueta="Nombre" value={editando.nombre} onChange={(evento) => setEditando({ ...editando, nombre: evento.target.value })} maxLength={160} required />
            <Campo tono="servicios" etiqueta="Fecha objetivo (opcional)" type="date" value={editando.fecha} onChange={(evento) => setEditando({ ...editando, fecha: evento.target.value })} />
            <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
              <Boton tono="servicios" variante="secundario" onClick={() => setEditando(null)} disabled={ocupado}>Cancelar</Boton>
              <Boton tono="servicios" type="submit" cargando={ocupado}>Guardar</Boton>
            </div>
          </form>
        ) : null}
      </Dialogo>
    </div>
  );
}
