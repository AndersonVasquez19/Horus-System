'use client';

import { RotateCcw, RotateCw, Send } from 'lucide-react';
import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';

interface Turno {
  id: number;
  autor: 'persona' | 'asistente';
  texto: string;
  /** El envío de este mensaje falló: el texto se conserva y se puede reintentar. */
  fallido?: boolean;
}

const MAXIMO = 2000;
/** El contador solo aparece cuando el límite está cerca: antes sería ruido. */
const AVISO_DESDE = 1800;
const ALTO_MAXIMO_CAMPO = 160;

/**
 * Probador del asistente virtual. La conversación vive solo en esta pantalla (no se guarda en el navegador); el servidor
 * aplica el filtro de privacidad y registra cada intercambio. Cada visita usa su propio identificador de sesión.
 *
 * - El registro se desplaza solo hasta el último mensaje y muestra «escribiendo» mientras espera.
 * - Enter envía; Shift+Enter salta de línea. El campo crece con el texto.
 * - Si un envío falla, el mensaje no se pierde: queda marcado y se puede reintentar.
 */
export function ProbadorAsistente() {
  const { ejecutar } = useOperacionPanel();
  const idCampo = useId();
  const idContador = useId();
  const sesion = useRef<string | null>(null);
  const siguienteId = useRef(1);
  const registroRef = useRef<HTMLDivElement>(null);
  const campoRef = useRef<HTMLTextAreaElement>(null);
  const [mensaje, setMensaje] = useState('');
  const [turnos, setTurnos] = useState<Turno[]>([]);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Siempre al último mensaje. Es solo DOM: no cambia estado.
  useEffect(() => {
    const registro = registroRef.current;
    if (registro) registro.scrollTop = registro.scrollHeight;
  }, [turnos, enviando]);

  /** Pide la respuesta de un mensaje ya mostrado. Marca el turno como fallido si el servidor no responde. */
  async function pedirRespuesta(turnoId: number, texto: string) {
    sesion.current ??= `panel-${crypto.randomUUID().replaceAll('-', '').slice(0, 24)}`;
    setEnviando(true);
    setError(null);
    setTurnos((actuales) => actuales.map((t) => (t.id === turnoId ? { ...t, fallido: false } : t)));

    const resultado = await ejecutar<{ respuesta: string }>('compartido/asistente/mensaje', 'POST', { mensaje: texto, session_id: sesion.current });
    setEnviando(false);

    if (resultado.ok) {
      setTurnos((actuales) => [...actuales, { id: siguienteId.current++, autor: 'asistente', texto: resultado.datos.respuesta }]);
    } else {
      setTurnos((actuales) => actuales.map((t) => (t.id === turnoId ? { ...t, fallido: true } : t)));
      setError(resultado.error.message);
    }
  }

  async function enviar(evento?: FormEvent<HTMLFormElement>) {
    evento?.preventDefault();
    const texto = mensaje.trim();
    if (texto === '' || enviando) return;
    const turnoId = siguienteId.current++;
    setTurnos((anteriores) => [...anteriores, { id: turnoId, autor: 'persona', texto }]);
    setMensaje('');
    if (campoRef.current) campoRef.current.style.height = 'auto';
    await pedirRespuesta(turnoId, texto);
  }

  function reiniciar() {
    sesion.current = null;
    setTurnos([]);
    setMensaje('');
    setError(null);
  }

  function alEscribir(valor: string, campo: HTMLTextAreaElement) {
    setMensaje(valor);
    campo.style.height = 'auto';
    campo.style.height = `${Math.min(campo.scrollHeight, ALTO_MAXIMO_CAMPO)}px`;
  }

  function alPulsarTecla(evento: KeyboardEvent<HTMLTextAreaElement>) {
    // Enter envía; Shift+Enter salta de línea. No se interfiere con la composición de texto (IME).
    if (evento.key === 'Enter' && !evento.shiftKey && !evento.nativeEvent.isComposing) {
      evento.preventDefault();
      evento.currentTarget.form?.requestSubmit();
    }
  }

  const cerca = mensaje.length >= AVISO_DESDE;

  return (
    <div className="space-y-4">
      <div
        ref={registroRef}
        role="log"
        aria-live="polite"
        aria-label="Conversación de prueba"
        className="max-h-96 min-h-40 space-y-3 overflow-y-auto rounded-lg border border-panel-border bg-panel-bg p-4"
      >
        {turnos.length === 0 ? <p className="text-sm text-panel-muted">Escribe un mensaje para ver cómo responde el asistente.</p> : null}
        {turnos.map((turno) => (
          <div key={turno.id} className={turno.autor === 'persona' ? 'flex flex-col items-end gap-1' : 'flex flex-col items-start gap-1'}>
            <p
              className={`max-w-[85%] whitespace-pre-line break-words rounded-2xl px-4 py-2 text-sm ${
                turno.autor === 'persona' ? 'bg-academico-700 text-white' : 'border border-panel-border bg-panel-surface text-panel-text'
              } ${turno.fallido ? 'opacity-70 ring-2 ring-estado-error' : ''}`}
            >
              <span className="sr-only">{turno.autor === 'persona' ? 'Tú: ' : 'Asistente: '}</span>
              {turno.texto}
            </p>
            {turno.fallido ? (
              <div className="flex items-center gap-2 text-sm text-estado-error">
                <span role="alert">No se pudo enviar este mensaje.</span>
                <button
                  type="button"
                  onClick={() => void pedirRespuesta(turno.id, turno.texto)}
                  disabled={enviando}
                  className="inline-flex min-h-8 items-center gap-1 rounded-md px-2 font-semibold underline underline-offset-2 hover:bg-red-50 disabled:opacity-60"
                >
                  <RotateCw className="h-3.5 w-3.5" aria-hidden="true" /> Reintentar
                </button>
              </div>
            ) : null}
          </div>
        ))}
        {enviando ? (
          <div className="flex justify-start">
            <p className="rounded-2xl border border-panel-border bg-panel-surface px-4 py-2 text-sm text-panel-muted motion-safe:animate-pulse">
              El asistente está escribiendo…
            </p>
          </div>
        ) : null}
      </div>

      {error ? <Alerta tipo="error">{error}</Alerta> : null}

      <form onSubmit={enviar} className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex-1">
          <label htmlFor={idCampo} className="mb-1.5 block text-sm font-medium text-panel-text">
            Mensaje de prueba
          </label>
          <textarea
            ref={campoRef}
            id={idCampo}
            rows={1}
            value={mensaje}
            onChange={(evento) => alEscribir(evento.target.value, evento.target)}
            onKeyDown={alPulsarTecla}
            maxLength={MAXIMO}
            autoComplete="off"
            aria-describedby={cerca ? idContador : undefined}
            className="block max-h-40 w-full resize-none rounded-lg border border-panel-border bg-panel-surface px-3 py-2.5 text-sm text-panel-text shadow-sm outline-none transition placeholder:text-slate-500 focus:border-academico-700 focus:ring-4 focus:ring-academico-700/30"
          />
          <div className="mt-1.5 flex items-start justify-between gap-3 text-xs text-panel-muted">
            <span>Enter envía · Shift+Enter salta de línea</span>
            {cerca ? (
              <span id={idContador} aria-live="polite" className={mensaje.length >= MAXIMO ? 'font-semibold text-estado-error' : ''}>
                {mensaje.length}/{MAXIMO}
              </span>
            ) : null}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {turnos.length > 0 ? (
            <Boton type="button" variante="secundario" disabled={enviando} icono={<RotateCcw className="h-4 w-4" aria-hidden="true" />} onClick={reiniciar}>
              Nueva conversación
            </Boton>
          ) : null}
          <Boton type="submit" cargando={enviando} icono={<Send className="h-4 w-4" aria-hidden="true" />}>
            {enviando ? 'Enviando…' : 'Enviar'}
          </Boton>
        </div>
      </form>
      <p className="text-xs text-panel-muted">No escribas datos personales reales: el servidor los omite del registro, pero esto es solo un probador.</p>
    </div>
  );
}
