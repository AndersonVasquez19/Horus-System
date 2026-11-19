'use client';

import { ClipboardCheck, FileSpreadsheet } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useCallback, useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { CampoSeleccion, Campo } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import { Tabla } from '@/components/shared/ui/tabla';
import type { EstadoAsistencia, ListaAsistencia, ResumenAsistenciaFila, SesionClase } from '@/features/academico/tipos';
import { fecha } from '@/lib/panel/formato';

const ESTADOS: Record<EstadoAsistencia, string> = { presente: 'Presente', ausente: 'Ausente', tardanza: 'Tardanza', justificada: 'Justificada' };
const COLORES = { presente: 'verde', ausente: 'rojo', tardanza: 'ambar', justificada: 'azul' } as const;

/** Botón y diálogo para tomar o corregir la asistencia de una sesión. Laravel valida todo de nuevo al guardar. */
export function AsistenciaSesion({ sesion }: { sesion: SesionClase }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeVer = usePuede('asistencias.ver');
  const puedeRegistrar = usePuede('asistencias.registrar');
  const puedeCorregir = usePuede('asistencias.corregir');
  const [abierto, setAbierto] = useState(false);
  const [lista, setLista] = useState<ListaAsistencia | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [elegidos, setElegidos] = useState<Record<number, EstadoAsistencia>>({});
  const [observaciones, setObservaciones] = useState<Record<number, string>>({});
  const [guardando, setGuardando] = useState(false);
  const [corrigiendo, setCorrigiendo] = useState<{ id: number; estado: EstadoAsistencia; motivo: string } | null>(null);

  const cargar = useCallback(async () => {
    setError(null);
    const r = await ejecutar<{ data: ListaAsistencia }>(`academico/sesiones/${sesion.identificador}/asistencias`, 'GET');
    if (!r.ok) return setError(r.error.message);
    setLista(r.datos.data);
    setElegidos(Object.fromEntries(r.datos.data.matriculas.filter((m) => m.estado === null).map((m) => [m.matricula_id, 'presente' as EstadoAsistencia])));
    setObservaciones(Object.fromEntries(r.datos.data.matriculas.filter((m) => m.estado === null).map((m) => [m.matricula_id, m.observacion ?? ''])));
  }, [ejecutar, sesion.identificador]);

  if (!puedeVer || sesion.estado === 'cancelada') return null;

  const pendientes = lista?.matriculas.filter((m) => m.estado === null) ?? [];

  function marcarTodosPresentes() {
    setElegidos(Object.fromEntries(pendientes.map((m) => [m.matricula_id, 'presente' as EstadoAsistencia])));
  }

  async function guardar() {
    setGuardando(true);
    setError(null);
    const r = await ejecutar<{ message: string }>(`academico/sesiones/${sesion.identificador}/asistencias`, 'PUT', {
      asistencias: pendientes.map((m) => ({
        matricula_id: m.matricula_id,
        estado: elegidos[m.matricula_id] ?? 'presente',
        observacion: (observaciones[m.matricula_id] ?? '').trim() || null,
      })),
    });
    setGuardando(false);
    if (!r.ok) return setError(r.error.message);
    mostrar('exito', r.datos.message);
    await cargar();
    router.refresh();
  }

  async function corregir() {
    if (!corrigiendo) return;
    setGuardando(true);
    setError(null);
    const r = await ejecutar<{ message: string }>(`academico/sesiones/${sesion.identificador}/asistencias/${corrigiendo.id}`, 'PUT', { estado: corrigiendo.estado, motivo: corrigiendo.motivo });
    setGuardando(false);
    if (!r.ok) return setError(r.error.message);
    mostrar('exito', r.datos.message);
    setCorrigiendo(null);
    await cargar();
    router.refresh();
  }

  return (
    <>
      <Boton variante="fantasma" icono={<ClipboardCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => { setLista(null); setAbierto(true); void cargar(); }}>Asistencia</Boton>
      <Dialogo abierto={abierto} titulo={`Asistencia · sesión ${sesion.numero}`} descripcion={`${fecha(sesion.fecha)} · ${sesion.hora_inicio}–${sesion.hora_fin}`} onCerrar={() => setAbierto(false)} bloqueado={guardando}>
        <div className="space-y-4">
          {error ? <Alerta tipo="error">{error}</Alerta> : null}
          {lista === null && !error ? <p className="text-sm text-panel-muted" aria-busy="true">Cargando matrículas…</p> : null}
          {lista && lista.matriculas.length === 0 ? <p className="text-sm text-panel-muted">La edición no tiene matrículas vigentes.</p> : null}
          {lista && lista.matriculas.length > 0 ? (
            <>
              {puedeRegistrar && pendientes.length > 0 ? (
                <div className="flex items-center justify-between gap-2">
                  <span className="text-xs text-panel-muted">{pendientes.length} por registrar</span>
                  <Boton variante="secundario" onClick={marcarTodosPresentes} disabled={guardando}>Todos presentes</Boton>
                </div>
              ) : null}
              <ul className="divide-y divide-panel-border rounded-xl border border-panel-border">
                {lista.matriculas.map((m) => (
                  <li key={m.matricula_id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-panel-text">{m.estudiante.nombre}</p>
                      <p className="font-mono text-xs text-panel-muted">{m.estudiante.dni}</p>
                      {m.estado !== null && m.observacion ? (
                        <p className="mt-0.5 text-xs text-panel-muted">Obs.: {m.observacion}</p>
                      ) : null}
                    </div>
                    {m.estado === null ? (
                      puedeRegistrar ? (
                        <div className="flex flex-wrap items-center gap-2">
                          <select
                            aria-label={`Asistencia de ${m.estudiante.nombre}`}
                            value={elegidos[m.matricula_id] ?? 'presente'}
                            onChange={(e) => setElegidos((p) => ({ ...p, [m.matricula_id]: e.target.value as EstadoAsistencia }))}
                            className="rounded-lg border border-panel-border bg-panel-surface px-2 py-1.5 text-sm"
                          >
                            {Object.entries(ESTADOS).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
                          </select>
                          <input
                            type="text"
                            aria-label={`Observación de ${m.estudiante.nombre}`}
                            placeholder="Observación (opcional)"
                            maxLength={200}
                            value={observaciones[m.matricula_id] ?? ''}
                            onChange={(e) => setObservaciones((p) => ({ ...p, [m.matricula_id]: e.target.value }))}
                            className="w-44 rounded-lg border border-panel-border bg-panel-surface px-2 py-1.5 text-sm text-panel-text"
                          />
                        </div>
                      ) : (
                        <span className="text-sm text-panel-muted">Sin registrar</span>
                      )
                    ) : (
                      <div className="flex items-center gap-2">
                        <Insignia color={COLORES[m.estado]}>{ESTADOS[m.estado]}</Insignia>
                        {m.corregida ? <span className="text-xs text-panel-muted">corregida</span> : null}
                        {puedeCorregir ? <Boton variante="fantasma" onClick={() => setCorrigiendo({ id: m.matricula_id, estado: m.estado ?? 'presente', motivo: '' })}>Corregir</Boton> : null}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </>
          ) : null}

          {corrigiendo ? (
            <div className="space-y-3 rounded-xl border border-panel-border bg-panel-surface p-3">
              <CampoSeleccion etiqueta="Nuevo estado" value={corrigiendo.estado} onChange={(e) => setCorrigiendo({ ...corrigiendo, estado: e.target.value as EstadoAsistencia })} opciones={ESTADOS} />
              <Campo etiqueta="Motivo de la corrección" value={corrigiendo.motivo} onChange={(e) => setCorrigiendo({ ...corrigiendo, motivo: e.target.value })} maxLength={200} />
              <div className="flex justify-end gap-2">
                <Boton variante="secundario" onClick={() => setCorrigiendo(null)} disabled={guardando}>Cancelar</Boton>
                <Boton onClick={() => void corregir()} cargando={guardando} disabled={corrigiendo.motivo.trim() === ''}>Guardar corrección</Boton>
              </div>
            </div>
          ) : null}

          {puedeRegistrar && pendientes.length > 0 ? (
            <div className="flex justify-end border-t border-panel-border pt-4">
              <Boton onClick={() => void guardar()} cargando={guardando}>Registrar {pendientes.length} asistencia(s)</Boton>
            </div>
          ) : null}
        </div>
      </Dialogo>
    </>
  );
}

/** Porcentaje de asistencia por matrícula y exportación a Excel. */
export function ResumenAsistenciaEdicion({ convocatoriaId, sesionesRealizadas, filas }: { convocatoriaId: string; sesionesRealizadas: number; filas: readonly ResumenAsistenciaFila[] }) {
  const { descargar } = useOperacionPanel();
  const puedeExportar = usePuede('asistencias.exportar');
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function exportar() {
    setOcupado(true);
    setError(null);
    const r = await descargar(`academico/convocatorias/${convocatoriaId}/asistencias/exportar`);
    setOcupado(false);
    if (!r.ok) setError(r.error.message);
  }

  return (
    <div className="space-y-3">
      {error ? <Alerta tipo="error">{error}</Alerta> : null}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-panel-muted">
          Sesiones realizadas: {sesionesRealizadas}. Cuentan presente y tardanza; la falta justificada no penaliza.
        </p>
        {puedeExportar ? (
          <Boton variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void exportar()}>Exportar Excel</Boton>
        ) : null}
      </div>
      {filas.length === 0 ? (
        <p className="text-sm text-panel-muted">No hay matrículas vigentes.</p>
      ) : (
        <Tabla titulo={`Asistencia (${filas.length})`} columnas={['Estudiante', 'DNI', 'Presentes', 'Tardanzas', 'Ausentes', 'Justificadas', '% asistencia']}>
          {filas.map((f) => (
            <tr key={f.matricula_id}>
              <td className="px-4 py-3">{f.estudiante.nombre}</td>
              <td className="px-4 py-3 font-mono text-xs text-panel-muted">{f.estudiante.dni}</td>
              <td className="px-4 py-3">{f.presentes}</td>
              <td className="px-4 py-3">{f.tardanzas}</td>
              <td className="px-4 py-3">{f.ausentes}</td>
              <td className="px-4 py-3">{f.justificadas}</td>
              <td className="px-4 py-3 font-semibold">{f.porcentaje === null ? '—' : `${f.porcentaje}%`}</td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
