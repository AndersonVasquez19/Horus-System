'use client';

import { CircleCheck, Lock, MessageSquarePlus, Pencil, Play, Plus, RotateCcw, UserCheck } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import { useOpciones } from '@/components/servicios/acciones-prospecto';
import type { EstadoTicket, TicketDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS, fecha } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const LISTADO = '/admin/servicios/soporte';
type Respuesta = { message: string; data: TicketDetalle };

interface ClienteOpcion {
  identificador: string;
  nombre: string;
  documento: string | null;
  documento_tipo: string | null;
}

const opcional = (texto: string) => (texto.trim() === '' ? null : texto.trim());

interface DatosTicket {
  asunto: string;
  descripcion: string;
  prioridad: string;
  canal: string;
}

function FormularioTicket({ ticket, onGuardado, onCancelar, onOcupado }: {
  ticket?: TicketDetalle;
  onGuardado: (mensaje: string, identificador: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
}) {
  const { ejecutar } = useOperacionPanel();
  const puedeVerClientes = usePuede('clientes.ver');
  const [datos, setDatos] = useState<DatosTicket>({
    asunto: ticket?.asunto ?? '',
    descripcion: ticket?.descripcion ?? '',
    prioridad: ticket?.prioridad ?? 'media',
    canal: ticket?.canal ?? 'telefono',
  });
  const [busqueda, setBusqueda] = useState('');
  const [clientes, setClientes] = useState<ClienteOpcion[] | null>(null);
  const [cliente, setCliente] = useState('');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const cambiar = (campo: keyof DatosTicket) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

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
    const cuerpo = { asunto: datos.asunto.trim(), descripcion: opcional(datos.descripcion), prioridad: datos.prioridad, canal: datos.canal };
    const resultado = ticket
      ? await ejecutar<Respuesta>(`servicios/tickets/${ticket.identificador}`, 'PUT', { ...cuerpo, _revision: ticket.revision })
      : await ejecutar<Respuesta>('servicios/tickets', 'POST', { ...cuerpo, cliente });
    setGuardando(false);
    onOcupado(false);
    if (resultado.ok) onGuardado(resultado.datos.message, resultado.datos.data.identificador);
    else setError(resultado.error);
  }

  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <form onSubmit={(evento) => void enviar(evento)} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      <Alerta tipo="info">No escribas contraseñas ni claves en el ticket ni en sus notas: el sistema no las detecta.</Alerta>
      {ticket ? null : (
        <section aria-labelledby="titulo-ticket-cliente" className="space-y-2">
          <h3 id="titulo-ticket-cliente" className="text-sm font-semibold text-panel-text">Cliente</h3>
          {puedeVerClientes ? (
            <>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <Campo tono="servicios" etiqueta="Buscar por nombre, correo o documento" value={busqueda} onChange={(evento) => setBusqueda(evento.target.value)} maxLength={200} autoComplete="off" className="flex-1" onKeyDown={(evento) => { if (evento.key === 'Enter') { evento.preventDefault(); void buscarClientes(); } }} />
                <Boton tono="servicios" variante="secundario" cargando={guardando} onClick={() => void buscarClientes()}>Buscar</Boton>
              </div>
              {clientes === null ? null : clientes.length === 0 ? (
                <p className="text-sm text-panel-muted">Ningún cliente activo coincide.</p>
              ) : (
                <fieldset className="space-y-1">
                  <legend className="sr-only">Resultados</legend>
                  {clientes.map((c) => (
                    <label key={c.identificador} className="flex cursor-pointer items-center gap-2 rounded-md border border-panel-border px-3 py-2 text-sm text-panel-text">
                      <input type="radio" name="cliente-ticket" value={c.identificador} checked={cliente === c.identificador} onChange={() => setCliente(c.identificador)} className="h-4 w-4 accent-servicios-700" />
                      <span className="font-medium">{c.nombre}</span>
                    </label>
                  ))}
                </fieldset>
              )}
              {err('cliente') ? <p role="alert" className="text-sm text-estado-error">{err('cliente')}</p> : null}
            </>
          ) : (
            <Alerta tipo="info">Necesitas permiso para consultar clientes para elegir uno.</Alerta>
          )}
        </section>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo tono="servicios" etiqueta="Asunto" value={datos.asunto} onChange={cambiar('asunto')} error={err('asunto')} required maxLength={160} className="sm:col-span-2" />
        <CampoArea tono="servicios" etiqueta="Descripción (opcional)" value={datos.descripcion} onChange={cambiar('descripcion')} error={err('descripcion')} rows={4} maxLength={5000} className="sm:col-span-2" />
        <CampoSeleccion tono="servicios" etiqueta="Prioridad" value={datos.prioridad} onChange={cambiar('prioridad')} error={err('prioridad')} opciones={ETIQUETAS.prioridadTicket} ayuda="Define el plazo de atención: crítica 4 h, alta 8 h, media 24 h, baja 72 h." />
        <CampoSeleccion tono="servicios" etiqueta="Canal" value={datos.canal} onChange={cambiar('canal')} error={err('canal')} opciones={ETIQUETAS.canalTicket} />
      </div>
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono="servicios" variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono="servicios" type="submit" cargando={guardando} disabled={!ticket && cliente === ''}>{ticket ? 'Guardar cambios' : 'Registrar ticket'}</Boton>
      </div>
    </form>
  );
}

/** «Nuevo ticket» del listado. */
export function BotonNuevoTicket() {
  const puede = usePuede('soporte.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono="servicios" icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nuevo ticket</Boton>
      <Dialogo abierto={abierto} titulo="Registrar ticket" descripcion="Una solicitud de soporte de un cliente." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioTicket
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

type Modo = 'editar' | 'asignar' | null;

const ICONOS: Record<EstadoTicket, typeof Play> = { abierto: Play, en_progreso: Play, en_espera_cliente: Lock, resuelto: CircleCheck, cerrado: Lock };

/** Texto del botón de cada paso (depende también del estado de partida). */
function textoPaso(desde: EstadoTicket, hacia: EstadoTicket): string {
  if (hacia === 'en_progreso') return desde === 'abierto' ? 'Tomar el ticket' : desde === 'resuelto' ? 'Reabrir' : 'Reanudar';
  if (hacia === 'en_espera_cliente') return 'Esperar al cliente';
  if (hacia === 'resuelto') return 'Marcar como resuelto';
  return 'Cerrar ticket';
}

/** Acciones del detalle: pasos de estado permitidos, asignación y edición. */
export function AccionesTicket({ ticket, transiciones }: { ticket: TicketDetalle; transiciones: readonly EstadoTicket[] }) {
  const puede = usePuede('soporte.gestionar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [modo, setModo] = useState<Modo>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [asignado, setAsignado] = useState('');
  const [confirmarCierre, setConfirmarCierre] = useState(false);
  const asignables = useOpciones('servicios/tickets/opciones/asignados', modo === 'asignar');

  if (!puede) return null;

  const abierto = ticket.estado !== 'cerrado';
  const cerrar = () => setModo(null);

  async function operar(ruta: string, cuerpo: Record<string, unknown>, alCerrar: boolean) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/tickets/${ticket.identificador}/${ruta}`, 'POST', { ...cuerpo, _revision: ticket.revision });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      // La revisión quedó obsoleta: se relee para que el siguiente intento use la vigente.
      if (resultado.estado === 412) router.refresh();
      return;
    }
    if (alCerrar) cerrar();
    setConfirmarCierre(false);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <>
      {transiciones.map((hacia) => {
        const Icono = hacia === 'en_progreso' && ticket.estado === 'resuelto' ? RotateCcw : ICONOS[hacia];
        return (
          <Boton
            key={hacia}
            tono="servicios"
            variante={hacia === 'en_progreso' || hacia === 'resuelto' ? 'primario' : 'secundario'}
            icono={<Icono className="h-4 w-4" aria-hidden="true" />}
            cargando={ocupado}
            onClick={() => (hacia === 'cerrado' ? setConfirmarCierre(true) : void operar('estado', { estado: hacia }, false))}
          >
            {textoPaso(ticket.estado, hacia)}
          </Boton>
        );
      })}
      {abierto ? (
        <>
          <Boton tono="servicios" variante="secundario" icono={<UserCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setAsignado(ticket.asignado === null ? '' : String(ticket.asignado.id)); setModo('asignar'); }}>Asignar</Boton>
          <Boton tono="servicios" variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setModo('editar'); }}>Editar</Boton>
        </>
      ) : null}
      {error && modo === null && !confirmarCierre ? <p role="alert" className="basis-full text-sm text-estado-error">{error.message}</p> : null}

      <Dialogo abierto={modo === 'editar'} titulo="Editar ticket" descripcion={ticket.codigo} onCerrar={cerrar} bloqueado={ocupado}>
        <FormularioTicket
          ticket={ticket}
          onOcupado={setOcupado}
          onCancelar={cerrar}
          onGuardado={(mensaje) => {
            cerrar();
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <Dialogo abierto={modo === 'asignar'} titulo="Asignar ticket" descripcion={ticket.codigo} onCerrar={cerrar} bloqueado={ocupado} ancho="sm">
        <form onSubmit={(evento) => { evento.preventDefault(); void operar('asignar', { asignado_a: asignado === '' ? null : Number(asignado) }, true); }} noValidate className="space-y-4">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'asignado_a') ?? error.message}</Alerta> : null}
          <CampoSeleccion tono="servicios" etiqueta="Atiende" value={asignado} onChange={(evento) => setAsignado(evento.target.value)} opciones={{ '': 'Sin asignar', ...Object.fromEntries((asignables ?? []).map((o) => [o.valor, o.texto])) }} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Guardar</Boton>
          </div>
        </form>
      </Dialogo>

      <Dialogo abierto={confirmarCierre} titulo="Cerrar ticket" descripcion={ticket.codigo} onCerrar={() => setConfirmarCierre(false)} bloqueado={ocupado} ancho="sm">
        <div className="space-y-4">
          {error ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <p className="text-sm text-panel-text">Un ticket cerrado no se puede editar, reabrir ni recibir notas. Si el problema vuelve, se abre otro ticket.</p>
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setConfirmarCierre(false)} disabled={ocupado}>Volver</Boton>
            <Boton tono="servicios" cargando={ocupado} onClick={() => void operar('estado', { estado: 'cerrado' }, false)}>Cerrar ticket</Boton>
          </div>
        </div>
      </Dialogo>
    </>
  );
}

/** Notas del ticket: solo se añaden (para corregir se añade otra). Las internas se distinguen con una insignia. */
export function NotasTicket({ ticket }: { ticket: TicketDetalle }) {
  const puede = usePuede('soporte.gestionar') && ticket.estado !== 'cerrado';
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [texto, setTexto] = useState('');
  const [interna, setInterna] = useState(true);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function agregar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/tickets/${ticket.identificador}/notas`, 'POST', { texto: texto.trim(), interna });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setTexto('');
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <div className="space-y-4">
      {ticket.notas.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay notas.</p>
      ) : (
        <ul className="space-y-3">
          {ticket.notas.map((nota, n) => (
            <li key={`${nota.fecha}-${n}`} className="rounded-lg border border-panel-border p-3">
              <div className="mb-1 flex flex-wrap items-center gap-2 text-xs text-panel-muted">
                <Insignia color={nota.interna ? 'ambar' : 'azul'}>{nota.interna ? 'Interna' : 'Para el cliente'}</Insignia>
                <span>{fecha(nota.fecha)}{nota.autor ? ` · ${nota.autor}` : ''}</span>
              </div>
              <p className="whitespace-pre-line text-sm text-panel-text">{nota.texto}</p>
            </li>
          ))}
        </ul>
      )}
      {puede ? (
        <form onSubmit={(evento) => void agregar(evento)} noValidate className="space-y-3">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'texto') ?? error.message}</Alerta> : null}
          <CampoArea tono="servicios" etiqueta="Nueva nota" value={texto} onChange={(evento) => setTexto(evento.target.value)} rows={3} maxLength={2000} ayuda="No escribas contraseñas ni claves. Una nota no se edita: si te equivocas, añade otra." />
          <label className="flex items-center gap-2 text-sm text-panel-text">
            <input type="checkbox" checked={interna} onChange={(evento) => setInterna(evento.target.checked)} className="h-4 w-4 accent-servicios-700" />
            Nota interna (no se comparte con el cliente)
          </label>
          <div className="flex justify-end">
            <Boton tono="servicios" type="submit" cargando={ocupado} disabled={texto.trim() === ''} icono={<MessageSquarePlus className="h-4 w-4" aria-hidden="true" />}>Agregar nota</Boton>
          </div>
        </form>
      ) : null}
    </div>
  );
}
