import { Suspense } from 'react';
import type { Metadata } from 'next';
import { CotizacionForm } from '@/components/cotizacion/cotizacion-form';

export const metadata: Metadata = {
  title: 'Solicita una cotización',
  description: 'Solicita una cotización de cableado estructurado, videovigilancia, soporte y otros servicios tecnológicos de Horus Group SRL.',
};

export default function SolicitarCotizacionPage() {
  return (
    <div className="pt-28 pb-20">
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <Suspense fallback={<div className="py-20 text-center text-gold">Cargando formulario…</div>}>
          <CotizacionForm />
        </Suspense>
      </div>
    </div>
  );
}
