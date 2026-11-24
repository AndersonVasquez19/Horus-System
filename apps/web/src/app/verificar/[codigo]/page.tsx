import { horusGet } from '@/lib/horus-api';

interface Verificacion {
  data: {
    valido: boolean;
    estado: string;
    codigo: string;
    curso: string;
    fecha_emision: string;
    titular_abreviado: string;
  };
}

export const metadata = {
  title: 'Verificación de certificado · Horus Group',
  robots: { index: false, follow: false },
};

// Lee Laravel por petición (params + horusGet): sin esto `cacheComponents` intenta prerenderizarla y el build falla.
export const instant = false;

function fechaLarga(iso: string): string {
  const [anio, mes, dia] = iso.split('-');
  return anio && mes && dia ? `${dia}/${mes}/${anio}` : iso;
}

/** Verificación pública de un certificado por código (el del QR del PDF o el del correo). Solo muestra lo que expone la API: nunca DNI ni nombre completo. */
export default async function VerificarCertificadoPage({ params }: { params: Promise<{ codigo: string }> }) {
  const { codigo } = await params;
  const valido = /^[A-Za-z0-9-]{4,32}$/.test(codigo);
  const res = valido ? await horusGet<Verificacion>(`/certificados/${encodeURIComponent(codigo)}`) : null;
  const d = res?.ok ? res.data.data : null;

  return (
    <main className="mx-auto min-h-[70vh] max-w-md px-4 pb-20 pt-32">
      <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-sky-700">Horus Group · Verificación de certificado</p>
        {d ? (
          <>
            <h1 className={`mt-1 text-xl font-bold ${d.valido ? 'text-emerald-700' : 'text-red-700'}`}>
              {d.valido ? 'Certificado válido' : 'Certificado anulado'}
            </h1>
            <p className="mt-1 text-sm text-slate-600">
              {d.valido ? 'Este certificado fue emitido por Horus Group.' : 'Este certificado ya no tiene validez.'}
            </p>
            <dl className="mt-6 space-y-3 text-sm">
              <div><dt className="text-xs font-medium text-slate-500">Código</dt><dd className="font-mono text-slate-900">{d.codigo}</dd></div>
              <div><dt className="text-xs font-medium text-slate-500">Titular</dt><dd className="text-slate-900">{d.titular_abreviado}</dd></div>
              <div><dt className="text-xs font-medium text-slate-500">Curso</dt><dd className="text-slate-900">{d.curso}</dd></div>
              <div><dt className="text-xs font-medium text-slate-500">Fecha de emisión</dt><dd className="text-slate-900">{fechaLarga(d.fecha_emision)}</dd></div>
            </dl>
          </>
        ) : (
          <>
            <h1 className="mt-1 text-xl font-bold text-slate-900">No pudimos verificar este código</h1>
            <p className="mt-2 text-sm text-slate-600">
              No encontramos un certificado con ese código o el servicio no está disponible. Revisa que el código esté completo e inténtalo de nuevo; si el problema sigue, comunícate con Horus Group.
            </p>
          </>
        )}
      </div>
    </main>
  );
}
