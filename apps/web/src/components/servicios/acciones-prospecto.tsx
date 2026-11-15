'use client';

import { ArrowRight, CircleX, Pencil, RotateCcw, UserCheck, UserPlus, UserRoundCheck } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { ProspectoDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const LISTADO = '/admin/servicios/prospectos';
type Respuesta = { message: string; data: ProspectoDetalle };
type Opcion = { valor: string; texto: string };

interface ClienteOpcion {
  identificador: string;
  nombre: string;
  documento: string | null;
  documento_tipo: string | null;
}

/** Origen que se puede elegir a mano (`cotizacion` lo fija el sistema). */
const ORIGENES_MANUALES = { web: 'Web', referido: 'Referido', evento: 'Evento', llamada: 'Llamada', otro: 'Otro' } as const;

/** Opciones de un selector cargadas del servidor mientras `activo`. */
function useOpciones(ruta: string, activo: boolean): Opcion[] | null {
  const { ejecutar } = useOperacionPanel();
  const [opciones, setOpciones] = useState<Opcion[] | null>(null);

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

interface DatosProspecto {
  nombre_contacto: string;
  empresa: string;
  email: string;
  telefono: string;
  origen: string;
  servicio_interes: string;
  proximo_seguimiento: string;
}

function FormularioProspecto({ prospecto, onGuardado, onCancelar, onOcupado }: {
  prospecto?: ProspectoDetalle;
  onGuardado: (mensaje: string, identificador: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
}) {
  const { ejecutar } = useOperacionPanel();
  const servicios = useOpciones('servicios/prospectos/opciones/servicios', true);
  const [datos, setDatos] = useState<DatosProspecto>({
    nombre_contacto: prospecto?.nombre_contacto ?? '',
    empresa: prospecto?.empresa ?? '',
    email: prospecto?.email ?? '',
    telefono: prospecto?.telefono ?? '',
    origen: prospecto && prospecto.origen !== 'cotizacion' ? prospecto.origen : 'web',
    servicio_interes: prospecto?.servicio_interes?.identificador ?? '',
    proximo_seguimiento: prospecto?.proximo_seguimiento ?? '',
  });
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  // Un prospecto nacido de una cotización conserva su origen: el sistema lo fijó y no se cambia a mano.
  const origenFijo = prospecto?.origen === 'cotizacion';

  const cambiar = (campo: keyof DatosProspecto) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado(true);
    setError(null);
    const opcional = (texto: string) => (texto.trim() === '' ? null : texto.trim());
    const cuerpo = {
      nombre_contacto: datos.nombre_contacto.trim(),
      empresa: opcional(datos.empresa),
      email: opcional(datos.email),
      telefono: opcional(datos.telefono),
      // El origen reservado no se reenvía: Laravel solo acepta los manuales y conserva el existente.
      origen: origenFijo ? 'otro' : datos.origen,
      servicio_interes: opcional(datos.servicio_interes),
      proximo_seguimiento: opcional(datos.proximo_seguimiento),
    };
    const resultado = prospecto
      ? await ejecutar<Respuesta>(`servicios/prospectos/${prospecto.identificador}`, 'PUT', { ...cuerpo, _revision: prospecto.revision })
      : await ejecutar<Respuesta>('servicios/prospectos', 'POST', cuerpo);
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
      {origenFijo ? <Alerta tipo="info">Este prospecto nació de una solicitud de cotización; su origen no se modifica.</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo tono="servicios" etiqueta="Nombre de contacto" value={datos.nombre_contacto} onChange={cambiar('nombre_contacto')} error={err('nombre_contacto')} required maxLength={120} />
        <Campo tono="servicios" etiqueta="Empresa (opcional)" value={datos.empresa} onChange={cambiar('empresa')} error={err('empresa')} maxLength={160} />
        <Campo tono="servicios" etiqueta="Correo" type="email" value={datos.email} onChange={cambiar('email')} error={err('email')} maxLength={160} autoComplete="off" />
        <Campo tono="servicios" etiqueta="Teléfono" type="tel" value={datos.telefono} onChange={cambiar('telefono')} error={err('telefono')} maxLength={30} autoComplete="off" ayuda="Indica al menos correo o teléfono." />
        {origenFijo ? null : <CampoSeleccion tono="servicios" etiqueta="Origen" value={datos.origen} onChange={cambiar('origen')} error={err('origen')} opciones={ORIGENES_MANUALES} />}
        <CampoSeleccion tono="servicios" etiqueta="Servicio de interés" value={datos.servicio_interes} onChange={cambiar('servicio_interes')} error={err('servicio_interes')} opciones={{ '': 'Sin indicar', ...Object.fromEntries((servicios ?? []).map((s) => [s.valor, s.texto])) }} />
        <Campo tono="servicios" etiqueta="Próximo seguimiento (opcional)" type="date" value={datos.proximo_seguimiento} onChange={cambiar('proximo_seguimiento')} error={err('proximo_seguimiento')} />
      </div>
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono="servicios" variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono="servicios" type="submit" cargando={guardando}>{prospecto ? 'Guardar cambios' : 'Registrar prospecto'}</Boton>
      </div>
    </form>
  );
}

/** «Nuevo prospecto» del listado. */
export function BotonNuevoProspecto() {
  const puede = usePuede('prospectos.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono="servicios" icono={<UserPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nuevo prospecto</Boton>
      <Dialogo abierto={abierto} titulo="Registrar prospecto" descripcion="Un contacto comercial que aún no es cliente." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioProspecto
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

/** Siguiente paso natural de cada estado (el resto de movimientos van por «Descartar» o «Reabrir»). */
const SIGUIENTE: Partial<Record<ProspectoDetalle['estado'], { estado: string; texto: string }>> = {
  nuevo: { estado: 'contactado', texto: 'Marcar como contactado' },
  contactado: { estado: 'calificado', texto: 'Marcar como calificado' },
};

type Modo = 'editar' | 'responsable' | 'descartar' | 'convertir' | null;

/** Acciones del detalle: avanzar, descartar o reabrir, convertir en cliente, responsable y edición. */
export function AccionesProspecto({ prospecto }: { prospecto: ProspectoDetalle }) {
  const puedeEditar = usePuede('prospectos.editar');
  const puedeConvertir = usePuede('prospectos.convertir');
  const puedeVerClientes = usePuede('clientes.ver');
  const puedeCrearClientes = usePuede('clientes.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [modo, setModo] = useState<Modo>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [responsable, setResponsable] = useState('');
  const [motivo, setMotivo] = useState('');
  const [busquedaCliente, setBusquedaCliente] = useState('');
  const [clientes, setClientes] = useState<ClienteOpcion[] | null>(null);
  const [clienteElegido, setClienteElegido] = useState('');
  const responsables = useOpciones('servicios/prospectos/opciones/responsables', modo === 'responsable');

  const convertido = prospecto.estado === 'convertido';
  if (convertido || (!puedeEditar && !puedeConvertir)) return null;

  async function operar(ruta: string, cuerpo: Record<string, unknown>, cerrar: boolean) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/prospectos/${prospecto.identificador}/${ruta}`, 'POST', { ...cuerpo, _revision: prospecto.revision });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      // La revisión quedó obsoleta: se relee el detalle para que el siguiente intento use la vigente.
      if (resultado.estado === 412) router.refresh();
      return;
    }
    if (cerrar) setModo(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
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
    setMotivo('');
    setBusquedaCliente('');
    setClientes(null);
    setClienteElegido('');
    setResponsable(prospecto.responsable === null ? '' : String(prospecto.responsable.id));
    setModo(siguiente);
  };
  const siguiente = SIGUIENTE[prospecto.estado];
  const cerrar = () => setModo(null);

  return (
    <>
      {puedeEditar && siguiente ? (
        <Boton tono="servicios" variante="secundario" icono={<ArrowRight className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void operar('estado', { estado: siguiente.estado }, false)}>{siguiente.texto}</Boton>
      ) : null}
      {puedeConvertir && prospecto.estado === 'calificado' ? (
        <Boton tono="servicios" icono={<UserRoundCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('convertir')}>Convertir en cliente</Boton>
      ) : null}
      {puedeEditar && prospecto.estado === 'descartado' ? (
        <Boton tono="servicios" variante="secundario" icono={<RotateCcw className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void operar('estado', { estado: 'nuevo' }, false)}>Reabrir</Boton>
      ) : null}
      {puedeEditar && prospecto.estado !== 'descartado' ? (
        <Boton tono="servicios" variante="secundario" icono={<CircleX className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('descartar')}>Descartar</Boton>
      ) : null}
      {puedeEditar ? (
        <>
          <Boton tono="servicios" variante="secundario" icono={<UserCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('responsable')}>Responsable</Boton>
          <Boton tono="servicios" variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('editar')}>Editar</Boton>
        </>
      ) : null}
      {error && modo === null ? <p role="alert" className="basis-full text-sm text-estado-error">{error.message}</p> : null}

      <Dialogo abierto={modo === 'editar'} titulo="Editar prospecto" descripcion={prospecto.nombre_contacto} onCerrar={cerrar} bloqueado={ocupado}>
        <FormularioProspecto
          prospecto={prospecto}
          onOcupado={setOcupado}
          onCancelar={cerrar}
          onGuardado={(mensaje) => {
            cerrar();
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <Dialogo abierto={modo === 'responsable'} titulo="Asignar responsable" descripcion={prospecto.nombre_contacto} onCerrar={cerrar} bloqueado={ocupado} ancho="sm">
        <form onSubmit={(evento) => { evento.preventDefault(); void operar('responsable', { responsable_id: responsable === '' ? null : Number(responsable) }, true); }} noValidate className="space-y-4">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'responsable_id') ?? error.message}</Alerta> : null}
          <CampoSeleccion tono="servicios" etiqueta="Responsable" value={responsable} onChange={(evento) => setResponsable(evento.target.value)} opciones={{ '': 'Sin asignar', ...Object.fromEntries((responsables ?? []).map((o) => [o.valor, o.texto])) }} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Guardar</Boton>
          </div>
        </form>
      </Dialogo>

      <Dialogo abierto={modo === 'descartar'} titulo="Descartar prospecto" descripcion={prospecto.nombre_contacto} onCerrar={cerrar} bloqueado={ocupado} ancho="sm">
        <form onSubmit={(evento) => { evento.preventDefault(); void operar('estado', { estado: 'descartado', motivo_descarte: motivo.trim() }, true); }} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <CampoArea tono="servicios" etiqueta="Motivo del descarte" value={motivo} onChange={(evento) => setMotivo(evento.target.value)} error={errorDeCampo(error, 'motivo_descarte')} required rows={3} maxLength={255} ayuda="Queda en el detalle del prospecto. Se puede reabrir después." />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Descartar</Boton>
          </div>
        </form>
      </Dialogo>

      <Dialogo abierto={modo === 'convertir'} titulo="Convertir en cliente" descripcion={prospecto.nombre_contacto} onCerrar={cerrar} bloqueado={ocupado}>
        <div className="space-y-5">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'cliente') ?? error.message}</Alerta> : null}
          {puedeCrearClientes ? (
            <section aria-labelledby="titulo-convertir-nuevo" className="space-y-2">
              <h3 id="titulo-convertir-nuevo" className="text-sm font-semibold text-panel-text">Cliente nuevo</h3>
              <p className="text-sm text-panel-muted">
                Se crea un cliente {prospecto.empresa ? <>de tipo <strong>{ETIQUETAS.tipoCliente.empresa.toLowerCase()}</strong> con la persona de contacto como principal</> : <>de tipo <strong>{ETIQUETAS.tipoCliente.persona.toLowerCase()}</strong></>} con los datos del prospecto.
                Si ya existe un cliente con ese correo, vincúlalo desde «Cliente existente».
              </p>
              <div className="flex justify-end">
                <Boton tono="servicios" cargando={ocupado} onClick={() => void operar('convertir', {}, true)}>Crear cliente</Boton>
              </div>
            </section>
          ) : null}
          {puedeVerClientes ? (
            <section aria-labelledby="titulo-convertir-existente" className={`space-y-3 ${puedeCrearClientes ? 'border-t border-panel-border pt-4' : ''}`}>
              <h3 id="titulo-convertir-existente" className="text-sm font-semibold text-panel-text">Cliente existente</h3>
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
                <Boton tono="servicios" variante="secundario" cargando={ocupado} disabled={clienteElegido === ''} onClick={() => void operar('convertir', { cliente: clienteElegido }, true)}>Vincular cliente seleccionado</Boton>
              </div>
            </section>
          ) : null}
          <div className="flex justify-end border-t border-panel-border pt-4">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
          </div>
        </div>
      </Dialogo>
    </>
  );
}
