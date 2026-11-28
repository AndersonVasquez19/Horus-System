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
  };
}

// Lee Laravel por petición (params + horusGet): sin esto `cacheComponents` intenta prerenderizarla y el build falla.
export const instant = false;

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
      </div>
    </main>
  );
}
