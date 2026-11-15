'use client';

import { Pencil, Plus, Power, Trash2, UserCheck, UserPlus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { ClienteDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const LISTADO = '/admin/servicios/clientes';
const MAX_CONTACTOS = 10;
type Respuesta = { message: string; data: ClienteDetalle };

interface ContactoEditable {
  nombre: string;
  cargo: string;
  email: string;
  telefono: string;
  principal: boolean;
}

interface DatosCliente {
  tipo: string;
  nombre: string;
  documento_tipo: string;
  documento_numero: string;
  email: string;
  telefono: string;
  direccion: string;
}

const CONTACTO_VACIO: ContactoEditable = { nombre: '', cargo: '', email: '', telefono: '', principal: false };

function FormularioCliente({ cliente, onGuardado, onCancelar, onOcupado }: {
  cliente?: ClienteDetalle;
  onGuardado: (mensaje: string, identificador: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
}) {
  const { ejecutar } = useOperacionPanel();
  const [datos, setDatos] = useState<DatosCliente>({
    tipo: cliente?.tipo ?? 'persona',
    nombre: cliente?.nombre ?? '',
    documento_tipo: cliente?.documento_tipo ?? '',
    documento_numero: cliente?.documento_numero ?? '',
    email: cliente?.email ?? '',
    telefono: cliente?.telefono ?? '',
    direccion: cliente?.direccion ?? '',
  });
  const [contactos, setContactos] = useState<ContactoEditable[]>(
    (cliente?.contactos ?? []).map((c) => ({ nombre: c.nombre, cargo: c.cargo ?? '', email: c.email ?? '', telefono: c.telefono ?? '', principal: c.principal })),
  );
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const cambiar = (campo: keyof DatosCliente) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));
  const cambiarContacto = (indice: number, campo: keyof ContactoEditable, valor: string | boolean) =>
    setContactos((actuales) => actuales.map((c, i) => (i === indice ? { ...c, [campo]: valor } : campo === 'principal' && valor === true ? { ...c, principal: false } : c)));

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado(true);
    setError(null);
    const opcional = (texto: string) => (texto.trim() === '' ? null : texto.trim());
    const cuerpo = {
      tipo: datos.tipo,
      nombre: datos.nombre.trim(),
      documento_tipo: datos.documento_tipo === '' ? null : datos.documento_tipo,
      documento_numero: opcional(datos.documento_numero),
      email: opcional(datos.email),
      telefono: opcional(datos.telefono),
      direccion: opcional(datos.direccion),
      contactos: contactos.map((c) => ({ nombre: c.nombre.trim(), cargo: opcional(c.cargo), email: opcional(c.email), telefono: opcional(c.telefono), principal: c.principal })),
    };
    const resultado = cliente
      ? await ejecutar<Respuesta>(`servicios/clientes/${cliente.identificador}`, 'PUT', { ...cuerpo, _revision: cliente.revision })
      : await ejecutar<Respuesta>('servicios/clientes', 'POST', cuerpo);
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
        <CampoSeleccion tono="servicios" etiqueta="Tipo de cliente" value={datos.tipo} onChange={cambiar('tipo')} error={err('tipo')} opciones={ETIQUETAS.tipoCliente} />
        <Campo tono="servicios" etiqueta={datos.tipo === 'empresa' ? 'Razón social' : 'Nombre completo'} value={datos.nombre} onChange={cambiar('nombre')} error={err('nombre')} required maxLength={160} />
        <CampoSeleccion tono="servicios" etiqueta="Documento (opcional)" value={datos.documento_tipo} onChange={cambiar('documento_tipo')} error={err('documento_tipo')} opciones={{ '': 'Sin documento', ...ETIQUETAS.documentoCliente }} />
        <Campo tono="servicios" etiqueta="Número de documento" value={datos.documento_numero} onChange={cambiar('documento_numero')} error={err('documento_numero')} maxLength={20} autoComplete="off" ayuda="DNI: 8 dígitos. RUC: 11 dígitos." />
        <Campo tono="servicios" etiqueta="Correo" type="email" value={datos.email} onChange={cambiar('email')} error={err('email')} maxLength={160} autoComplete="off" />
        <Campo tono="servicios" etiqueta="Teléfono" type="tel" value={datos.telefono} onChange={cambiar('telefono')} error={err('telefono')} maxLength={30} autoComplete="off" />
        <Campo tono="servicios" etiqueta="Dirección" value={datos.direccion} onChange={cambiar('direccion')} error={err('direccion')} maxLength={255} className="sm:col-span-2" />
      </div>

      <fieldset className="space-y-3 rounded-lg border border-panel-border p-3">
        <legend className="px-1 text-sm font-semibold text-panel-text">Personas de contacto</legend>
        {err('contactos') ? <p role="alert" className="text-sm text-estado-error">{err('contactos')}</p> : null}
        {contactos.length === 0 ? <p className="text-sm text-panel-muted">Sin contactos adicionales.</p> : null}
        {contactos.map((contacto, indice) => (
          <div key={indice} className="space-y-3 rounded-md bg-panel-bg p-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <Campo tono="servicios" etiqueta="Nombre" value={contacto.nombre} onChange={(e) => cambiarContacto(indice, 'nombre', e.target.value)} error={err(`contactos.${indice}.nombre`)} required maxLength={120} />
              <Campo tono="servicios" etiqueta="Cargo" value={contacto.cargo} onChange={(e) => cambiarContacto(indice, 'cargo', e.target.value)} error={err(`contactos.${indice}.cargo`)} maxLength={80} />
              <Campo tono="servicios" etiqueta="Correo" type="email" value={contacto.email} onChange={(e) => cambiarContacto(indice, 'email', e.target.value)} error={err(`contactos.${indice}.email`)} maxLength={160} autoComplete="off" />
              <Campo tono="servicios" etiqueta="Teléfono" type="tel" value={contacto.telefono} onChange={(e) => cambiarContacto(indice, 'telefono', e.target.value)} error={err(`contactos.${indice}.telefono`)} maxLength={30} autoComplete="off" />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <label className="flex items-center gap-2 text-sm text-panel-text">
                <input type="checkbox" checked={contacto.principal} onChange={(e) => cambiarContacto(indice, 'principal', e.target.checked)} className="h-4 w-4 accent-servicios-700" />
                Contacto principal
              </label>
              <Boton tono="servicios" variante="fantasma" className="px-2 py-1 text-xs" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setContactos((actuales) => actuales.filter((_, i) => i !== indice))}>
                Quitar contacto {indice + 1}
              </Boton>
            </div>
          </div>
        ))}
        {contactos.length < MAX_CONTACTOS ? (
          <Boton tono="servicios" variante="secundario" icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setContactos((actuales) => [...actuales, { ...CONTACTO_VACIO }])}>
            Añadir contacto
          </Boton>
        ) : null}
      </fieldset>

      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono="servicios" variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono="servicios" type="submit" cargando={guardando}>{cliente ? 'Guardar cambios' : 'Registrar cliente'}</Boton>
      </div>
    </form>
  );
}

/** «Nuevo cliente» del listado. */
export function BotonNuevoCliente() {
  const puede = usePuede('clientes.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono="servicios" icono={<UserPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nuevo cliente</Boton>
      <Dialogo abierto={abierto} titulo="Registrar cliente" descripcion="Persona o empresa. El documento, si se indica, no puede repetirse." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioCliente
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

/** Opciones de responsable cargadas al abrir el diálogo. */
function useResponsables(activo: boolean): { valor: string; texto: string }[] | null {
  const { ejecutar } = useOperacionPanel();
  const [opciones, setOpciones] = useState<{ valor: string; texto: string }[] | null>(null);

  useEffect(() => {
    if (!activo) return;
    let vigente = true;
    void ejecutar<{ data: { id: number; texto: string }[] }>('servicios/clientes/opciones/responsables').then((resultado) => {
      if (vigente && resultado.ok) setOpciones(resultado.datos.data.map((o) => ({ valor: String(o.id), texto: o.texto })));
    });
    return () => {
      vigente = false;
    };
  }, [activo, ejecutar]);

  return opciones;
}

type Modo = 'editar' | 'responsable' | null;

/** Acciones del detalle: editar, cambiar responsable y activar o inactivar. */
export function AccionesCliente({ cliente }: { cliente: ClienteDetalle }) {
  const puedeEditar = usePuede('clientes.editar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [modo, setModo] = useState<Modo>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [responsable, setResponsable] = useState('');
  const responsables = useResponsables(modo === 'responsable');

  if (!puedeEditar) return null;

  async function operar(ruta: string, cuerpo: Record<string, unknown>, cerrar: boolean) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/clientes/${cliente.identificador}/${ruta}`, 'POST', { ...cuerpo, _revision: cliente.revision });
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

  const abrir = (siguiente: Modo) => {
    setError(null);
    setResponsable(cliente.responsable === null ? '' : String(cliente.responsable.id));
    setModo(siguiente);
  };
  const inactivo = cliente.estado === 'inactivo';

  return (
    <>
      <Boton tono="servicios" variante="secundario" icono={<UserCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('responsable')}>Responsable</Boton>
      <Boton tono="servicios" variante="secundario" icono={<Power className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void operar('estado', { estado: inactivo ? 'activo' : 'inactivo' }, false)}>
        {inactivo ? 'Activar' : 'Inactivar'}
      </Boton>
      <Boton tono="servicios" variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('editar')}>Editar</Boton>
      {error && modo === null ? <p role="alert" className="basis-full text-sm text-estado-error">{error.message}</p> : null}

      <Dialogo abierto={modo === 'editar'} titulo="Editar cliente" descripcion={cliente.nombre} onCerrar={() => setModo(null)} bloqueado={ocupado}>
        <FormularioCliente
          cliente={cliente}
          onOcupado={setOcupado}
          onCancelar={() => setModo(null)}
          onGuardado={(mensaje) => {
            setModo(null);
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <Dialogo abierto={modo === 'responsable'} titulo="Asignar responsable" descripcion={cliente.nombre} onCerrar={() => setModo(null)} bloqueado={ocupado} ancho="sm">
        <form onSubmit={(evento) => { evento.preventDefault(); void operar('responsable', { responsable_id: responsable === '' ? null : Number(responsable) }, true); }} noValidate className="space-y-4">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'responsable_id') ?? error.message}</Alerta> : null}
          <CampoSeleccion tono="servicios" etiqueta="Responsable" value={responsable} onChange={(evento) => setResponsable(evento.target.value)} opciones={{ '': 'Sin asignar', ...Object.fromEntries((responsables ?? []).map((o) => [o.valor, o.texto])) }} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setModo(null)} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Guardar</Boton>
          </div>
        </form>
      </Dialogo>
    </>
  );
}
