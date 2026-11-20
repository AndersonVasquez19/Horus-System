import Image from 'next/image';
import { ArrowRight, Camera, Cable, Check, Monitor, Network, Server, Settings, ShieldCheck, Video, Wrench } from 'lucide-react';
import type { ReactNode } from 'react';
import { CONTACT_INFO } from '@/data/contactos';
import { urlPublicaImagen, type ContenidoPagina } from '@/lib/horus-api';

/**
 * Contenido editorial autorizado en Laravel para una de las tres páginas de tecnologías (misma estructura que el
 * contenido estático: introducción con imagen y rejilla de tarjetas). Se renderiza en el servidor; las imágenes
 * se resuelven contra la base pública de Laravel y, si no se pueden resolver, simplemente no se muestran.
 */

const ICONOS: Record<string, ReactNode> = {
  video: <Video className="h-8 w-8 text-white" />,
  camera: <Camera className="h-8 w-8 text-white" />,
  cable: <Cable className="h-8 w-8 text-white" />,
  network: <Network className="h-8 w-8 text-white" />,
  server: <Server className="h-8 w-8 text-white" />,
  desktop: <Monitor className="h-8 w-8 text-white" />,
  wrench: <Wrench className="h-8 w-8 text-white" />,
  shield: <ShieldCheck className="h-8 w-8 text-white" />,
};

/** Laravel guarda clases de Font Awesome (`fas fa-video`): se traducen por nombre a un icono de la interfaz. */
function icono(clase: string | null): ReactNode {
  const nombre = clase?.split(' ').pop()?.replace(/^fa-/, '') ?? '';
  return ICONOS[nombre] ?? <Settings className="h-8 w-8 text-white" />;
}

export function ContenidoEditorial({ contenido }: { contenido: ContenidoPagina }) {
  const imagen = urlPublicaImagen(contenido.imagen_url);

  return (
    <div className="pt-20">
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#2563A8] text-white">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-10 lg:grid-cols-2 items-center">
            <div className="max-w-xl">
              <h2 className="relative pb-6 text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
                {contenido.titulo}
                <span className="absolute bottom-0 left-0 h-1 w-48 rounded-full animate-line-shine"></span>
              </h2>
              {contenido.parrafos.map((parrafo, i) => (
                <p key={i} className="mt-6 text-base sm:text-lg text-white/90 leading-relaxed text-justify">
                  {parrafo}
                </p>
              ))}
            </div>

            {imagen && (
              <div className="relative flex justify-center items-center">
                <div className="relative h-64 sm:h-80 w-full max-w-[520px] rounded-2xl overflow-hidden border-2 border-gold/40 shadow-2xl hover:-translate-y-2 hover:border-gold transition-all duration-400">
                  <Image
                    src={imagen}
                    alt={contenido.imagen_alt}
                    fill
                    unoptimized
                    priority
                    sizes="(max-width: 1024px) 100vw, 520px"
                    className="object-cover"
                  />
                </div>
              </div>
            )}
          </div>
        </div>
      </section>

      {contenido.tarjetas.length > 0 && (
        <section className="bg-gradient-to-b from-white to-[#f8f9fa] py-16 sm:py-24">
          <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            {contenido.titulo_tarjetas && (
              <div className="text-center mb-16">
                <h2 className="relative inline-block pb-6 text-3xl sm:text-4xl md:text-5xl font-bold tracking-tight text-[#0A2540]">
                  {contenido.titulo_tarjetas}
                  <span className="absolute bottom-0 left-1/2 -translate-x-1/2 h-1 w-48 rounded-full animate-line-shine"></span>
                </h2>
              </div>
            )}

            <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
              {contenido.tarjetas.map((tarjeta, i) => {
                const imagenTarjeta = urlPublicaImagen(tarjeta.imagen_url);
                return (
                  <article
                    key={`${tarjeta.titulo}-${i}`}
                    className="card-hover-border rounded-2xl bg-white border border-stone-200 shadow-[0_8px_30px_rgba(0,0,0,0.1)] hover:-translate-y-3 hover:shadow-[0_15px_50px_rgba(0,0,0,0.18)] hover:border-gold transition-all duration-400 flex flex-col justify-between overflow-hidden min-w-0"
                  >
                    <div>
                      {imagenTarjeta && (
                        <div className="relative h-56 w-full bg-stone-100 overflow-hidden">
                          <Image
                            src={imagenTarjeta}
                            alt={tarjeta.imagen_alt}
                            fill
                            unoptimized
                            sizes="(max-width: 768px) 100vw, 400px"
                            className={tarjeta.variante === 'producto' ? 'object-contain p-4' : 'object-cover'}
                          />
                        </div>
                      )}
                      <div className="p-7 sm:p-8">
                        {tarjeta.icono && (
                          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-[#0A2540] to-gold shadow-[0_8px_25px_rgba(10,37,64,0.2)] mx-auto mb-6">
                            {icono(tarjeta.icono)}
                          </div>
                        )}
                        <h3 className="text-xl font-bold text-[#0A2540] mb-4 leading-snug [overflow-wrap:anywhere]">{tarjeta.titulo}</h3>
                        <p className="text-sm sm:text-base text-slate-600 leading-relaxed mb-6 [overflow-wrap:anywhere]">{tarjeta.descripcion}</p>
                        {tarjeta.caracteristicas.length > 0 && (
                          <ul className="space-y-3 border-t-2 border-stone-100 pt-5">
                            {tarjeta.caracteristicas.map((caracteristica, j) => (
                              <li key={j} className="flex items-start gap-2.5 text-xs sm:text-sm text-slate-700 font-medium">
                                <Check className="h-4 w-4 text-gold shrink-0 mt-0.5" />
                                <span className="[overflow-wrap:anywhere]">{caracteristica}</span>
                              </li>
                            ))}
                          </ul>
                        )}
                      </div>
                    </div>

                    <div className="p-7 sm:p-8 pt-0">
                      <a
                        href={`https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(`Hola, deseo solicitar cotización para: ${tarjeta.titulo}.`)}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center justify-center gap-3 w-full min-h-[48px] rounded-xl border-2 border-[#0A2540] bg-[#0A2540] px-5 py-3 text-sm font-semibold text-white hover:bg-gold hover:text-[#0A2540] hover:border-gold transition-colors duration-200"
                        aria-label={`Solicitar cotización de ${tarjeta.titulo}`}
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
      )}
    </div>
  );
}
