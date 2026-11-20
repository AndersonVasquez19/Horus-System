'use client';

import { useState, type FormEvent } from 'react';

const CARITAS = [
  { valor: 3, emoji: '😄', etiqueta: 'Muy bueno' },
  { valor: 2, emoji: '😐', etiqueta: 'Normal' },
  { valor: 1, emoji: '😞', etiqueta: 'Por mejorar' },
] as const;

export function EncuestaCliente({ token, curso, yaRespondida }: { token: string; curso: string; yaRespondida: boolean }) {
  const [calificacion, setCalificacion] = useState<number | null>(null);
  const [sugerencia, setSugerencia] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [enviada, setEnviada] = useState(yaRespondida);
  const [error, setError] = useState<string | null>(null);

  if (enviada) {
    return (
      <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center">
        <p className="text-2xl">🎉</p>
        <h2 className="mt-2 text-lg font-semibold text-emerald-900">¡Gracias por tu respuesta!</h2>
        <p className="mt-1 text-sm text-emerald-700">Tu opinión sobre {curso} nos ayuda a seguir mejorando.</p>
      </div>
    );
  }

  async function enviar(e: FormEvent) {
    e.preventDefault();
    if (calificacion === null) {
      setError('Elige una carita para continuar.');
      return;
    }
    setEnviando(true);
    setError(null);
    const res = await fetch(`/api/encuestas/${encodeURIComponent(token)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ calificacion, sugerencia_curso: sugerencia.trim() || null }),
    });
    setEnviando(false);
    if (!res.ok) {
      const json = (await res.json().catch(() => ({}))) as { message?: string };
      setError(json.message ?? 'No se pudo enviar tu respuesta.');
      return;
    }
    setEnviada(true);
  }

  return (
    <form onSubmit={(e) => void enviar(e)} className="space-y-6">
      {error ? <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p> : null}

      <div className="grid grid-cols-3 gap-3">
        {CARITAS.map((c) => (
          <button
            key={c.valor}
            type="button"
            onClick={() => setCalificacion(c.valor)}
            className={`flex flex-col items-center gap-1 rounded-xl border p-4 transition ${
              calificacion === c.valor ? 'border-sky-600 bg-sky-50 ring-2 ring-sky-600/30' : 'border-slate-200 bg-white hover:bg-slate-50'
            }`}
          >
            <span className="text-3xl">{c.emoji}</span>
            <span className="text-xs font-semibold text-slate-700">{c.etiqueta}</span>
          </button>
        ))}
      </div>

      <div>
        <label htmlFor="sugerencia" className="block text-sm font-medium text-slate-700">
          ¿Qué otro curso te gustaría llevar con nosotros? (opcional)
        </label>
        <input
          id="sugerencia"
          type="text"
          maxLength={120}
          value={sugerencia}
          onChange={(e) => setSugerencia(e.target.value)}
          placeholder="Ej. Power BI, Python, Excel Financiero…"
          className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-sky-600 focus:outline-none"
        />
      </div>

      <button
        type="submit"
        disabled={enviando}
        className="w-full rounded-xl bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-800 disabled:opacity-50"
      >
        {enviando ? 'Enviando…' : 'Enviar opinión'}
      </button>
    </form>
  );
}
