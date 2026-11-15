'use client';

import { ArrowRight, CircleX, Pencil, Plus, RotateCcw, UserCheck } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type DragEvent, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { useOpciones } from '@/components/servicios/acciones-prospecto';
import {
  TRANSICIONES_OPORTUNIDAD,
  type ColumnaTablero,
  type EtapaOportunidad,
  type OportunidadDetalle,
  type OportunidadResumen,
} from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS, fecha, moneda } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const LISTADO = '/admin/servicios/oportunidades';
type Respuesta = { message: string; data: OportunidadDetalle };

interface ClienteOpcion {
  identificador: string;
  nombre: string;
  documento: string | null;
  documento_tipo: string | null;
}

/** Siguiente etapa natural (el resto de movimientos van por «Perdida» o «Reabrir»). */
const SIGUIENTE: Partial<Record<EtapaOportunidad, { etapa: EtapaOportunidad; texto: string }>> = {
  nueva: { etapa: 'calificada', texto: 'Marcar como calificada' },
  calificada: { etapa: 'propuesta', texto: 'Pasar a propuesta' },
  propuesta: { etapa: 'negociacion', texto: 'Pasar a negociación' },
  negociacion: { etapa: 'ganada', texto: 'Marcar como ganada' },
};

const opcional = (texto: string) => (texto.trim() === '' ? null : texto.trim());

/** Cambio de etapa compartido por el detalle y el tablero. Devuelve el mensaje de éxito o el error. */
function useCambioEtapa() {
  const { ejecutar } = useOperacionPanel();
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function cambiar(oportunidad: { identificador: string; revision: string }, etapa: EtapaOportunidad, motivo?: string): Promise<boolean> {
    setOcupado(true);
    setError(null);
    const cuerpo: Record<string, unknown> = { etapa, _revision: oportunidad.revision };
    if (motivo !== undefined) cuerpo.motivo_perdida = motivo;
    const resultado = await ejecutar<Respuesta>(`servicios/oportunidades/${oportunidad.identificador}/etapa`, 'POST', cuerpo);
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      // La revisión quedó obsoleta: se relee para que el siguiente intento use la vigente.
      if (resultado.estado === 412) router.refresh();
      return false;
    }
    mostrar('exito', resultado.datos.message);
    router.refresh();
    return true;
  }

  return { cambiar, ocupado, error, limpiarError: () => setError(null) };
}

/** Diálogo de «Marcar como perdida»: el motivo es obligatorio. */
function DialogoPerdida({ oportunidad, abierto, onCerrar, cambio }: {
  oportunidad: { identificador: string; revision: string; titulo: string } | null;
  abierto: boolean;
  onCerrar: () => void;
  cambio: ReturnType<typeof useCambioEtapa>;
}) {
  const [motivo, setMotivo] = useState('');

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (oportunidad && (await cambio.cambiar(oportunidad, 'perdida', motivo.trim()))) {
      setMotivo('');
      onCerrar();
    }
  }

  return (
    <Dialogo abierto={abierto} titulo="Marcar como perdida" descripcion={oportunidad?.titulo} onCerrar={onCerrar} bloqueado={cambio.ocupado} ancho="sm">
      <form onSubmit={(evento) => void enviar(evento)} noValidate className="space-y-4">
        {cambio.error && cambio.error.code !== 'validacion' ? <Alerta tipo="error">{cambio.error.message}</Alerta> : null}
        <CampoArea tono="servicios" etiqueta="Motivo de la pérdida" value={motivo} onChange={(evento) => setMotivo(evento.target.value)} error={errorDeCampo(cambio.error, 'motivo_perdida')} required rows={3} maxLength={255} ayuda="Queda en el detalle de la oportunidad. Se puede reabrir después." />
        <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
          <Boton tono="servicios" variante="secundario" onClick={onCerrar} disabled={cambio.ocupado}>Cancelar</Boton>
          <Boton tono="servicios" type="submit" cargando={cambio.ocupado}>Marcar como perdida</Boton>
        </div>
      </form>
    </Dialogo>
  );
}

interface DatosOportunidad {
  titulo: string;
  monto_estimado: string;
  probabilidad: string;
  fecha_cierre_estimada: string;
}

function FormularioOportunidad({ oportunidad, onGuardado, onCancelar, onOcupado }: {
  oportunidad?: OportunidadDetalle;
  onGuardado: (mensaje: string, identificador: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
}) {
  const { ejecutar } = useOperacionPanel();
  const puedeVerClientes = usePuede('clientes.ver');
  const [datos, setDatos] = useState<DatosOportunidad>({
    titulo: oportunidad?.titulo ?? '',
    monto_estimado: oportunidad ? oportunidad.monto_estimado : '',
    probabilidad: oportunidad ? String(oportunidad.probabilidad) : '',
    fecha_cierre_estimada: oportunidad?.fecha_cierre_estimada ?? '',
  });
  const [busqueda, setBusqueda] = useState('');
  const [clientes, setClientes] = useState<ClienteOpcion[] | null>(null);
  const [cliente, setCliente] = useState('');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const cambiar = (campo: keyof DatosOportunidad) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

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
      titulo: datos.titulo.trim(),
      monto_estimado: opcional(datos.monto_estimado),
      fecha_cierre_estimada: opcional(datos.fecha_cierre_estimada),
    };
    if (datos.probabilidad.trim() !== '') cuerpo.probabilidad = Number(datos.probabilidad);
    const resultado = oportunidad
      ? await ejecutar<Respuesta>(`servicios/oportunidades/${oportunidad.identificador}`, 'PUT', { ...cuerpo, _revision: oportunidad.revision })
      : await ejecutar<Respuesta>('servicios/oportunidades', 'POST', { ...cuerpo, cliente });
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
      {oportunidad ? null : (
        <section aria-labelledby="titulo-oportunidad-cliente" className="space-y-2">
          <h3 id="titulo-oportunidad-cliente" className="text-sm font-semibold text-panel-text">Cliente</h3>
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
                      <input type="radio" name="cliente-oportunidad" value={c.identificador} checked={cliente === c.identificador} onChange={() => setCliente(c.identificador)} className="h-4 w-4 accent-servicios-700" />
                      <span className="font-medium">{c.nombre}</span>
                      {c.documento ? <span className="text-panel-muted">{c.documento_tipo?.toUpperCase()} {c.documento}</span> : null}
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
        <Campo tono="servicios" etiqueta="Título" value={datos.titulo} onChange={cambiar('titulo')} error={err('titulo')} required maxLength={160} className="sm:col-span-2" />
        <Campo tono="servicios" etiqueta="Monto estimado (S/)" type="number" inputMode="decimal" min="0" step="0.01" value={datos.monto_estimado} onChange={cambiar('monto_estimado')} error={err('monto_estimado')} />
        <Campo tono="servicios" etiqueta="Cierre estimado (opcional)" type="date" value={datos.fecha_cierre_estimada} onChange={cambiar('fecha_cierre_estimada')} error={err('fecha_cierre_estimada')} />
        <Campo tono="servicios" etiqueta="Probabilidad (%)" type="number" inputMode="numeric" min="0" max="100" step="1" value={datos.probabilidad} onChange={cambiar('probabilidad')} error={err('probabilidad')} ayuda="Si lo dejas vacío se usa la de la etapa." />
      </div>
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton tono="servicios" variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton tono="servicios" type="submit" cargando={guardando} disabled={!oportunidad && cliente === ''}>{oportunidad ? 'Guardar cambios' : 'Registrar oportunidad'}</Boton>
      </div>
    </form>
  );
}

/** «Nueva oportunidad» del tablero y del listado. */
export function BotonNuevaOportunidad() {
  const puede = usePuede('pipeline.gestionar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton tono="servicios" icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nueva oportunidad</Boton>
      <Dialogo abierto={abierto} titulo="Registrar oportunidad" descripcion="Un posible negocio con un cliente existente." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioOportunidad
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

type Modo = 'editar' | 'responsable' | 'perdida' | null;

/** Acciones del detalle: avanzar, marcar como perdida o reabrir, responsable y edición. */
export function AccionesOportunidad({ oportunidad }: { oportunidad: OportunidadDetalle }) {
  const puede = usePuede('pipeline.gestionar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const cambio = useCambioEtapa();
  const [modo, setModo] = useState<Modo>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [responsable, setResponsable] = useState('');
  const responsables = useOpciones('servicios/oportunidades/opciones/responsables', modo === 'responsable');

  if (!puede) return null;

  const abierta = oportunidad.etapa !== 'ganada' && oportunidad.etapa !== 'perdida';
  const siguiente = SIGUIENTE[oportunidad.etapa];
  const cerrar = () => setModo(null);
  const abrir = (nuevo: Modo) => {
    setError(null);
    cambio.limpiarError();
    setResponsable(oportunidad.responsable === null ? '' : String(oportunidad.responsable.id));
    setModo(nuevo);
  };

  async function asignar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`servicios/oportunidades/${oportunidad.identificador}/responsable`, 'POST', {
      responsable_id: responsable === '' ? null : Number(responsable),
      _revision: oportunidad.revision,
    });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 412) router.refresh();
      return;
    }
    cerrar();
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <>
      {siguiente ? (
        <Boton tono="servicios" icono={<ArrowRight className="h-4 w-4" aria-hidden="true" />} cargando={cambio.ocupado} onClick={() => void cambio.cambiar(oportunidad, siguiente.etapa)}>{siguiente.texto}</Boton>
      ) : null}
      {!abierta ? (
        <Boton tono="servicios" variante="secundario" icono={<RotateCcw className="h-4 w-4" aria-hidden="true" />} cargando={cambio.ocupado} onClick={() => void cambio.cambiar(oportunidad, 'nueva')}>Reabrir</Boton>
      ) : (
        <Boton tono="servicios" variante="secundario" icono={<CircleX className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('perdida')}>Marcar como perdida</Boton>
      )}
      <Boton tono="servicios" variante="secundario" icono={<UserCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('responsable')}>Responsable</Boton>
      {abierta ? <Boton tono="servicios" variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('editar')}>Editar</Boton> : null}
      {cambio.error && modo === null ? <p role="alert" className="basis-full text-sm text-estado-error">{cambio.error.message}</p> : null}

      <Dialogo abierto={modo === 'editar'} titulo="Editar oportunidad" descripcion={oportunidad.titulo} onCerrar={cerrar} bloqueado={ocupado}>
        <FormularioOportunidad
          oportunidad={oportunidad}
          onOcupado={setOcupado}
          onCancelar={cerrar}
          onGuardado={(mensaje) => {
            cerrar();
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <Dialogo abierto={modo === 'responsable'} titulo="Asignar responsable" descripcion={oportunidad.titulo} onCerrar={cerrar} bloqueado={ocupado} ancho="sm">
        <form onSubmit={(evento) => void asignar(evento)} noValidate className="space-y-4">
          {error ? <Alerta tipo="error">{errorDeCampo(error, 'responsable_id') ?? error.message}</Alerta> : null}
          <CampoSeleccion tono="servicios" etiqueta="Responsable" value={responsable} onChange={(evento) => setResponsable(evento.target.value)} opciones={{ '': 'Sin asignar', ...Object.fromEntries((responsables ?? []).map((o) => [o.valor, o.texto])) }} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>Guardar</Boton>
          </div>
        </form>
      </Dialogo>

      <DialogoPerdida oportunidad={oportunidad} abierto={modo === 'perdida'} onCerrar={cerrar} cambio={cambio} />
    </>
  );
}

function Tarjeta({ oportunidad, puede, cambio, onPerdida }: {
  oportunidad: OportunidadResumen;
  puede: boolean;
  cambio: ReturnType<typeof useCambioEtapa>;
  onPerdida: (o: OportunidadResumen) => void;
}) {
  const destinos = TRANSICIONES_OPORTUNIDAD[oportunidad.etapa];

  function mover(destino: string) {
    if (destino === '') return;
    if (destino === 'perdida') onPerdida(oportunidad);
    else void cambio.cambiar(oportunidad, destino as EtapaOportunidad);
  }

  return (
    <li
      draggable={puede}
      onDragStart={(evento: DragEvent<HTMLLIElement>) => {
        evento.dataTransfer.setData('text/plain', oportunidad.identificador);
        evento.dataTransfer.effectAllowed = 'move';
      }}
      className="rounded-lg border border-panel-border bg-panel-surface p-3 shadow-sm"
    >
      <Link href={`${LISTADO}/${oportunidad.identificador}`} className="text-sm font-semibold text-panel-text hover:text-servicios-800 hover:underline">{oportunidad.titulo}</Link>
      {oportunidad.cliente ? <p className="mt-0.5 text-xs text-panel-muted">{oportunidad.cliente.nombre}</p> : null}
      <p className="mt-2 text-sm font-medium text-panel-text">{moneda(oportunidad.monto_estimado)} <span className="text-xs font-normal text-panel-muted">· {oportunidad.probabilidad} %</span></p>
      {oportunidad.fecha_cierre_estimada ? <p className="text-xs text-panel-muted">Cierre: {fecha(oportunidad.fecha_cierre_estimada)}</p> : null}
      {puede ? (
        <label className="mt-2 block text-xs text-panel-muted">
          <span className="sr-only">Mover «{oportunidad.titulo}» a</span>
          <select
            value=""
            disabled={cambio.ocupado}
            onChange={(evento) => mover(evento.target.value)}
            className="w-full rounded-md border border-panel-border bg-panel-surface px-2 py-1 text-xs text-panel-text"
          >
            <option value="">Mover a…</option>
            {destinos.map((destino) => <option key={destino} value={destino}>{ETIQUETAS.etapaOportunidad[destino]}</option>)}
          </select>
        </label>
      ) : null}
    </li>
  );
}

/**
 * Tablero Kanban. Cada tarjeta se mueve con su selector (accesible con teclado) o arrastrándola a otra columna; en
 * ambos casos se pide `POST …/etapa` con la revisión de la tarjeta, así que Laravel valida la transición.
 */
export function TableroOportunidades({ columnas, porColumna }: { columnas: ColumnaTablero[]; porColumna: number }) {
  const puede = usePuede('pipeline.gestionar');
  const cambio = useCambioEtapa();
  const [perdida, setPerdida] = useState<OportunidadResumen | null>(null);
  const [sobre, setSobre] = useState<EtapaOportunidad | null>(null);
  const tarjetas = new Map(columnas.flatMap((c) => c.tarjetas.map((t) => [t.identificador, t] as const)));

  function soltar(evento: DragEvent<HTMLElement>, destino: EtapaOportunidad) {
    evento.preventDefault();
    setSobre(null);
    const origen = tarjetas.get(evento.dataTransfer.getData('text/plain'));
    if (!puede || !origen || origen.etapa === destino) return;
    if (!TRANSICIONES_OPORTUNIDAD[origen.etapa].includes(destino)) {
      // Laravel es la autoridad, pero se evita la petición evidentemente inválida.
      cambio.limpiarError();
      return;
    }
    if (destino === 'perdida') setPerdida(origen);
    else void cambio.cambiar(origen, destino);
  }

  return (
    <>
      {cambio.error && perdida === null ? <Alerta tipo="error">{cambio.error.message}</Alerta> : null}
      <div className="grid gap-4 overflow-x-auto pb-2 md:grid-cols-2 xl:grid-cols-6">
        {columnas.map((columna) => (
          <section
            key={columna.etapa}
            aria-labelledby={`columna-${columna.etapa}`}
            onDragOver={(evento) => { if (puede) { evento.preventDefault(); setSobre(columna.etapa); } }}
            onDragLeave={() => setSobre((actual) => (actual === columna.etapa ? null : actual))}
            onDrop={(evento) => soltar(evento, columna.etapa)}
            className={`min-w-56 rounded-xl border bg-panel-bg p-3 ${sobre === columna.etapa ? 'border-servicios-700' : 'border-panel-border'}`}
          >
            <header className="mb-3 space-y-1">
              <div className="flex items-center justify-between gap-2">
                <h2 id={`columna-${columna.etapa}`} className="text-sm font-semibold text-panel-text">{ETIQUETAS.etapaOportunidad[columna.etapa]}</h2>
                <Insignia color={colorEstado(columna.etapa)}>{columna.total}</Insignia>
              </div>
              <p className="text-xs text-panel-muted">{moneda(columna.monto)}</p>
            </header>
            {columna.tarjetas.length === 0 ? (
              <p className="rounded-md border border-dashed border-panel-border px-2 py-4 text-center text-xs text-panel-muted">Sin oportunidades</p>
            ) : (
              <ul className="space-y-2">
                {columna.tarjetas.map((t) => <Tarjeta key={t.identificador} oportunidad={t} puede={puede} cambio={cambio} onPerdida={setPerdida} />)}
              </ul>
            )}
            {columna.total > columna.tarjetas.length ? (
              <p className="mt-2 text-xs text-panel-muted">Se muestran las {porColumna} más recientes. <Link href={`${LISTADO}?vista=lista&etapa=${columna.etapa}`} className="font-semibold text-servicios-700 hover:underline">Ver todas</Link></p>
            ) : null}
          </section>
        ))}
      </div>
      <DialogoPerdida oportunidad={perdida} abierto={perdida !== null} onCerrar={() => setPerdida(null)} cambio={cambio} />
    </>
  );
}
