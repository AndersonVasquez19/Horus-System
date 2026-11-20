'use client';

import { RotateCcw, Send } from 'lucide-react';
import { useId, useRef, useState, type FormEvent } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';

interface Turno {
  autor: 'persona' | 'asistente';
  texto: string;
}

/**
 * Probador del asistente virtual. La conversación vive solo en esta pantalla (no se guarda en el navegador); el servidor
 * aplica el filtro de privacidad y registra cada intercambio. Cada visita usa su propio identificador de sesión.
 */
export function ProbadorAsistente() {
  const { ejecutar } = useOperacionPanel();
  const idCampo = useId();
  const sesion = useRef<string | null>(null);
  const [mensaje, setMensaje] = useState('');
  const [turnos, setTurnos] = useState<Turno[]>([]);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    const texto = mensaje.trim();
    if (texto === '' || enviando) return;
    sesion.current ??= `panel-${crypto.randomUUID().replaceAll('-', '').slice(0, 24)}`;
    setEnviando(true);
    setError(null);
    setTurnos((anteriores) => [...anteriores, { autor: 'persona', texto }]);
    setMensaje('');

    const resultado = await ejecutar<{ respuesta: string }>('compartido/asistente/mensaje', 'POST', { mensaje: texto, session_id: sesion.current });
    setEnviando(false);
    if (resultado.ok) setTurnos((anteriores) => [...anteriores, { autor: 'asistente', texto: resultado.datos.respuesta }]);
    else setError(resultado.error.message);
  }

  function reiniciar() {
    sesion.current = null;
    setTurnos([]);
    setMensaje('');
    setError(null);
  }

  return (
    <div className="space-y-4">
      <div role="log" aria-live="polite" aria-label="Conversación de prueba" className="max-h-96 min-h-40 space-y-3 overflow-y-auto rounded-lg border border-panel-border bg-panel-bg p-4">
        {turnos.length === 0 ? <p className="text-sm text-panel-muted">Escribe un mensaje para ver cómo responde el asistente.</p> : null}
        {turnos.map((turno, indice) => (
          <div key={indice} className={turno.autor === 'persona' ? 'flex justify-end' : 'flex justify-start'}>
            <p className={`max-w-[85%] whitespace-pre-line rounded-2xl px-4 py-2 text-sm ${turno.autor === 'persona' ? 'bg-academico-700 text-white' : 'border border-panel-border bg-panel-surface text-panel-text'}`}>
              <span className="sr-only">{turno.autor === 'persona' ? 'Tú: ' : 'Asistente: '}</span>
              {turno.texto}
            </p>
          </div>
        ))}
      </div>

      {error ? <Alerta tipo="error">{error}</Alerta> : null}

      <form onSubmit={enviar} className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex-1">
          <label htmlFor={idCampo} className="mb-1 block text-sm font-medium text-panel-text">
            Mensaje de prueba
          </label>
          <input
            id={idCampo}
            value={mensaje}
            onChange={(evento) => setMensaje(evento.target.value)}
            maxLength={2000}
            autoComplete="off"
            className="w-full rounded-lg border border-slate-300 bg-panel-surface px-3 py-2 text-sm text-panel-text outline-none focus:border-academico-700 focus:ring-4 focus:ring-academico-700/15"
          />
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
