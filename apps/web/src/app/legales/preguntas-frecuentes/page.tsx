import type { Metadata } from 'next';
import { FaqClient } from '@/components/faq/faq-client';

export const metadata: Metadata = {
  title: 'Preguntas Frecuentes (FAQ)',
  description: 'Respuestas a las dudas más comunes sobre certificados, cursos, compras y soporte en Horus Group.',
};

export default function PreguntasFrecuentesPage() {
  return (
    <div className="pt-28 pb-20">
      <section className="relative overflow-hidden py-16 sm:py-20 bg-gradient-to-b from-[#0A1929] to-[#0A2540]">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto">
          <span className="text-xs font-bold tracking-widest text-gold uppercase">Centro de Ayuda</span>
          <h1 className="mt-2 text-3xl sm:text-5xl font-extrabold text-white tracking-tight leading-tight">
            Preguntas <span className="text-gold">Frecuentes</span>
          </h1>
          <p className="mt-4 text-sm sm:text-base text-stone-300 leading-relaxed">
            Encuentra respuestas rápidas sobre certificaciones, modalidades de estudio, compras, garantías y atención personalizada.
          </p>
        </div>
      </section>

      <section className="py-12 sm:py-16">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <FaqClient />
        </div>
      </section>
    </div>
  );
}
