'use client';

import { ClipboardPlus, Link2, MessageSquarePlus, Pencil, Search, UserCheck, UserPlus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { CotizacionDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

const LISTADO = '/admin/servicios/cotizaciones';
type Respuesta = { message: string; data: CotizacionDetalle };

/** UUID de la intención: se genera al abrir el formulario y se reutiliza en los reintentos (idempotencia). */
function nuevaClave(): string {
  return crypto.randomUUID();
}

/** Opciones de un selector cargadas del servidor al abrir el diálogo. */
function useOpciones(ruta: string, activo: boolean): { valor: string; texto: string }[] | null {
  const { ejecutar } = useOperacionPanel();
  const [opciones, setOpciones] = useState<{ valor: string; texto: string }[] | null>(null);

  useEffect(() => {
    if (!activo) return;
    let vigente = true;
    void ejecutar<{ data: { id?: number; valor?: string; texto: string }[] }>(ruta).then((resultado) => {
      if (vigente && resultado.ok) setOpciones(resultado.datos.data.map((o) => ({ valor: String(o.valor ?? o.id), texto: o.texto })));
    });
    return () => {
      vigente = false;
    };
  }, [activo, ejecutar, ruta]);

  return opciones;
}

interface DatosSolicitud {
  contacto_nombre: string;
  contacto_email: string;
  contacto_telefono: string;
  necesidad_cliente: string;
  servicio_uuid: string;
}

function FormularioSolicitud({ cotizacion, onGuardado, onCancelar, onOcupado }: {
  cotizacion?: CotizacionDetalle;
  onGuardado: (mensaje: string, identificador: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
}) {
  const { ejecutar } = useOperacionPanel();
  const servicios = useOpciones('servicios/cotizaciones/opciones/servicios', cotizacion === undefined || cotizacion.servicio_uuid === null);
  const [clave] = useState(nuevaClave);
  const [datos, setDatos] = useState<DatosSolicitud>({
    contacto_nombre: cotizacion?.contacto.nombre ?? '',
    contacto_email: cotizacion?.contacto.email ?? '',
    contacto_telefono: cotizacion?.contacto.telefono ?? '',
    necesidad_cliente: cotizacion?.necesidad ?? '',
    servicio_uuid: cotizacion?.servicio_uuid ?? '',
  });
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const cambiar = (campo: keyof DatosSolicitud) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado(true);
    setError(null);
    const cuerpo = {
      contacto_nombre: datos.contacto_nombre.trim(),
      necesidad_cliente: datos.necesidad_cliente.trim(),
      contacto_email: datos.contacto_email.trim() || null,
      contacto_telefono: datos.contacto_telefono.trim() || null,
      // La asociación histórica con un servicio no se sustituye: solo se envía mientras no exista.
      ...(cotizacion?.servicio_uuid ? {} : { servicio_uuid: datos.servicio_uuid || null }),
    };
    const resultado = cotizacion
      ? await ejecutar<Respuesta>(`servicios/cotizaciones/${cotizacion.identificador}`, 'PUT', { ...cuerpo, _revision: cotizacion.revision })
      : await ejecutar<Respuesta>('servicios/cotizaciones', 'POST', { ...cuerpo, _clave: clave });
    setGuardando(false);
    onOcupado(false);
    if (resultado.ok) onGuardado(resultado.datos.message, resultado.datos.data.identificador);
    else setError(resultado.error);
  }

  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo tono="servicios" etiqueta="Nombre del solicitante" value={datos.contacto_nombre} onChange={cambiar('contacto_nombre')} error={err('contacto_nombre')} required maxLength={120} className="sm:col-span-2" />
        <Campo tono="servicios" etiqueta="Correo" type="email" value={datos.contacto_email} onChange={cambiar('contacto_email')} error={err('contacto_email')} maxLength={254} autoComplete="off" />
        <Campo tono="servicios" etiqueta="Teléfono" type="tel" value={datos.contacto_telefono} onChange={cambiar('contacto_telefono')} error={err('contacto_telefono')} maxLength={30} autoComplete="off" ayuda="Indica al menos correo o teléfono." />
        {cotizacion?.servicio_uuid ? null : (
          <CampoSeleccion tono="servicios" etiqueta="Servicio (opcional)" value={datos.servicio_uuid} onChange={cambiar('servicio_uuid')} error={err('servicio_uuid')} opciones={{ '': 'Sin servicio', ...Object.fromEntries((servicios ?? []).map((s) => [s.valor, s.texto])) }} className="sm:col-span-2" />
        )}
        <CampoArea tono="servicios" etiqueta="Necesidad del cliente" value={datos.necesidad_cliente} onChange={cambiar('necesidad_cliente')} error={err('necesidad_cliente')} required rows={5} maxLength={5000} className="sm:col-span-2" />
      </div>
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono="servicios" variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono="servicios" type="submit" cargando={guardando}>{cotizacion ? 'Guardar cambios' : 'Registrar solicitud'}</Boton>
      </div>
    </form>
  );
}

/** «Nueva solicitud» de la bandeja: alta manual (por teléfono, presencial…), con origen interno. */
export function BotonNuevaSolicitud() {
  const puede = usePuede('cotizaciones.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono="servicios" icono={<ClipboardPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nueva solicitud</Boton>
      <Dialogo abierto={abierto} titulo="Registrar solicitud de cotización" descripcion="Para solicitudes recibidas por otros medios. Nace en estado «Recibida» y sin responsable." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioSolicitud
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

type Modo = 'editar' | 'responsable' | 'seguimiento' | 'cliente' | null;

interface ClienteOpcion {
  identificador: string;
  nombre: string;
  documento: string | null;
  documento_tipo: string | null;
}

/** Acciones del expediente: editar datos, pasar a revisión, asignar responsable y registrar seguimientos. */
export function AccionesCotizacion({ cotizacion }: { cotizacion: CotizacionDetalle }) {
  const puedeEditar = usePuede('cotizaciones.editar');
  const puedeGestionar = usePuede('cotizaciones.gestionar');
  const puedeVerClientes = usePuede('clientes.ver');
  const puedeCrearClientes = usePuede('clientes.crear');
  const puedeCrearProspectos = usePuede('prospectos.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [modo, setModo] = useState<Modo>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [responsable, setResponsable] = useState('');
  const [clave, setClave] = useState(nuevaClave);
  const [seguimiento, setSeguimiento] = useState({ nota: '', proximo: '' });
  const [busquedaCliente, setBusquedaCliente] = useState('');
  const [clientes, setClientes] = useState<ClienteOpcion[] | null>(null);
  const [clienteElegido, setClienteElegido] = useState('');
  const responsables = useOpciones('servicios/cotizaciones/opciones/responsables', modo === 'responsable');

  async function operar(ruta: string, cuerpo: Record<string, unknown>, cerrar = true) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/cotizaciones/${cotizacion.identificador}/${ruta}`, 'POST', { ...cuerpo, _revision: cotizacion.revision });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 412) router.refresh();
      return;
    }
    if (cerrar) setModo(null);
    mostrar('exito', resultado.datos.message);
    setClave(nuevaClave());
    setSeguimiento({ nota: '', proximo: '' });
    router.refresh();
  }

  /** Prospecto a partir de esta solicitud (una solicitud da uno solo: repetirlo lleva al mismo). */
  async function crearProspecto() {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<{ message: string; data: { identificador: string } }>(`servicios/cotizaciones/${cotizacion.identificador}/prospecto`, 'POST', {});
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    mostrar('exito', resultado.datos.message);
    router.push(`/admin/servicios/prospectos/${resultado.datos.data.identificador}`);
  }

  async function buscarClientes(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setOcupado(true);
    setError(null);
    const consulta = new URLSearchParams({ busqueda: busquedaCliente.trim() }).toString();
    const resultado = await ejecutar<{ data: ClienteOpcion[] }>(`servicios/clientes?${consulta}`);
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setClienteElegido('');
    setClientes(resultado.datos.data);
  }

  const abrir = (siguiente: Modo) => {
    setError(null);
    if (siguiente === 'cliente') {
      setBusquedaCliente('');
      setClientes(null);
      setClienteElegido('');
    }
    setResponsable(cotizacion.responsable === null ? '' : String(cotizacion.responsable.id));
    setModo(siguiente);
  };
  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <>
      {puedeGestionar && cotizacion.estado === 'recibida' ? (
        <Boton tono="servicios" variante="secundario" icono={<Search className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void operar('estado', { estado: 'en_revision' }, false)}>
          Pasar a revisión
        </Boton>
      ) : null}
      {puedeGestionar ? (
        <>
          <Boton tono="servicios" variante="secundario" icono={<UserCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('responsable')}>Responsable</Boton>
          <Boton tono="servicios" variante="secundario" icono={<MessageSquarePlus className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('seguimiento')}>Seguimiento</Boton>
        </>
      ) : null}
      {puedeGestionar && cotizacion.cliente === null && (puedeVerClientes || puedeCrearClientes) ? (
        <Boton tono="servicios" variante="secundario" icono={<Link2 className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('cliente')}>Cliente</Boton>
      ) : null}
      {puedeCrearProspectos ? (
        <Boton tono="servicios" variante="secundario" icono={<UserPlus className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void crearProspecto()}>Crear prospecto</Boton>
      ) : null}
      {puedeEditar ? (
        <Boton tono="servicios" variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('editar')}>Editar</Boton>
      ) : null}
      {error && modo === null ? <p role="alert" className="basis-full text-sm text-estado-error">{error.message}</p> : null}

      <Dialogo abierto={modo === 'editar'} titulo="Editar solicitud" descripcion={cotizacion.codigo} onCerrar={() => setModo(null)} bloqueado={ocupado}>
        <FormularioSolicitud
          cotizacion={cotizacion}
          onOcupado={setOcupado}
          onCancelar={() => setModo(null)}
          onGuardado={(mensaje) => {
            setModo(null);
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <Dialogo abierto={modo === 'responsable'} titulo="Asignar responsable" descripcion={cotizacion.codigo} onCerrar={() => setModo(null)} bloqueado={ocupado} ancho="sm">
        <form onSubmit={(evento) => { evento.preventDefault(); void operar('responsable', { responsable_id: responsable === '' ? null : Number(responsable) }); }} noValidate className="space-y-4">
          {error ? <Alerta tipo="error">{err('responsable_id') ?? error.message}</Alerta> : null}
          <CampoSeleccion tono="servicios" etiqueta="Responsable" value={responsable} onChange={(evento) => setResponsable(evento.target.value)} opciones={{ '': 'Sin asignar', ...Object.fromEntries((responsables ?? []).map((o) => [o.valor, o.texto])) }} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setModo(null)} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Guardar</Boton>
          </div>
        </form>
      </Dialogo>

      <Dialogo abierto={modo === 'cliente'} titulo="Vincular cliente" descripcion={cotizacion.codigo} onCerrar={() => setModo(null)} bloqueado={ocupado}>
        <div className="space-y-5">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'cliente') ?? error.message}</Alerta> : null}
          {puedeVerClientes ? (
            <section aria-labelledby="titulo-vincular-existente" className="space-y-3">
              <h3 id="titulo-vincular-existente" className="text-sm font-semibold text-panel-text">Cliente existente</h3>
              <form onSubmit={(evento) => void buscarClientes(evento)} noValidate className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <Campo tono="servicios" etiqueta="Buscar por nombre, correo o documento" value={busquedaCliente} onChange={(evento) => setBusquedaCliente(evento.target.value)} maxLength={200} autoComplete="off" className="flex-1" />
                <Boton tono="servicios" variante="secundario" type="submit" cargando={ocupado}>Buscar</Boton>
              </form>
              {clientes === null ? null : clientes.length === 0 ? (
                <p className="text-sm text-panel-muted">Ningún cliente coincide.</p>
              ) : (
                <fieldset className="space-y-1">
                  <legend className="sr-only">Resultados</legend>
                  {clientes.map((c) => (
                    <label key={c.identificador} className="flex cursor-pointer items-center gap-2 rounded-md border border-panel-border px-3 py-2 text-sm text-panel-text">
                      <input type="radio" name="cliente-elegido" value={c.identificador} checked={clienteElegido === c.identificador} onChange={() => setClienteElegido(c.identificador)} className="h-4 w-4 accent-servicios-700" />
                      <span className="font-medium">{c.nombre}</span>
                      {c.documento ? <span className="text-panel-muted">{c.documento_tipo?.toUpperCase()} {c.documento}</span> : null}
                    </label>
                  ))}
                </fieldset>
              )}
              <div className="flex justify-end">
                <Boton tono="servicios" cargando={ocupado} disabled={clienteElegido === ''} onClick={() => void operar('cliente', { cliente: clienteElegido })}>Vincular cliente seleccionado</Boton>
              </div>
            </section>
          ) : null}
          {puedeCrearClientes ? (
            <section aria-labelledby="titulo-vincular-nuevo" className="space-y-2 border-t border-panel-border pt-4">
              <h3 id="titulo-vincular-nuevo" className="text-sm font-semibold text-panel-text">Cliente nuevo</h3>
              <p className="text-sm text-panel-muted">Se crea una persona con el nombre, el correo y el teléfono de esta solicitud. Si ya existe un cliente con ese correo, se te pedirá vincularlo.</p>
              <div className="flex justify-end">
                <Boton tono="servicios" variante="secundario" cargando={ocupado} onClick={() => void operar('cliente', { crear_cliente: true })}>Crear cliente desde esta solicitud</Boton>
              </div>
            </section>
          ) : null}
        </div>
      </Dialogo>

      <Dialogo abierto={modo === 'seguimiento'} titulo="Registrar seguimiento" descripcion={cotizacion.codigo} onCerrar={() => setModo(null)} bloqueado={ocupado}>
        <form
          onSubmit={(evento) => {
            evento.preventDefault();
            void operar('seguimientos', { nota: seguimiento.nota.trim(), proximo_seguimiento: seguimiento.proximo === '' ? null : seguimiento.proximo, _clave: clave });
          }}
          noValidate
          className="space-y-4"
        >
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <CampoArea tono="servicios" etiqueta="Nota" value={seguimiento.nota} onChange={(evento) => setSeguimiento((s) => ({ ...s, nota: evento.target.value }))} error={err('nota')} required rows={4} maxLength={3000} />
          <Campo tono="servicios" etiqueta="Próximo seguimiento (opcional)" type="date" value={seguimiento.proximo} onChange={(evento) => setSeguimiento((s) => ({ ...s, proximo: evento.target.value }))} error={err('proximo_seguimiento')} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setModo(null)} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Registrar</Boton>
          </div>
        </form>
      </Dialogo>
    </>
  );
}
