import type { Metadata } from 'next';
import { Cookie, Settings, CheckCircle2 } from 'lucide-react';

export const metadata: Metadata = {
  title: 'Políticas de Cookies',
  description: 'Información sobre el uso de cookies y almacenamiento web en el portal de Horus Group SRL.',
};

export default function PoliticasCookiesPage() {
  return (
    <div className="pt-28 pb-20">
      <section className="relative overflow-hidden py-16 sm:py-20 bg-gradient-to-b from-[#0A1929] to-[#0A2540]">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto">
          <span className="text-xs font-bold tracking-widest text-gold uppercase">Navegación Web</span>
          <h1 className="mt-2 text-3xl sm:text-5xl font-extrabold text-white tracking-tight leading-tight">
            Políticas de <span className="text-gold">Cookies</span>
          </h1>
          <p className="mt-4 text-sm sm:text-base text-stone-300 leading-relaxed">
            Transparencia sobre el almacenamiento local y las tecnologías que garantizan el correcto funcionamiento de nuestra plataforma.
          </p>
        </div>
      </section>

      <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-8">
        <div className="rounded-3xl border border-white/10 bg-[#0A1929]/70 backdrop-blur-xl p-8 sm:p-10 shadow-xl space-y-6 text-sm leading-relaxed text-stone-300">
          <div>
            <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
              <Cookie className="h-5 w-5 text-gold" />
              <span>¿Qué son las Cookies?</span>
            </h2>
            <p>
              Las cookies son pequeños archivos de texto que los sitios web almacenan en su navegador para recordar preferencias de navegación, optimizar el tiempo de carga y garantizar una experiencia segura.
            </p>
          </div>

          <div>
            <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
              <CheckCircle2 className="h-5 w-5 text-gold" />
              <span>Cookies que Utilizamos</span>
            </h2>
            <div className="space-y-3 mt-3">
              <div className="rounded-2xl border border-white/5 bg-white/5 p-4">
                <strong className="block text-white">Cookies Técnicas y Estrictamente Necesarias</strong>
                <span className="text-xs text-stone-400">
                  Permiten la navegación segura a través del portal web y el uso de sus diferentes opciones o servicios (gestión de formularios, seguridad anti-CSRF y prevención de spam).
                </span>
              </div>

              <div className="rounded-2xl border border-white/5 bg-white/5 p-4">
                <strong className="block text-white">Cookies de Preferencias y Rendimiento</strong>
                <span className="text-xs text-stone-400">
                  Permiten recordar sus configuraciones de accesibilidad (como la reducción de movimiento en animaciones) y optimizar la velocidad de entrega de contenido.
                </span>
              </div>
            </div>
          </div>

          <div>
            <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
              <Settings className="h-5 w-5 text-gold" />
              <span>Cómo Desactivar o Gestionar las Cookies</span>
            </h2>
            <p>
              Usted puede en cualquier momento permitir, bloquear o eliminar las cookies instaladas en su equipo mediante la configuración de las opciones del navegador que utilice (Google Chrome, Mozilla Firefox, Microsoft Edge, Safari). Tenga en cuenta que la desactivación de ciertas cookies técnicas esenciales puede limitar el correcto funcionamiento de formularios interactivos.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
