'use client';

import { CheckCheck, FileSpreadsheet, Grid3x3, Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type ClipboardEvent, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import type { ActaCalificaciones, EvaluacionActa, FilaActa } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

type Respuesta = { message: string; data: ActaCalificaciones };

type Objetivo =
  | { tipo: 'nota'; fila: FilaActa; evaluacion: EvaluacionActa }
  | { tipo: 'evaluacion'; evaluacion: EvaluacionActa | null }
  | { tipo: 'cerrar' }
  | null;

/**
 * Evaluaciones, notas y acta de una convocatoria. Toda la regla vive en Laravel (GestionCalificaciones): pesos, rango, motivo
 * de corrección, estado de la convocatoria y cierre. Aquí solo se muestran las acciones que el permiso y el estado permiten.
 */
export function PanelCalificaciones({ acta }: { acta: ActaCalificaciones }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, descargar } = useOperacionPanel();
  const puedeRegistrar = usePuede('calificaciones.registrar');
  const puedeCorregir = usePuede('calificaciones.corregir');
  const puedeExportar = usePuede('calificaciones.exportar');
  const puedeCerrar = usePuede('convocatorias.cerrar');
  const [objetivo, setObjetivo] = useState<Objetivo>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [modoRapido, setModoRapido] = useState(false);
  const [borradores, setBorradores] = useState<Record<string, string>>({});
  const [motivoLote, setMotivoLote] = useState('');
  const [errorRapido, setErrorRapido] = useState<string | null>(null);

  const { convocatoria, evaluaciones, matriculas } = acta;
  const enCurso = convocatoria.estado === 'en_curso';
  const cerrada = convocatoria.estado === 'cerrada';
  const estructuraEditable = ['borrador', 'abierta', 'en_curso'].includes(convocatoria.estado);
  const pesosCompletos = acta.peso_total === '100.00';
  const base = `academico/convocatorias/${convocatoria.identificador}`;

  const cerrarDialogo = () => {
    setObjetivo(null);
    setError(null);
  };

  /** Ejecuta una operación y, si sale bien, cierra el diálogo y relee. Una revisión o nota obsoleta también relee. */
  async function operar(ruta: string, metodo: 'POST' | 'PUT', cuerpo: Record<string, string | number | null>) {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<Respuesta>(ruta, metodo, cuerpo);
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 412 || resultado.estado === 409) router.refresh();
      return false;
    }
    cerrarDialogo();
    mostrar('exito', resultado.datos.message);
    router.refresh();
    return true;
  }

  async function exportar(borrador = false) {
    setOcupado(true);
    const resultado = await descargar(`${base}/${borrador ? 'acta/borrador/exportar' : 'acta/exportar'}`);
    setOcupado(false);
    if (resultado.ok) mostrar('exito', borrador ? 'Borrador del acta generado.' : 'Acta generada. La exportación quedó registrada.');
    else mostrar('error', resultado.error.message);
  }

  function abrirModoRapido() {
    const iniciales: Record<string, string> = {};
    for (const f of matriculas) {
      for (const e of evaluaciones) {
        iniciales[`${f.id}:${e.identificador}`] = f.notas[e.identificador] ?? '';
      }
    }
    setBorradores(iniciales);
    setMotivoLote('');
    setErrorRapido(null);
    setModoRapido(true);
  }

  function pegarColumna(evento: ClipboardEvent<HTMLInputElement>, filaInicio: number, evaluacionId: string) {
    const texto = evento.clipboardData.getData('text');
    const lineas = texto.split(/\r?\n/).map((l) => l.split('\t')[0]?.trim() ?? '');
    if (lineas.length <= 1) return;
    evento.preventDefault();
    setBorradores((prev) => {
      const siguiente = { ...prev };
      lineas.forEach((valor, offset) => {
        const filaDestino = matriculas[filaInicio + offset];
        if (!filaDestino || valor === '') return;
        const actual = filaDestino.notas[evaluacionId];
        const editable = enCurso && (actual === undefined ? puedeRegistrar : puedeCorregir);
        if (editable) siguiente[`${filaDestino.id}:${evaluacionId}`] = valor.replace(',', '.');
      });
      return siguiente;
    });
  }

  async function guardarLote() {
    setErrorRapido(null);
    const cambios: { matricula_id: number; evaluacion: string; nota: string; nota_actual: string | null }[] = [];
    let requiereMotivo = false;

    for (const f of matriculas) {
      for (const e of evaluaciones) {
        const claveCelda = `${f.id}:${e.identificador}`;
        const actual = f.notas[e.identificador];
        const nuevo = (borradores[claveCelda] ?? '').trim().replace(',', '.');
        if (nuevo === (actual ?? '')) continue;
        if (nuevo === '' && actual !== undefined) {
          setErrorRapido(`No puedes dejar vacía la nota ya registrada de ${f.estudiante.nombre} (${e.nombre}).`);
          return;
        }
        if (nuevo === '') continue;
        if (!/^\d{1,2}(\.\d{1,2})?$/.test(nuevo) || Number(nuevo) < 0 || Number(nuevo) > 20) {
          setErrorRapido(`Nota inválida para ${f.estudiante.nombre} en ${e.nombre}: usa un valor de 0 a 20.`);
          return;
        }
        if (actual !== undefined) requiereMotivo = true;
        cambios.push({ matricula_id: f.id, evaluacion: e.identificador, nota: nuevo, nota_actual: actual ?? null });
      }
    }

    if (cambios.length === 0) {
      setModoRapido(false);
      return;
    }
    if (requiereMotivo && motivoLote.trim().length < 3) {
      setErrorRapido('Indica un motivo de corrección (al menos 3 caracteres) para las notas modificadas que ya tenían registro.');
      return;
    }

    setOcupado(true);
    let guardadas = 0;
    for (const c of cambios) {
      const r = await ejecutar<Respuesta>(`${base}/calificaciones`, 'PUT', {
        evaluacion: c.evaluacion,
        matricula_id: c.matricula_id,
        nota: c.nota,
        nota_actual: c.nota_actual,
        motivo: c.nota_actual === null ? null : motivoLote.trim(),
      });
      if (!r.ok) {
        setOcupado(false);
        setErrorRapido(guardadas > 0
          ? `Se guardaron ${guardadas} nota(s) y falló el resto: ${r.error.message}`
          : r.error.message);
        router.refresh();
        return;
      }
      guardadas++;
    }
    setOcupado(false);
    setModoRapido(false);
    setMotivoLote('');
    mostrar('exito', guardadas === 1 ? '1 nota guardada.' : `${guardadas} notas guardadas.`);
    router.refresh();
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Insignia color={pesosCompletos ? 'verde' : 'ambar'}>Pesos: {acta.peso_total}% de 100%</Insignia>
        <span className="text-xs text-panel-muted">Nota mínima aprobatoria: {acta.nota_aprobatoria}</span>
        <div className="ml-auto flex flex-wrap gap-2">
          {enCurso && (puedeRegistrar || puedeCorregir) && evaluaciones.length > 0 && matriculas.length > 0 ? (
            modoRapido ? (
              <>
                <Boton variante="secundario" icono={<X className="h-4 w-4" aria-hidden="true" />} disabled={ocupado} onClick={() => setModoRapido(false)}>
                  Salir de cuadrícula
                </Boton>
                <Boton icono={<Save className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void guardarLote()}>
                  Guardar notas
                </Boton>
              </>
            ) : (
              <Boton variante="secundario" icono={<Grid3x3 className="h-4 w-4" aria-hidden="true" />} disabled={ocupado} onClick={abrirModoRapido}>
                Captura rápida
              </Boton>
            )
          ) : null}
          {puedeCorregir && estructuraEditable && !modoRapido ? (
            <Boton variante="secundario" icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setObjetivo({ tipo: 'evaluacion', evaluacion: null })}>
              Nueva evaluación
            </Boton>
          ) : null}
          {puedeCerrar && enCurso && !modoRapido ? (
            <Boton icono={<CheckCheck className="h-4 w-4" aria-hidden="true" />} disabled={!pesosCompletos} onClick={() => setObjetivo({ tipo: 'cerrar' })}>
              Cerrar acta
            </Boton>
          ) : null}
          {puedeExportar && !cerrada && evaluaciones.length > 0 && matriculas.length > 0 ? (
            <Boton variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void exportar(true)}>
              Exportar borrador Excel
            </Boton>
          ) : null}
          {puedeExportar && cerrada ? (
            <Boton variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} cargando={ocupado} onClick={() => void exportar(false)}>
              Exportar acta
            </Boton>
          ) : null}
        </div>
      </div>

      {modoRapido ? (
        <div className="space-y-3 rounded-xl border border-academico-200 bg-academico-50/50 p-3 text-sm">
          <p className="text-xs text-academico-900">
            Edita las notas en línea o pega una columna de Excel directamente en la primera celda a rellenar. Al finalizar pulsa «Guardar notas».
          </p>
          <Campo
            etiqueta="Motivo de corrección (solo necesario si editas notas ya registradas)"
            value={motivoLote}
            onChange={(e) => setMotivoLote(e.target.value)}
            maxLength={200}
            placeholder="Ej. Recalificación de examen"
          />
          {errorRapido ? <Alerta tipo="error">{errorRapido}</Alerta> : null}
        </div>
      ) : null}

      {evaluaciones.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay evaluaciones. Crea una para empezar a registrar notas.</p>
      ) : matriculas.length === 0 ? (
        <p className="text-sm text-panel-muted">No hay matrículas vigentes en esta convocatoria.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-panel-border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">Notas de la convocatoria {convocatoria.codigo}</caption>
            <thead className="border-b border-panel-border bg-panel-bg text-xs uppercase tracking-wide text-panel-muted">
              <tr>
                <th scope="col" className="px-3 py-2 font-semibold">Estudiante</th>
                {evaluaciones.map((e) => (
                  <th key={e.identificador} scope="col" className="px-3 py-2 text-right font-semibold">
                    {puedeCorregir && estructuraEditable ? (
                      <button type="button" className="inline-flex items-center gap-1 uppercase hover:text-academico-800" onClick={() => setObjetivo({ tipo: 'evaluacion', evaluacion: e })} aria-label={`Editar la evaluación ${e.nombre}`}>
                        {e.nombre} <Pencil className="h-3 w-3" aria-hidden="true" />
                      </button>
                    ) : (
                      e.nombre
                    )}
                    <span className="block font-normal normal-case">{e.peso}%</span>
                  </th>
                ))}
                {acta.asistencia_visible ? <th scope="col" className="px-3 py-2 text-right font-semibold">% asistencia</th> : null}
                <th scope="col" className="px-3 py-2 text-right font-semibold">Nota final</th>
                <th scope="col" className="px-3 py-2 font-semibold">Resultado</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-panel-border">
              {matriculas.map((fila, indiceFila) => (
                <tr key={fila.id}>
                  <th scope="row" className="px-3 py-2 text-left font-medium text-panel-text">
                    {fila.estudiante.nombre}
                    <span className="block font-mono text-xs font-normal text-panel-muted">{fila.estudiante.dni}</span>
                  </th>
                  {evaluaciones.map((e) => {
                    const nota = fila.notas[e.identificador];
                    const puede = enCurso && (nota === undefined ? puedeRegistrar : puedeCorregir);
                    const claveCelda = `${fila.id}:${e.identificador}`;
                    return (
                      <td key={e.identificador} className="px-3 py-2 text-right tabular-nums">
                        {modoRapido && puede ? (
                          <input
                            type="text"
                            inputMode="decimal"
                            value={borradores[claveCelda] ?? ''}
                            onChange={(ev) => setBorradores((prev) => ({ ...prev, [claveCelda]: ev.target.value }))}
                            onPaste={(ev) => pegarColumna(ev, indiceFila, e.identificador)}
                            disabled={ocupado}
                            aria-label={`Nota de ${fila.estudiante.nombre} en ${e.nombre}`}
                            className="w-20 rounded-md border border-academico-300 bg-panel-surface px-2 py-1 text-right text-sm tabular-nums text-panel-text outline-none focus:border-academico-700 focus:ring-2 focus:ring-academico-700/20"
                          />
                        ) : puede ? (
                          <button
                            type="button"
                            className="rounded-md px-2 py-1 font-semibold text-academico-800 hover:bg-academico-50"
                            onClick={() => setObjetivo({ tipo: 'nota', fila, evaluacion: e })}
                            aria-label={`${nota === undefined ? 'Registrar' : 'Corregir'} la nota de ${fila.estudiante.nombre} en ${e.nombre}`}
                          >
                            {nota ?? '—'}
                          </button>
                        ) : (
                          <span className="px-2 py-1">{nota ?? '—'}</span>
                        )}
                      </td>
                    );
                  })}
                  {acta.asistencia_visible ? <td className="px-3 py-2 text-right tabular-nums">{fila.asistencia === null ? '—' : `${fila.asistencia}%`}</td> : null}
                  <td className="px-3 py-2 text-right font-semibold tabular-nums">{fila.nota_final ?? '—'}</td>
                  <td className="px-3 py-2">
                    {fila.resultado ? <Insignia color={fila.resultado === 'aprobado' ? 'verde' : 'rojo'}>{fila.resultado === 'aprobado' ? 'Aprobado' : 'Desaprobado'}</Insignia> : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {objetivo?.tipo === 'nota' ? (
        <DialogoNota
          key={`${objetivo.fila.id}-${objetivo.evaluacion.identificador}`}
          objetivo={objetivo}
          ocupado={ocupado}
          error={error}
          onCerrar={cerrarDialogo}
          onGuardar={(cuerpo) => operar(`${base}/calificaciones`, 'PUT', cuerpo)}
        />
      ) : null}

      {objetivo?.tipo === 'evaluacion' ? (
        <DialogoEvaluacion
          key={objetivo.evaluacion?.identificador ?? 'nueva'}
          evaluacion={objetivo.evaluacion}
          ocupado={ocupado}
          error={error}
          onCerrar={cerrarDialogo}
          onGuardar={(cuerpo) =>
            objetivo.evaluacion
              ? operar(`${base}/evaluaciones/${objetivo.evaluacion.identificador}`, 'PUT', { ...cuerpo, _revision: convocatoria.revision })
              : operar(`${base}/evaluaciones`, 'POST', { ...cuerpo, _revision: convocatoria.revision })
          }
          onEliminar={objetivo.evaluacion && !objetivo.evaluacion.con_notas
            ? () => operar(`${base}/evaluaciones/${objetivo.evaluacion?.identificador}/eliminar`, 'POST', { _revision: convocatoria.revision })
            : undefined}
        />
      ) : null}

      <DialogoConfirmacion
        abierto={objetivo?.tipo === 'cerrar'}
        titulo="Cerrar acta"
        texto={
          <>
            Se calculará la nota final de cada matrícula y la convocatoria <strong>{convocatoria.codigo}</strong> quedará cerrada. Después no se
            modifican notas ni evaluaciones. Faltando alguna nota, el cierre se rechaza.
          </>
        }
        confirmar="Cerrar acta"
        cargando={ocupado}
        error={error?.message ?? null}
        onConfirmar={() => void operar(`${base}/acta/cerrar`, 'POST', { _revision: convocatoria.revision })}
        onCerrar={cerrarDialogo}
      />
    </div>
  );
}

function DialogoNota({ objetivo, ocupado, error, onCerrar, onGuardar }: {
  objetivo: Extract<Objetivo, { tipo: 'nota' }>;
  ocupado: boolean;
  error: ErrorPanel | null;
  onCerrar: () => void;
  onGuardar: (cuerpo: Record<string, string | number | null>) => Promise<boolean>;
}) {
  const { fila, evaluacion } = objetivo;
  const actual = fila.notas[evaluacion.identificador];
  const [nota, setNota] = useState(actual ?? '');
  const [motivo, setMotivo] = useState('');

  function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    void onGuardar({
      evaluacion: evaluacion.identificador,
      matricula_id: fila.id,
      nota: nota.trim(),
      nota_actual: actual ?? null,
      motivo: actual === undefined || motivo.trim() === '' ? null : motivo.trim(),
    });
  }

  return (
    <Dialogo abierto titulo={actual === undefined ? 'Registrar nota' : 'Corregir nota'} descripcion={`${fila.estudiante.nombre} · ${evaluacion.nombre}`} onCerrar={onCerrar} bloqueado={ocupado} ancho="sm">
      <form onSubmit={enviar} noValidate className="space-y-4">
        {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
        <Campo etiqueta="Nota (0 a 20)" type="number" inputMode="decimal" min="0" max="20" step="0.01" value={nota} onChange={(e) => setNota(e.target.value)} error={errorDeCampo(error, 'nota')} required autoFocus />
        {actual !== undefined ? (
          <Campo etiqueta="Motivo de la corrección" maxLength={200} value={motivo} onChange={(e) => setMotivo(e.target.value)} error={errorDeCampo(error, 'motivo')} ayuda={`Nota actual: ${actual}. Queda en la auditoría.`} required />
        ) : null}
        <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
          <Boton variante="secundario" onClick={onCerrar} disabled={ocupado}>Cancelar</Boton>
          <Boton type="submit" cargando={ocupado}>Guardar nota</Boton>
        </div>
      </form>
    </Dialogo>
  );
}

function DialogoEvaluacion({ evaluacion, ocupado, error, onCerrar, onGuardar, onEliminar }: {
  evaluacion: EvaluacionActa | null;
  ocupado: boolean;
  error: ErrorPanel | null;
  onCerrar: () => void;
  onGuardar: (cuerpo: Record<string, string | number | null>) => Promise<boolean>;
  onEliminar?: () => Promise<boolean>;
}) {
  const [nombre, setNombre] = useState(evaluacion?.nombre ?? '');
  const [peso, setPeso] = useState(evaluacion?.peso ?? '');
  const [fecha, setFecha] = useState(evaluacion?.fecha ?? '');

  function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    void onGuardar({ nombre: nombre.trim(), peso: peso.trim(), fecha: fecha === '' ? null : fecha });
  }

  return (
    <Dialogo abierto titulo={evaluacion ? 'Editar evaluación' : 'Nueva evaluación'} onCerrar={onCerrar} bloqueado={ocupado} ancho="sm">
      <form onSubmit={enviar} noValidate className="space-y-4">
        {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
        <Campo etiqueta="Nombre" maxLength={120} value={nombre} onChange={(e) => setNombre(e.target.value)} error={errorDeCampo(error, 'nombre')} required autoFocus />
        <Campo etiqueta="Peso (%)" type="number" inputMode="decimal" min="0.01" max="100" step="0.01" value={peso} onChange={(e) => setPeso(e.target.value)} error={errorDeCampo(error, 'peso')} ayuda="La suma de pesos no puede pasar de 100 y debe ser 100 para cerrar el acta." required />
        <Campo etiqueta="Fecha (opcional)" type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} error={errorDeCampo(error, 'fecha')} />
        <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
          {onEliminar ? (
            <Boton variante="secundario" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => void onEliminar()} disabled={ocupado} className="sm:mr-auto">Eliminar</Boton>
          ) : null}
          <Boton variante="secundario" onClick={onCerrar} disabled={ocupado}>Cancelar</Boton>
          <Boton type="submit" cargando={ocupado}>Guardar</Boton>
        </div>
      </form>
    </Dialogo>
  );
}
