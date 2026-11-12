import Link from 'next/link';
import Image from 'next/image';
import { Phone, Mail, MapPin, BookOpen } from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

export function Footer() {
  return (
    <footer className="mt-20 border-t-4 border-gold bg-[#0A1929] text-stone-200">
      <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <div className="grid gap-10 md:grid-cols-2 lg:grid-cols-4">
          {/* Column 1: Brand Info */}
          <div>
            <div className="mb-5 flex items-center gap-3">
              <div className="relative h-12 w-12 flex-shrink-0">
                <Image
                  src="/images/index/logos/logo-horus.png"
                  alt="Horus Group SRL"
                  fill
                  sizes="48px"
                  className="object-contain"
                />
              </div>
              <div>
                <h3 className="text-lg font-bold text-white tracking-wide">Horus Group SRL</h3>
                <span className="text-xs font-semibold tracking-wider text-gold">RUC {CONTACT_INFO.ruc}</span>
              </div>
            </div>
            <p className="text-sm leading-relaxed text-stone-300">
              Especialistas en soluciones tecnológicas, capacitaciones y soporte técnico para hogares, empresas y profesionales en Cajamarca - Perú.
            </p>
          </div>

          {/* Column 2: Legal Links */}
          <div>
            <h4 className="mb-4 text-base font-bold text-gold uppercase tracking-wider">
              Términos y Condiciones
            </h4>
            <ul className="space-y-2.5 text-sm text-stone-300">
              <li>
                <Link href="/legales/politicas-devolucion" className="hover:text-gold transition-colors">
                  Políticas de devolución
                </Link>
              </li>
              <li>
                <Link href="/legales/politicas-privacidad" className="hover:text-gold transition-colors">
                  Políticas de privacidad
                </Link>
              </li>
              <li>
                <Link href="/legales/politicas-cookies" className="hover:text-gold transition-colors">
                  Políticas de cookies
                </Link>
              </li>
              <li>
                <Link href="/legales/preguntas-frecuentes" className="hover:text-gold transition-colors">
                  Preguntas frecuentes (FAQ)
                </Link>
              </li>
            </ul>
          </div>

          {/* Column 3: Contact Info */}
          <div>
            <h4 className="mb-4 text-base font-bold text-gold uppercase tracking-wider">
              Contactos
            </h4>
            <ul className="space-y-3 text-sm text-stone-300">
              <li className="flex items-start gap-2.5">
                <Phone className="h-4 w-4 text-gold flex-shrink-0 mt-0.5" />
                <a href={`tel:${CONTACT_INFO.phoneClean}`} className="hover:text-gold transition-colors">
                  {CONTACT_INFO.phone}
                </a>
              </li>
              <li className="flex items-start gap-2.5">
                <Mail className="h-4 w-4 text-gold flex-shrink-0 mt-0.5" />
                <a href={`mailto:${CONTACT_INFO.email}`} className="hover:text-gold transition-colors break-all">
                  {CONTACT_INFO.email}
                </a>
              </li>
              <li className="flex items-start gap-2.5">
                <MapPin className="h-4 w-4 text-gold flex-shrink-0 mt-0.5" />
                <span>{CONTACT_INFO.address}</span>
              </li>
            </ul>
          </div>

          {/* Column 4: Socials & Libro de Reclamaciones */}
          <div>
            <h4 className="mb-4 text-base font-bold text-gold uppercase tracking-wider">
              Síguenos
            </h4>
            <div className="mb-6 flex flex-wrap items-center gap-2.5 text-stone-200">
              <a
                href={CONTACT_INFO.socials.facebook}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Facebook de Horus Group"
                className="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 border border-white/10 hover:border-gold hover:text-gold hover:bg-gold/10 transition-all"
              >
                <span className="text-xs font-bold">Fb</span>
              </a>
              <a
                href={CONTACT_INFO.socials.whatsapp}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="WhatsApp de Horus Group"
                className="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 border border-white/10 hover:border-gold hover:text-gold hover:bg-gold/10 transition-all"
              >
                <span className="text-xs font-bold">Wa</span>
              </a>
              <a
                href={CONTACT_INFO.socials.instagram}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Instagram de Horus Group"
                className="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 border border-white/10 hover:border-gold hover:text-gold hover:bg-gold/10 transition-all"
              >
                <span className="text-xs font-bold">Ig</span>
              </a>
              <a
                href={CONTACT_INFO.socials.tiktok}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="TikTok de Horus Group"
                className="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 border border-white/10 hover:border-gold hover:text-gold hover:bg-gold/10 transition-all"
              >
                <span className="text-xs font-bold">Tk</span>
              </a>
              <a
                href={CONTACT_INFO.socials.youtube}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="YouTube de Horus Group"
                className="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 border border-white/10 hover:border-gold hover:text-gold hover:bg-gold/10 transition-all"
              >
                <span className="text-xs font-bold">Yt</span>
              </a>
            </div>

            {/* Libro de Reclamaciones Button */}
            <Link
              href="/legales/libro-reclamaciones"
              className="group inline-flex items-center gap-3 rounded-2xl border border-gold/40 bg-gold/5 px-4 py-2.5 text-sm font-semibold text-stone-200 hover:bg-gold/15 hover:border-gold transition-all"
            >
              <BookOpen className="h-4 w-4 text-gold group-hover:scale-110 transition-transform" />
              <span>Libro de Reclamaciones</span>
            </Link>
          </div>
        </div>
      </div>

      {/* Bottom Bar */}
      <div className="border-t border-white/10 bg-[#071321] py-4 text-center text-xs text-stone-400">
        © 2026 Horus Group SRL. Todos los derechos reservados.
      </div>
    </footer>
  );
}
