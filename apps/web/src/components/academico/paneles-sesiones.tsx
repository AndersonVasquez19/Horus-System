'use client';

import { CalendarPlus, Check, KeyRound, Pencil, Plus, Wand2, X } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { z } from 'zod';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { AsistenciaSesion } from '@/components/academico/paneles-asistencia';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import type { DocenteAsignado, SesionClase } from '@/features/academico/tipos';
import { fecha } from '@/lib/panel/formato';

const ESTADOS = { programada: 'Programada', realizada: 'Realizada', cancelada: 'Cancelada' } as const;
const COLORES = { programada: 'azul', realizada: 'verde', cancelada: 'rojo' } as const;

interface Props {
  convocatoriaId: string;
  modalidad: string;
  /** `null` si la persona no puede ver sesiones. */
  sesiones: readonly SesionClase[] | null;
  total: number;
  docentes: readonly DocenteAsignado[];
  /** La edición todavía admite cambios (no está cerrada ni cancelada). */
  editable: boolean;
}

const esquema = z.object({
  numero: z.string().regex(/^\d{0,4}$/, 'Usa solo números.'),
  fecha: z.string().min(1, 'Indica la fecha.'),
  hora_inicio: z.string().min(1, 'Indica la hora de inicio.'),
  hora_fin: z.string().min(1, 'Indica la hora de fin.'),
  tema: z.string().max(200, 'Hasta 200 caracteres.'),
  docente: z.string(),
  enlace_virtual: z.string().max(500, 'Hasta 500 caracteres.'),
});

/** Sesiones de clase de una edición: programar, editar, generar desde el horario, cancelar, marcar realizada y ver el enlace privado. */
export function PanelSesionesEdicion({ convocatoriaId, modalidad, sesiones, total, docentes, editable }: Props) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeCrear = usePuede('sesiones.crear') && editable;
  const puedeEditar = usePuede('sesiones.editar') && editable;
  const puedeCancelar = usePuede('sesiones.cancelar') && editable;
  const puedeEnlace = usePuede('sesiones.ver-enlace');
  const [formulario, setFormulario] = useState<SesionClase | 'nueva' | null>(null);
  const [cancelando, setCancelando] = useState<SesionClase | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [enlaces, setEnlaces] = useState<Record<string, string>>({});
  const [generando, setGenerando] = useState(false);
  const [errorAccion, setErrorAccion] = useState<string | null>(null);

  if (sesiones === null) return <Alerta tipo="info">No tienes permiso para ver las sesiones.</Alerta>;

  const accion = async (destino: string, metodo: 'POST', cuerpo?: unknown, exito?: string) => {
    setErrorAccion(null);
    const r = await ejecutar<{ message?: string }>(destino, metodo, cuerpo);
    if (!r.ok) return setErrorAccion(r.error.message);
    mostrar('exito', exito ?? r.datos.message ?? 'Listo.');
    router.refresh();
  };

  const generar = async () => {
    setGenerando(true);
    setErrorAccion(null);
    const r = await ejecutar<{ message: string; data: { creadas: number; omitidas_total: number } }>(`academico/convocatorias/${convocatoriaId}/sesiones/generar`, 'POST', {});
    setGenerando(false);
    if (!r.ok) return setErrorAccion(r.error.message);
    mostrar('exito', `${r.datos.data.creadas} sesión(es) generada(s)${r.datos.data.omitidas_total > 0 ? `; ${r.datos.data.omitidas_total} omitida(s) por existir o cruzarse` : ''}.`);
    router.refresh();
  };

  const verEnlace = async (id: string) => {
    setErrorAccion(null);
    const r = await ejecutar<{ data: { enlace_virtual: string } }>(`academico/sesiones/${id}/enlace`, 'GET');
    if (!r.ok) return setErrorAccion(r.error.message);
    setEnlaces((previos) => ({ ...previos, [id]: r.datos.data.enlace_virtual }));
  };

  return (
    <div className="space-y-4">
      {errorAccion ? <Alerta tipo="error">{errorAccion}</Alerta> : null}
      {puedeCrear ? (
        <div className="flex flex-wrap gap-2">
          <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setFormulario('nueva')}>Programar sesión</Boton>
          <Boton variante="secundario" icono={<Wand2 className="h-4 w-4" aria-hidden="true" />} onClick={generar} cargando={generando}>Generar desde el horario</Boton>
        </div>
      ) : null}

      {sesiones.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay sesiones programadas.</p>
      ) : (
        <ul className="divide-y divide-panel-border rounded-xl border border-panel-border bg-panel-surface">
          {sesiones.map((s) => (
            <li key={s.identificador} className="space-y-2 px-4 py-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p className="font-semibold text-panel-text">
                    Sesión {s.numero} · {fecha(s.fecha)} · {s.hora_inicio}–{s.hora_fin}
                  </p>
                  <p className="text-sm text-panel-muted">
                    {[s.tema, s.docente ? `${s.docente.nombres} ${s.docente.apellidos}` : null, s.aula ? `${s.aula.codigo} · ${s.aula.nombre}` : null, s.plataforma_virtual?.nombre].filter(Boolean).join(' · ') || 'Sin detalles'}
                  </p>
                  {s.estado === 'cancelada' && s.motivo_cancelacion ? <p className="text-sm text-estado-error">Motivo: {s.motivo_cancelacion}</p> : null}
                </div>
                <Insignia color={COLORES[s.estado]}>{ESTADOS[s.estado]}</Insignia>
              </div>
              <div className="flex flex-wrap gap-1">
                <AsistenciaSesion sesion={s} />
              </div>
              {s.estado === 'programada' ? (
                <div className="flex flex-wrap gap-1">
                  {puedeEditar ? <Boton variante="fantasma" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setFormulario(s)}>Editar</Boton> : null}
                  {puedeEditar ? <Boton variante="fantasma" icono={<Check className="h-4 w-4" aria-hidden="true" />} onClick={() => accion(`academico/sesiones/${s.identificador}/realizada`, 'POST', {}, 'La sesión se marcó como realizada.')}>Marcar realizada</Boton> : null}
                  {puedeCancelar ? <Boton variante="fantasma" icono={<X className="h-4 w-4" aria-hidden="true" />} onClick={() => setCancelando(s)}>Cancelar</Boton> : null}
                </div>
              ) : null}
              {s.tiene_enlace && puedeEnlace && s.estado !== 'cancelada' ? (
                enlaces[s.identificador] ? (
                  <p className="break-all text-sm">
                    <span className="font-medium">Enlace: </span>
                    <a href={enlaces[s.identificador]} target="_blank" rel="noopener noreferrer" className="text-academico-700 underline">{enlaces[s.identificador]}</a>
                  </p>
                ) : (
                  <Boton variante="fantasma" icono={<KeyRound className="h-4 w-4" aria-hidden="true" />} onClick={() => verEnlace(s.identificador)}>Ver enlace privado</Boton>
                )
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {total > sesiones.length ? <p className="text-xs text-panel-muted">Se muestran las primeras {sesiones.length} de {total} sesiones.</p> : null}

      <Dialogo abierto={formulario !== null} titulo={formulario === 'nueva' ? 'Programar sesión' : 'Editar sesión'} onCerrar={() => setFormulario(null)} bloqueado={ocupado}>
        {formulario !== null ? (
          <FormularioSesion
            key={formulario === 'nueva' ? 'nueva' : formulario.identificador}
            convocatoriaId={convocatoriaId}
            sesion={formulario === 'nueva' ? null : formulario}
            virtual={modalidad === 'virtual'}
            docentes={docentes}
            onOcupado={setOcupado}
            onCerrar={() => setFormulario(null)}
          />
        ) : null}
      </Dialogo>
      <Dialogo abierto={cancelando !== null} titulo="Cancelar sesión" onCerrar={() => setCancelando(null)} bloqueado={ocupado}>
        {cancelando ? <FormularioCancelar sesion={cancelando} onOcupado={setOcupado} onCerrar={() => setCancelando(null)} /> : null}
      </Dialogo>
    </div>
  );
}

function FormularioSesion({ convocatoriaId, sesion, virtual, docentes, onOcupado, onCerrar }: { convocatoriaId: string; sesion: SesionClase | null; virtual: boolean; docentes: readonly DocenteAsignado[]; onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const f = useFormularioPanel({
    esquema,
    valores: {
      numero: sesion ? String(sesion.numero) : '',
      fecha: sesion?.fecha ?? '',
      hora_inicio: sesion?.hora_inicio ?? '',
      hora_fin: sesion?.hora_fin ?? '',
      tema: sesion?.tema ?? '',
      docente: sesion?.docente?.identificador ?? '',
      enlace_virtual: '',
    },
    onOcupado,
    enviar: (d) => {
      const cuerpo: Record<string, unknown> = {
        numero: d.numero === '' ? null : Number(d.numero),
        fecha: d.fecha,
        hora_inicio: d.hora_inicio,
        hora_fin: d.hora_fin,
        tema: d.tema === '' ? null : d.tema,
        docente: d.docente === '' ? null : d.docente,
      };
      // Vacío = no tocar el enlace guardado (nunca se descarga para rellenar el formulario).
      if (virtual && d.enlace_virtual !== '') cuerpo.enlace_virtual = d.enlace_virtual;
      return sesion
        ? ejecutar<{ message: string }>(`academico/sesiones/${sesion.identificador}`, 'PUT', { ...cuerpo, _revision: sesion.revision })
        : ejecutar<{ message: string }>(`academico/convocatorias/${convocatoriaId}/sesiones`, 'POST', cuerpo);
    },
    onExito: (r) => {
      onCerrar();
      mostrar('exito', r.message);
      router.refresh();
    },
  });
  const opcionesDocente: Record<string, string> = { '': 'Sin docente', ...Object.fromEntries(docentes.map((d) => [d.docente, `${d.apellidos}, ${d.nombres}`])) };

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Fecha" type="date" {...f.registro('fecha')} error={f.error('fecha')} />
        <Campo etiqueta="Número" inputMode="numeric" {...f.registro('numero')} error={f.error('numero')} ayuda="Vacío: se asigna el siguiente." />
        <Campo etiqueta="Hora de inicio" type="time" {...f.registro('hora_inicio')} error={f.error('hora_inicio')} />
        <Campo etiqueta="Hora de fin" type="time" {...f.registro('hora_fin')} error={f.error('hora_fin')} />
      </div>
      <Campo etiqueta="Tema" {...f.registro('tema')} error={f.error('tema')} />
      <CampoSeleccion etiqueta="Docente" {...f.registro('docente')} error={f.error('docente')} opciones={opcionesDocente} ayuda="Solo docentes asignados a la edición." />
      {virtual ? (
        <Campo
          etiqueta="Enlace de la clase (privado)"
          type="url"
          autoComplete="off"
          {...f.registro('enlace_virtual')}
          error={f.error('enlace_virtual')}
          ayuda={sesion?.tiene_enlace ? 'Ya hay un enlace guardado; escribe uno nuevo solo para reemplazarlo.' : 'Debe ser https. Se guarda cifrado.'}
        />
      ) : null}
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando} icono={<CalendarPlus className="h-4 w-4" aria-hidden="true" />}>Guardar</Boton>
      </div>
    </form>
  );
}

const esquemaCancelar = z.object({ motivo: z.string().trim().min(1, 'Indica el motivo.').max(200, 'Hasta 200 caracteres.') });

function FormularioCancelar({ sesion, onOcupado, onCerrar }: { sesion: SesionClase; onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const f = useFormularioPanel({
    esquema: esquemaCancelar,
    valores: { motivo: '' },
    onOcupado,
    enviar: (d) => ejecutar<{ message: string }>(`academico/sesiones/${sesion.identificador}/cancelar`, 'POST', { motivo: d.motivo, _revision: sesion.revision }),
    onExito: (r) => {
      onCerrar();
      mostrar('exito', r.message);
      router.refresh();
    },
  });

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <p className="text-sm text-panel-muted">Sesión {sesion.numero} del {fecha(sesion.fecha)}. Una sesión cancelada no se puede reactivar.</p>
      <Campo etiqueta="Motivo" {...f.registro('motivo')} error={f.error('motivo')} />
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Volver</Boton>
        <Boton type="submit" cargando={f.guardando}>Cancelar sesión</Boton>
      </div>
    </form>
  );
}
