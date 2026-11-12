import type { Metadata } from 'next';
import { Suspense } from 'react';
import Image from 'next/image';
import {
  Video,
  Wifi,
  Mail,
  Moon,
  Smartphone,
  ArrowRight,
} from 'lucide-react';
import { CAMARAS_ITEMS } from '@/data/servicios';
import { CONTACT_INFO } from '@/data/contactos';
import { obtenerPagina } from '@/lib/horus-api';
import { ContenidoEditorial } from '@/components/tecnologias/contenido-editorial';

export const metadata: Metadata = {
  title: 'Cámaras de Seguridad',
  description: 'Desarrollamos proyectos de seguridad integral con cámaras IP, analógicas, domo, 360° y monitoreo en Cajamarca.',
};

function Estatica() {
  const getIcon = (id: string) => {
    switch (id) {
      case 'sensor-movimiento':
        return <Wifi className="h-8 w-8 text-white" />;
      case 'alarma-email':
        return <Mail className="h-8 w-8 text-white" />;
      case 'vision-nocturna':
        return <Moon className="h-8 w-8 text-white" />;
      case 'visualizacion-dispositivos':
        return <Smartphone className="h-8 w-8 text-white" />;
      default:
        return <Video className="h-8 w-8 text-white" />;
    }
  };

  const isProducto = (id: string) => {
    // Escenas are 360, sensor, alarma, visualización
    return !['cam-360', 'sensor-movimiento', 'alarma-email', 'visualizacion-dispositivos'].includes(id);
  };

  return (
    <div className="pt-20">
      {/* Intro Videovigilancia con degradado institucional y lineShine */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#2563A8] text-white">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-10 lg:grid-cols-2 items-center">
            <div className="max-w-xl">
              <h2 className="relative pb-6 text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
                SISTEMA DE VIDEOVIGILANCIA
                <span className="absolute bottom-0 left-0 h-1 w-48 rounded-full animate-line-shine"></span>
              </h2>
              <p className="mt-6 text-base sm:text-lg text-white/90 leading-relaxed text-justify">
                Desarrollamos proyectos de seguridad integral diseñados para proteger su hogar, empresa o
                institución. Nuestras soluciones avanzadas le permiten mantener el control absoluto de sus
                espacios, sin importar dónde se encuentre.
              </p>
            </div>

            <div className="relative flex justify-center items-center">
              <div className="relative h-64 sm:h-80 w-full max-w-[520px] rounded-2xl overflow-hidden border-2 border-gold/40 shadow-2xl hover:-translate-y-2 hover:border-gold transition-all duration-400 bg-white/5 p-4 flex items-center justify-center">
                <Image
                  src="/images/videovigilancia/camera.webp"
                  alt="Sistema de videovigilancia"
                  fill
                  priority
                  sizes="(max-width: 1024px) 100vw, 520px"
                  className="object-contain p-2"
                />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Grid de 10 Cartas exactas de Blade en fondo blanco */}
      <section className="bg-white py-16 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            {CAMARAS_ITEMS.map((item) => {
              const producto = isProducto(item.id);
              return (
                <article
                  key={item.id}
                  className="card-hover-border rounded-2xl bg-white border border-stone-200 shadow-[0_8px_30px_rgba(10,37,64,0.1)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(10,37,64,0.18)] hover:border-gold transition-all duration-400 flex flex-col justify-between overflow-hidden text-center"
                >
                  <div>
                    {/* Header Image */}
                    <div className="relative h-[210px] w-full bg-white border-b border-[#0A2540]/8 overflow-hidden">
                      <Image
                        src={item.imagen}
                        alt={item.titulo}
                        fill
                        sizes="(max-width: 768px) 100vw, 400px"
                        className={`transition-transform duration-600 hover:scale-105 ${
                          producto ? 'object-contain p-3.5 pb-8' : 'object-cover'
                        }`}
                      />
                    </div>

                    {/* Overlapping Circle Icon */}
                    <div className="relative z-10 -mt-10 mx-auto flex h-[76px] w-[76px] items-center justify-center rounded-full border-4 border-white bg-gradient-to-br from-[#0A2540] to-gold shadow-[0_8px_25px_rgba(10,37,64,0.2)]">
                      {getIcon(item.id)}
                    </div>

                    {/* Content */}
                    <div className="px-6 pb-6 pt-2">
                      <h3 className="text-xl font-bold text-[#0A2540] mb-3 leading-snug">
                        {item.titulo}
                      </h3>
                      <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-6">
                        {item.descripcion}
                      </p>
                    </div>
                  </div>

                  {/* Solicitar Cotización Button */}
                  <div className="px-6 pb-7">
                    <a
                      href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, deseo solicitar cotización para: ${item.servicioCotizar}.`)}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center justify-center gap-3 w-full min-h-[48px] rounded-xl border-2 border-[#0A2540] bg-[#0A2540] px-5 py-3 text-sm font-semibold text-white hover:bg-gold hover:text-[#0A2540] hover:border-gold transition-colors duration-200"
                      aria-label={`Solicitar cotización de ${item.titulo}`}
                    >
                      <span>Solicitar cotización</span>
                      <ArrowRight className="h-4 w-4" />
                    </a>
                  </div>
                </article>
              );
            })}
          </div>
        </div>
      </section>
    </div>
  );
}

/**
 * El contenido editorial autorizado sale de Laravel (servidor a servidor); si no hay ficha autorizada, el catálogo
 * está apagado o la API no responde, se muestra el contenido estático de siempre.
 */
async function Dinamica() {
  const resultado = await obtenerPagina('camaras-seguridad');
  const contenido = resultado.ok ? resultado.data.contenido : null;
  return contenido ? <ContenidoEditorial contenido={contenido} /> : <Estatica />;
}

export default function CamarasSeguridadPage() {
  return (
    <Suspense fallback={<Estatica />}>
      <Dinamica />
    </Suspense>
  );
}
