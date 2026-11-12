import type { Metadata } from 'next';
import { ShieldCheck, Info } from 'lucide-react';
import { LibroForm } from '@/components/libro/libro-form';

export const metadata: Metadata = {
  title: 'Libro de Reclamaciones Virtual',
  description: 'Libro de reclamaciones virtual de Horus Group SRL conforme a la Ley N° 29571 (Indecopi).',
};

export default function LibroReclamacionesPage() {
  return (
    <div className="pt-28 pb-20">
      {/* Header */}
      <section className="relative overflow-hidden py-16 sm:py-20 bg-gradient-to-b from-[#0A1929] to-[#0A2540]">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto">
          <div className="inline-flex items-center gap-2 rounded-full border border-gold/40 bg-gold/10 px-4 py-1.5 backdrop-blur-md mb-4">
            <ShieldCheck className="h-4 w-4 text-gold" />
            <span className="text-xs sm:text-sm font-semibold tracking-wide text-gold">
              Código de Protección al Consumidor – Ley N° 29571
            </span>
          </div>

          <h1 className="text-3xl sm:text-5xl font-extrabold text-white tracking-tight leading-tight">
            Libro de <span className="text-gold">Reclamaciones</span>
          </h1>

          <p className="mt-4 text-sm sm:text-base text-stone-300 leading-relaxed">
            Tu opinión y satisfacción son fundamentales para nosotros. Registra aquí tu queja o reclamo y nos comprometemos a responderte en un plazo máximo de <strong>15 días hábiles</strong>.
          </p>
        </div>
      </section>

      {/* Legal notice banner */}
      <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 mt-8">
        <div className="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5 flex items-start gap-3.5 text-xs sm:text-sm text-stone-300">
          <Info className="h-5 w-5 text-gold flex-shrink-0 mt-0.5" />
          <p>
            <strong>Información importante:</strong> La formulación de un reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI, conforme al Código de Protección y Defensa del Consumidor (Ley N° 29571).
          </p>
        </div>
      </div>

      {/* Interactive Form Component */}
      <section className="py-12 sm:py-16">
        <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
          <LibroForm />
        </div>
      </section>
    </div>
  );
}
