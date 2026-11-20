import { notFound } from 'next/navigation';
import { horusGet } from '@/lib/horus-api';
import { EncuestaCliente } from './encuesta-cliente';

interface EncuestaPublica {
  data: {
    identificador: string;
    curso: string;
    edicion: string | null;
    respondida: boolean;
  };
}

export default async function EncuestaPublicaPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const res = await horusGet<EncuestaPublica>(`/encuestas/${encodeURIComponent(token)}`);
  if (!res.ok) notFound();

  const d = res.data.data;

  return (
    <main className="mx-auto max-w-md px-4 py-12">
      <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-sky-700">Horus Group · Encuesta rápida</p>
        <h1 className="mt-1 text-xl font-bold text-slate-900">¿Qué te pareció {d.curso}?</h1>
        {d.edicion ? <p className="mt-0.5 text-xs text-slate-500">Edición {d.edicion}</p> : null}
        <div className="mt-6">
          <EncuestaCliente token={d.identificador} curso={d.curso} yaRespondida={d.respondida} />
        </div>
      </div>
    </main>
  );
}
