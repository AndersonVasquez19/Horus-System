import { notFound } from 'next/navigation';
import { horusGet } from '@/lib/horus-api';

interface CronogramaEdicion {
  data: {
    codigo: string;
    nombre: string | null;
    curso: string | null;
    modalidad: string;
    estado: string;
    fecha_inicio: string;
    fecha_fin: string;
    horarios: { dia_semana: number; hora_inicio: string; hora_fin: string }[];
    sesiones: { numero: number; fecha: string; hora_inicio: string; hora_fin: string; tema: string | null; estado: string }[];
  };
}

const DIAS: Record<number, string> = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };

export default async function CronogramaPublicoPage({ params }: { params: Promise<{ codigo: string }> }) {
  const { codigo } = await params;
  const res = await horusGet<CronogramaEdicion>(`/ediciones/${encodeURIComponent(codigo)}/cronograma`);
  if (!res.ok) notFound();

  const d = res.data.data;

  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-sky-700">Horus Group · Cronograma de Edición</p>
        <h1 className="mt-1 text-2xl font-bold text-slate-900">{d.nombre ?? d.curso ?? d.codigo}</h1>
        <p className="mt-1 text-sm text-slate-600">
          Código: <span className="font-mono font-semibold">{d.codigo}</span> · Modalidad: <span className="capitalize">{d.modalidad}</span> · Del {d.fecha_inicio} al {d.fecha_fin}
        </p>

        {d.horarios.length > 0 ? (
          <section className="mt-6">
            <h2 className="text-sm font-semibold text-slate-800">Horario semanal</h2>
            <div className="mt-2 flex flex-wrap gap-2">
              {d.horarios.map((h, idx) => (
                <span key={idx} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-700">
                  {DIAS[h.dia_semana] ?? `Día ${h.dia_semana}`} · {h.hora_inicio}–{h.hora_fin}
                </span>
              ))}
            </div>
          </section>
        ) : null}

        <section className="mt-6">
          <h2 className="text-sm font-semibold text-slate-800">Sesiones programadas</h2>
          {d.sesiones.length === 0 ? (
            <p className="mt-2 text-sm text-slate-500">Todavía no hay sesiones publicadas para esta edición.</p>
          ) : (
            <ul className="mt-3 divide-y divide-slate-200 rounded-xl border border-slate-200">
              {d.sesiones.map((s) => (
                <li key={s.numero} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                  <div>
                    <p className="font-semibold text-slate-900">Sesión {s.numero}{s.tema ? `: ${s.tema}` : ''}</p>
                    <p className="text-xs text-slate-500">{s.fecha} · {s.hora_inicio}–{s.hora_fin}</p>
                  </div>
                  <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${s.estado === 'realizada' ? 'bg-emerald-50 text-emerald-700' : 'bg-sky-50 text-sky-700'}`}>
                    {s.estado === 'realizada' ? 'Realizada' : 'Programada'}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </main>
  );
}
