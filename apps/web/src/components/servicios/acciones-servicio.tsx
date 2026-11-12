'use client';

import { CirclePower, PackagePlus, Pencil } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { ServicioDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const LISTADO = '/admin/servicios/catalogo';

type Respuesta = { message: string; data: ServicioDetalle };

/** Botón «Nuevo servicio» del catálogo: abre el formulario y lleva a la ficha del servicio creado. */
export function BotonNuevoServicio() {
  const puede = usePuede('servicios.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [abierto, setAbierto] = useState(false);
  const [datos, setDatos] = useState({ codigo: '', nombre: '', categoria: '', estado: 'inactivo' });
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  if (!puede) return null;

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>('servicios/servicios', 'POST', { ...datos, categoria: datos.categoria.trim() || null });
    setGuardando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setAbierto(false);
    mostrar('exito', resultado.datos.message);
    router.push(`${LISTADO}/${resultado.datos.data.id}`);
  }

  const err = (campo: string) => errorDeCampo(error, campo);
  const cambiar = (campo: keyof typeof datos) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  return (
    <>
      <Boton tono="servicios" icono={<PackagePlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nuevo servicio</Boton>
      <Dialogo abierto={abierto} titulo="Registrar servicio" descripcion="La ficha pública se prepara y autoriza después, desde el detalle." onCerrar={() => setAbierto(false)} bloqueado={guardando}>
        <form onSubmit={enviar} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <Campo tono="servicios" etiqueta="Código" value={datos.codigo} onChange={cambiar('codigo')} error={err('codigo')} required maxLength={40} autoComplete="off" ayuda="Letras mayúsculas, números, guion o guion bajo. No se puede cambiar después." />
            <CampoSeleccion tono="servicios" etiqueta="Estado inicial" value={datos.estado} onChange={cambiar('estado')} error={err('estado')} opciones={ETIQUETAS.estadoServicio} />
            <Campo tono="servicios" etiqueta="Nombre" value={datos.nombre} onChange={cambiar('nombre')} error={err('nombre')} required maxLength={120} className="sm:col-span-2" />
            <Campo tono="servicios" etiqueta="Categoría (opcional)" value={datos.categoria} onChange={cambiar('categoria')} error={err('categoria')} maxLength={80} className="sm:col-span-2" />
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setAbierto(false)} disabled={guardando}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={guardando}>Registrar servicio</Boton>
          </div>
        </form>
      </Dialogo>
    </>
  );
}

type Modo = 'editar' | 'estado' | null;

/** Acciones de la ficha del servicio: editar nombre y categoría y cambiar su estado (ambas con la revisión vigente). */
export function AccionesServicio({ servicio }: { servicio: ServicioDetalle }) {
  const puede = usePuede('servicios.editar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [modo, setModo] = useState<Modo>(null);
  const [nombre, setNombre] = useState(servicio.nombre);
  const [categoria, setCategoria] = useState(servicio.categoria ?? '');
  const [estado, setEstado] = useState<string>(servicio.estado);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  if (!puede) return null;

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    setError(null);
    const resultado = modo === 'estado'
      ? await ejecutar<Respuesta>(`servicios/servicios/${servicio.id}/estado`, 'POST', { estado, _revision: servicio.revision })
      : await ejecutar<Respuesta>(`servicios/servicios/${servicio.id}`, 'PUT', { nombre, categoria: categoria.trim() || null, _revision: servicio.revision });
    setGuardando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setModo(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const abrir = (siguiente: Modo) => {
    setNombre(servicio.nombre);
    setCategoria(servicio.categoria ?? '');
    setEstado(servicio.estado);
    setError(null);
    setModo(siguiente);
  };
  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <>
      <Boton tono="servicios" variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('editar')}>Editar</Boton>
      <Boton tono="servicios" variante="secundario" icono={<CirclePower className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('estado')}>Cambiar estado</Boton>

      <Dialogo abierto={modo !== null} titulo={modo === 'estado' ? 'Cambiar estado' : 'Editar servicio'} descripcion={`${servicio.codigo} · ${servicio.nombre}`} onCerrar={() => setModo(null)} bloqueado={guardando} ancho={modo === 'estado' ? 'sm' : 'lg'}>
        <form onSubmit={enviar} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          {modo === 'estado' ? (
            <>
              <CampoSeleccion tono="servicios" etiqueta="Estado" value={estado} onChange={(evento) => setEstado(evento.target.value)} error={err('estado')} opciones={ETIQUETAS.estadoServicio} />
              <p className="text-xs text-panel-muted">Si el servicio deja de estar activo, su ficha pública publicada se retira automáticamente.</p>
            </>
          ) : (
            <div className="grid gap-4">
              <Campo tono="servicios" etiqueta="Nombre" value={nombre} onChange={(evento) => setNombre(evento.target.value)} error={err('nombre')} required maxLength={120} />
              <Campo tono="servicios" etiqueta="Categoría (opcional)" value={categoria} onChange={(evento) => setCategoria(evento.target.value)} error={err('categoria')} maxLength={80} />
            </div>
          )}
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setModo(null)} disabled={guardando}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={guardando}>Guardar</Boton>
          </div>
        </form>
      </Dialogo>
    </>
  );
}
