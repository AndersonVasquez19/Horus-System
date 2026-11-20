import type { Metadata } from 'next';
import { GalleryClient } from '@/components/galeria/gallery-client';

export const metadata: Metadata = {
  title: 'Galería',
  description: 'Nuestra galería de fotos de Horus Group SRL en Cajamarca: momentos que inspiran.',
};

export default function GaleriaPage() {
  return (
    <div className="pt-20">
      {/* Hero exacto de galeria.blade.php */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#1a2f45] text-white text-center">
        <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-center gap-3 mb-4" aria-hidden="true">
            <span className="h-0.5 w-8 bg-gold/60"></span>
            <span className="h-2 w-2 rounded-full bg-gold"></span>
            <span className="h-0.5 w-8 bg-gold/60"></span>
          </div>

          <h1 className="text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
            Nuestra <span className="text-gold">galería</span>
          </h1>
          <p className="mt-3 text-base sm:text-lg text-white/80 font-light">
            Momentos que inspiran
          </p>
        </div>
      </section>

      {/* Galería y Filtros en fondo limpio */}
      <section className="bg-[#F7F9FC] py-12 sm:py-16">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <GalleryClient />
        </div>
      </section>
    </div>
  );
}
