'use client';

import { CalendarClock, CalendarDays, CircleX, Contact, Copy, CopyPlus, FileText, Pencil, Play, Plus, Trash2, DoorOpen, CheckCheck } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { ConvocatoriaDetalle, EstadoConvocatoria, HorarioConvocatoria } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';
import { copiarAlPortapapeles } from '@/lib/panel/whatsapp-plantillas';

const LISTADO = '/admin/academico/convocatorias';
type Respuesta = { message: string; data: ConvocatoriaDetalle };

interface CursoOpcion {
  id: number;
  codigo: string;
  nombre: string;
}

const opcional = (texto: string) => (texto.trim() === '' ? null : texto.trim());

interface DatosConvocatoria {
  nombre: string;
  modalidad: string;
  fecha_inicio: string;
  fecha_fin: string;
  cupos_maximos: string;
}

function FormularioConvocatoria({ convocatoria, cursoInicial, onGuardado, onCancelar, onOcupado }: {
  convocatoria?: ConvocatoriaDetalle;
  cursoInicial?: CursoOpcion;
  onGuardado: (mensaje: string, identificador: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
}) {
  const { ejecutar, descargar } = useOperacionPanel();
  const [datos, setDatos] = useState<DatosConvocatoria>({
    nombre: convocatoria?.nombre_propio ?? '',
    modalidad: convocatoria?.modalidad ?? 'presencial',
    fecha_inicio: convocatoria?.fecha_inicio ?? '',
    fecha_fin: convocatoria?.fecha_fin ?? '',
    cupos_maximos: convocatoria ? String(convocatoria.cupos.maximos) : '',
  });
  const [busqueda, setBusqueda] = useState('');
  const [cursos, setCursos] = useState<CursoOpcion[] | null>(null);
  const [curso, setCurso] = useState(cursoInicial ? String(cursoInicial.id) : '');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const cambiar = (campo: keyof DatosConvocatoria) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  async function buscarCursos() {
    setGuardando(true);
    setError(null);
    const consulta = new URLSearchParams({ busqueda: busqueda.trim(), estado: 'activo' }).toString();
    const resultado = await ejecutar<{ data: CursoOpcion[] }>(`academico/cursos?${consulta}`);
    setGuardando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setCurso('');
    setCursos(resultado.datos.data);
  }

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado(true);
    setError(null);
    const cuerpo: Record<string, unknown> = {
      nombre: opcional(datos.nombre),
      modalidad: datos.modalidad,
      fecha_inicio: opcional(datos.fecha_inicio),
      fecha_fin: opcional(datos.fecha_fin),
      cupos_maximos: datos.cupos_maximos.trim() === '' ? null : Number(datos.cupos_maximos),
    };
    const resultado = convocatoria
      ? await ejecutar<Respuesta>(`academico/convocatorias/${convocatoria.identificador}`, 'PUT', { ...cuerpo, _revision: convocatoria.revision })
      : await ejecutar<Respuesta>('academico/convocatorias', 'POST', { ...cuerpo, curso_id: curso === '' ? null : Number(curso) });
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
      {convocatoria ? null : cursoInicial ? (
        <section aria-labelledby="titulo-convocatoria-curso" className="space-y-1">
          <h3 id="titulo-convocatoria-curso" className="text-sm font-semibold text-panel-text">Curso</h3>
          <p className="rounded-lg border border-panel-border bg-panel-bg px-3 py-2 text-sm text-panel-text">
            <span className="font-medium">{cursoInicial.nombre}</span>{' '}
            <span className="font-mono text-xs text-panel-muted">({cursoInicial.codigo})</span>
          </p>
        </section>
      ) : (
        <section aria-labelledby="titulo-convocatoria-curso" className="space-y-2">
          <h3 id="titulo-convocatoria-curso" className="text-sm font-semibold text-panel-text">Curso</h3>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <Campo etiqueta="Buscar curso por código o nombre" value={busqueda} onChange={(evento) => setBusqueda(evento.target.value)} maxLength={200} autoComplete="off" className="flex-1" onKeyDown={(evento) => { if (evento.key === 'Enter') { evento.preventDefault(); void buscarCursos(); } }} />
            <Boton variante="secundario" cargando={guardando} onClick={() => void buscarCursos()}>Buscar</Boton>
          </div>
          {cursos === null ? null : cursos.length === 0 ? (
            <p className="text-sm text-panel-muted">Ningún curso activo coincide.</p>
          ) : (
            <fieldset className="space-y-1">
              <legend className="sr-only">Cursos</legend>
              {cursos.map((c) => (
                <label key={c.id} className="flex cursor-pointer items-center gap-2 rounded-md border border-panel-border px-3 py-2 text-sm text-panel-text">
                  <input type="radio" name="curso-convocatoria" value={c.id} checked={curso === String(c.id)} onChange={() => setCurso(String(c.id))} className="h-4 w-4 accent-academico-700" />
                  <span className="font-medium">{c.nombre}</span>
                  <span className="text-panel-muted">{c.codigo}</span>
                </label>
              ))}
            </fieldset>
          )}
          {err('curso_id') ? <p role="alert" className="text-sm text-estado-error">{err('curso_id')}</p> : null}
        </section>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Nombre (opcional)" value={datos.nombre} onChange={cambiar('nombre')} error={err('nombre')} maxLength={160} ayuda="Si lo dejas vacío se usa el del curso." className="sm:col-span-2" />
        <CampoSeleccion etiqueta="Modalidad" value={datos.modalidad} onChange={cambiar('modalidad')} error={err('modalidad')} opciones={ETIQUETAS.modalidad} />
        <Campo etiqueta="Cupos máximos" type="number" inputMode="numeric" min="1" max="10" step="1" value={datos.cupos_maximos} onChange={cambiar('cupos_maximos')} error={err('cupos_maximos')} required />
        <Campo etiqueta="Inicio" type="date" value={datos.fecha_inicio} onChange={cambiar('fecha_inicio')} error={err('fecha_inicio')} required />
        <Campo etiqueta="Fin" type="date" value={datos.fecha_fin} onChange={cambiar('fecha_fin')} error={err('fecha_fin')} required />
      </div>
      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={guardando} disabled={!convocatoria && curso === ''}>{convocatoria ? 'Guardar cambios' : 'Registrar edición'}</Boton>
      </div>
    </form>
  );
}

/** «Nueva edición» del listado o de la ficha de un curso. */
export function BotonNuevaConvocatoria({ cursoInicial }: { cursoInicial?: CursoOpcion } = {}) {
  const puede = usePuede('convocatorias.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Nueva edición</Boton>
      <Dialogo abierto={abierto} lado="derecha" titulo="Registrar edición" descripcion="Una edición de un curso con sus fechas, cupos y horario." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioConvocatoria
          cursoInicial={cursoInicial}
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

const ACCION_ESTADO: Record<EstadoConvocatoria, { texto: string; icono: typeof Play; secundaria: boolean }> = {
  borrador: { texto: 'Borrador', icono: Play, secundaria: true },
  abierta: { texto: 'Abrir inscripciones', icono: DoorOpen, secundaria: false },
  en_curso: { texto: 'Iniciar', icono: Play, secundaria: false },
  cerrada: { texto: 'Cerrar edición', icono: CheckCheck, secundaria: false },
  cancelada: { texto: 'Cancelar edición', icono: CircleX, secundaria: true },
};

/** Acciones del detalle: cambios de estado permitidos y edición. Cerrar y cancelar piden confirmación y su permiso propio. */
export function AccionesConvocatoria({ convocatoria, transiciones }: { convocatoria: ConvocatoriaDetalle; transiciones: readonly EstadoConvocatoria[] }) {
  const puedeEditar = usePuede('convocatorias.editar');
  const puedeCrear = usePuede('convocatorias.crear');
  const puedeCerrar = usePuede('convocatorias.cerrar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, descargar } = useOperacionPanel();
  const [editando, setEditando] = useState(false);
  const [duplicando, setDuplicando] = useState(false);
  const [sigNombre, setSigNombre] = useState(convocatoria.nombre_propio ?? '');
  const [sigInicio, setSigInicio] = useState('');
  const [sigFin, setSigFin] = useState('');
  const [sigCupos, setSigCupos] = useState(String(convocatoria.cupos.maximos));
  const [sigAbrir, setSigAbrir] = useState(true);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [confirmar, setConfirmar] = useState<'cerrada' | 'cancelada' | null>(null);

  const visibles = transiciones.filter((estado) => (estado === 'cerrada' || estado === 'cancelada' ? puedeCerrar : puedeEditar));
  if (visibles.length === 0 && !puedeEditar && !puedeCrear) return null;

  async function cambiarEstado(estado: EstadoConvocatoria) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(`academico/convocatorias/${convocatoria.identificador}/estado`, 'POST', { estado, _revision: convocatoria.revision });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      // La revisión quedó obsoleta: se relee para que el siguiente intento use la vigente.
      if (resultado.estado === 412) router.refresh();
      return;
    }
    setConfirmar(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const vigente = transiciones.length > 0;

  return (
    <>
      {visibles.map((estado) => {
        const accion = ACCION_ESTADO[estado];
        const Icono = accion.icono;
        return (
          <Boton
            key={estado}
            variante={accion.secundaria ? 'secundario' : 'primario'}
            icono={<Icono className="h-4 w-4" aria-hidden="true" />}
            cargando={ocupado}
            onClick={() => (estado === 'cerrada' || estado === 'cancelada' ? (setError(null), setConfirmar(estado)) : void cambiarEstado(estado))}
          >
            {accion.texto}
          </Boton>
        );
      })}
      {puedeEditar && vigente ? <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setEditando(true); }}>Editar</Boton> : null}
      {puedeCrear && (convocatoria.modalidad === 'presencial' || convocatoria.modalidad === 'virtual') ? (
        <Boton
          variante="fantasma"
          icono={<CopyPlus className="h-4 w-4" aria-hidden="true" />}
          onClick={() => {
            setError(null);
            setSigNombre(convocatoria.nombre_propio ?? '');
            setSigCupos(String(convocatoria.cupos.maximos));
            setDuplicando(true);
          }}
        >
          Abrir siguiente edición
        </Boton>
      ) : null}
      <Boton
        variante="fantasma"
        icono={<Copy className="h-4 w-4" aria-hidden="true" />}
        onClick={async () => {
          const url = `${typeof window !== 'undefined' ? window.location.origin : ''}/edicion/${convocatoria.codigo}`;
          const ok = await copiarAlPortapapeles(url);
          mostrar(ok ? 'exito' : 'error', ok ? 'Enlace público del cronograma copiado.' : 'No se pudo copiar.');
        }}
      >
        Copiar cronograma
      </Boton>
      <Boton
        variante="fantasma"
        icono={<CalendarDays className="h-4 w-4" aria-hidden="true" />}
        onClick={async () => {
          const r = await descargar(`academico/convocatorias/${convocatoria.identificador}/calendario.ics`, 'GET');
          mostrar(r.ok ? 'exito' : 'error', r.ok ? 'Calendario .ics descargado.' : r.error.message);
        }}
      >
        Calendario .ics
      </Boton>
      <Boton
        variante="fantasma"
        icono={<FileText className="h-4 w-4" aria-hidden="true" />}
        onClick={async () => {
          const r = await descargar(`academico/convocatorias/${convocatoria.identificador}/lista-aula/pdf`);
          mostrar(r.ok ? 'exito' : 'error', r.ok ? 'Lista imprimible de aula descargada.' : r.error.message);
        }}
      >
        Lista aula PDF
      </Boton>
      <Boton
        variante="fantasma"
        icono={<Contact className="h-4 w-4" aria-hidden="true" />}
        onClick={async () => {
          const r = await descargar(`academico/convocatorias/${convocatoria.identificador}/contactos/csv`);
          mostrar(r.ok ? 'exito' : 'error', r.ok ? 'Contactos CSV descargados para WhatsApp.' : r.error.message);
        }}
      >
        Contactos WhatsApp
      </Boton>
      {error && !editando && !duplicando && confirmar === null ? <p role="alert" className="basis-full text-sm text-estado-error">{error.message}</p> : null}

      <Dialogo abierto={editando} lado="derecha" titulo="Editar edición" descripcion={convocatoria.codigo} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <FormularioConvocatoria
          convocatoria={convocatoria}
          onOcupado={setOcupado}
          onCancelar={() => setEditando(false)}
          onGuardado={(mensaje) => {
            setEditando(false);
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <Dialogo abierto={confirmar !== null} titulo={confirmar === 'cerrada' ? 'Cerrar edición' : 'Cancelar edición'} descripcion={convocatoria.codigo} onCerrar={() => setConfirmar(null)} bloqueado={ocupado} ancho="sm">
        <div className="space-y-4">
          {error ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <p className="text-sm text-panel-text">Una edición {confirmar === 'cerrada' ? 'cerrada' : 'cancelada'} ya no se puede editar ni reabrir.</p>
          <div className="pie-acciones">
            <Boton variante="secundario" onClick={() => setConfirmar(null)} disabled={ocupado}>Volver</Boton>
            <Boton variante="peligro" cargando={ocupado} onClick={() => confirmar !== null && void cambiarEstado(confirmar)}>{confirmar === 'cerrada' ? 'Cerrar edición' : 'Cancelar edición'}</Boton>
          </div>
        </div>
      </Dialogo>

      <Dialogo abierto={duplicando} titulo="Abrir siguiente edición" descripcion={`Copia curso, modalidad (${convocatoria.modalidad}) y ${convocatoria.horarios.length} bloque(s) de horario.`} onCerrar={() => setDuplicando(false)} bloqueado={ocupado} ancho="sm">
        <form
          onSubmit={async (evento) => {
            evento.preventDefault();
            setOcupado(true);
            setError(null);
            const r = await ejecutar<Respuesta>(`academico/convocatorias/${convocatoria.identificador}/siguiente`, 'POST', {
              fecha_inicio: sigInicio,
              fecha_fin: sigFin,
              nombre: opcional(sigNombre),
              cupos_maximos: sigCupos.trim() === '' ? null : Number(sigCupos),
              abrir: sigAbrir,
            });
            setOcupado(false);
            if (!r.ok) {
              setError(r.error);
              return;
            }
            setDuplicando(false);
            mostrar('exito', r.datos.message);
            router.push(`${LISTADO}/${r.datos.data.identificador}`);
          }}
          className="space-y-4"
        >
          {error ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <Campo etiqueta="Nombre de la nueva edición (opcional)" value={sigNombre} onChange={(e) => setSigNombre(e.target.value)} maxLength={120} error={errorDeCampo(error, 'nombre')} />
          <div className="grid gap-3 sm:grid-cols-2">
            <Campo etiqueta="Fecha de inicio" type="date" required value={sigInicio} onChange={(e) => setSigInicio(e.target.value)} error={errorDeCampo(error, 'fecha_inicio')} />
            <Campo etiqueta="Fecha de fin" type="date" required value={sigFin} onChange={(e) => setSigFin(e.target.value)} error={errorDeCampo(error, 'fecha_fin')} />
          </div>
          <Campo etiqueta="Cupos máximos" type="number" min={1} max={10000} value={sigCupos} onChange={(e) => setSigCupos(e.target.value)} error={errorDeCampo(error, 'cupos_maximos')} />
          <label className="flex items-center gap-2 text-sm text-panel-text">
            <input type="checkbox" checked={sigAbrir} onChange={(e) => setSigAbrir(e.target.checked)} className="h-4 w-4 rounded border-panel-border" />
            Abrir inmediatamente para matrículas
          </label>
          <div className="pie-acciones">
            <Boton variante="secundario" onClick={() => setDuplicando(false)} disabled={ocupado}>Cancelar</Boton>
            <Boton type="submit" cargando={ocupado}>Crear edición</Boton>
          </div>
        </form>
      </Dialogo>
    </>
  );
}

interface BloqueEditable {
  dia_semana: string;
  hora_inicio: string;
  hora_fin: string;
}

const aEditable = (h: HorarioConvocatoria): BloqueEditable => ({ dia_semana: String(h.dia_semana), hora_inicio: h.hora_inicio, hora_fin: h.hora_fin });

/** Editor de horarios: se envían todos los bloques juntos (reemplazan a los anteriores) con la revisión de la convocatoria. */
export function EditorHorarios({ convocatoria, editable }: { convocatoria: ConvocatoriaDetalle; editable: boolean }) {
  const puede = usePuede('convocatorias.editar') && editable;
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, descargar } = useOperacionPanel();
  const [editando, setEditando] = useState(false);
  const [bloques, setBloques] = useState<BloqueEditable[]>([]);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  const abrir = () => {
    setBloques(convocatoria.horarios.map(aEditable));
    setError(null);
    setEditando(true);
  };
  const cambiar = (indice: number, campo: keyof BloqueEditable, valor: string) => setBloques((actuales) => actuales.map((b, i) => (i === indice ? { ...b, [campo]: valor } : b)));

  async function guardar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setOcupado(true);
    setError(null);
    const horarios = bloques.map((b) => ({
      dia_semana: Number(b.dia_semana),
      hora_inicio: b.hora_inicio,
      hora_fin: b.hora_fin,
    }));
    const resultado = await ejecutar<Respuesta>(`academico/convocatorias/${convocatoria.identificador}/horarios`, 'PUT', { horarios, _revision: convocatoria.revision });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 412) router.refresh();
      return;
    }
    setEditando(false);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <div className="space-y-4">
      {convocatoria.horarios.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay horario.</p>
      ) : (
        <ul className="divide-y divide-panel-border rounded-lg border border-panel-border">
          {convocatoria.horarios.map((h, n) => (
            <li key={`${h.dia_semana}-${h.hora_inicio}-${n}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-sm">
              <span className="w-24 font-medium text-panel-text">{ETIQUETAS.diaSemana[String(h.dia_semana) as keyof typeof ETIQUETAS.diaSemana]}</span>
              <span className="text-panel-text">{h.hora_inicio} – {h.hora_fin}</span>
            </li>
          ))}
        </ul>
      )}
      {puede ? <Boton variante="secundario" icono={<CalendarClock className="h-4 w-4" aria-hidden="true" />} onClick={abrir}>Editar horario</Boton> : null}

      <Dialogo abierto={editando} lado="derecha" titulo="Editar horario" descripcion={convocatoria.codigo} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <form onSubmit={(evento) => void guardar(evento)} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          {error?.code === 'validacion' ? <Alerta tipo="error">{Object.values(error.errors ?? {})[0]?.[0] ?? 'Revisa los bloques marcados.'}</Alerta> : null}
          {bloques.length === 0 ? <p className="text-sm text-panel-muted">Sin bloques: al guardar se quitará el horario.</p> : null}
          {bloques.map((b, i) => (
            <fieldset key={i} className="grid gap-3 rounded-lg border border-panel-border p-3 sm:grid-cols-4">
              <legend className="px-1 text-xs font-semibold text-panel-muted">Bloque {i + 1}</legend>
              <CampoSeleccion etiqueta="Día" value={b.dia_semana} onChange={(e) => cambiar(i, 'dia_semana', e.target.value)} error={errorDeCampo(error, `horarios.${i}.dia_semana`)} opciones={ETIQUETAS.diaSemana} />
              <Campo etiqueta="Desde" type="time" value={b.hora_inicio} onChange={(e) => cambiar(i, 'hora_inicio', e.target.value)} error={errorDeCampo(error, `horarios.${i}.hora_inicio`)} required />
              <Campo etiqueta="Hasta" type="time" value={b.hora_fin} onChange={(e) => cambiar(i, 'hora_fin', e.target.value)} error={errorDeCampo(error, `horarios.${i}.hora_fin`)} required />
              <div className="flex items-end">
                <Boton variante="secundario" className="w-full" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setBloques((actuales) => actuales.filter((_, j) => j !== i))}>Quitar</Boton>
              </div>
            </fieldset>
          ))}
          <Boton variante="secundario" icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setBloques((actuales) => [...actuales, { dia_semana: '1', hora_inicio: '', hora_fin: '' }])}>Agregar bloque</Boton>
          <div className="pie-acciones">
            <Boton variante="secundario" onClick={() => setEditando(false)} disabled={ocupado}>Cancelar</Boton>
            <Boton type="submit" cargando={ocupado}>Guardar horario</Boton>
          </div>
        </form>
      </Dialogo>
    </div>
  );
}
