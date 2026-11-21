'use client';

import { useState, useEffect, useRef } from 'react';
import Link from 'next/link';
import Image from 'next/image';
import { usePathname } from 'next/navigation';
import { Menu, X, ChevronDown, PhoneCall } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CONTACT_INFO } from '@/data/contactos';
import { TECNOLOGIAS_VISIBLES } from '@/lib/funciones';

export function Navbar() {
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [openDropdown, setOpenDropdown] = useState<string | null>(null);
  const [scrolled, setScrolled] = useState(false);

  const timeoutRef = useRef<NodeJS.Timeout | null>(null);
  const navRef = useRef<HTMLElement>(null);

  useEffect(() => {
    const handleScroll = () => {
      setScrolled(window.scrollY > 20);
    };
    window.addEventListener('scroll', handleScroll, { passive: true });
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  // Close menus on route change: se ajusta durante el render al cambiar `pathname` (patrón recomendado por React en vez de un efecto).
  const [rutaAnterior, setRutaAnterior] = useState(pathname);
  if (rutaAnterior !== pathname) {
    setRutaAnterior(pathname);
    setMobileOpen(false);
    setOpenDropdown(null);
  }

  // Click outside to close dropdowns
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (navRef.current && !navRef.current.contains(e.target as Node)) {
        setOpenDropdown(null);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Cleanup timeout on unmount
  useEffect(() => {
    return () => {
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    };
  }, []);

  const handleMouseEnter = (menu: string) => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current);
    setOpenDropdown(menu);
  };

  const handleMouseLeave = () => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current);
    timeoutRef.current = setTimeout(() => {
      setOpenDropdown(null);
    }, 200);
  };

  const handleToggle = (menu: string) => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current);
    setOpenDropdown((prev) => (prev === menu ? null : menu));
  };

  const isActive = (path: string) => {
    if (path === '/') return pathname === '/';
    return pathname.startsWith(path);
  };

  return (
    <header
      className={cn(
        'fixed top-0 left-0 right-0 z-50 transition-all duration-300 px-4 py-3 sm:py-4',
        scrolled ? 'bg-[#0A2540]/90 backdrop-blur-md shadow-xl py-2 sm:py-3' : 'bg-transparent'
      )}
    >
      <div className="mx-auto max-w-7xl">
        <nav
          ref={navRef}
          className="flex items-center justify-between rounded-full border border-white/10 bg-white/5 backdrop-blur-lg px-4 py-2 sm:px-6 shadow-2xl relative"
        >
          {/* Brand Logo */}
          <Link href="/" className="flex items-center gap-3 group">
            <div className="relative h-10 w-10 sm:h-11 sm:w-11 overflow-hidden transition-transform group-hover:scale-105">
              <Image
                src="/images/index/logos/logo-horus.png"
                alt="Horus Group Logo"
                fill
                sizes="44px"
                className="object-contain"
                priority
              />
            </div>
            <div className="flex flex-col">
              <span className="text-base sm:text-lg font-bold tracking-wide text-white group-hover:text-gold transition-colors">
                Horus Group
              </span>
              <span className="text-[10px] tracking-wider text-gold font-semibold uppercase hidden sm:block">
                Tecnología & Educación
              </span>
            </div>
          </Link>

          {/* Desktop Navigation Links */}
          <ul className="hidden lg:flex items-center gap-1 xl:gap-2">
            <li>
              <Link
                href="/"
                className={cn(
                  'px-3 py-1.5 rounded-full text-sm font-medium transition-all',
                  isActive('/')
                    ? 'text-gold bg-gold/10 font-semibold'
                    : 'text-stone-200 hover:text-gold hover:bg-white/5'
                )}
              >
                Inicio
              </Link>
            </li>

            <li>
              <Link
                href="/quienes-somos"
                className={cn(
                  'px-3 py-1.5 rounded-full text-sm font-medium transition-all',
                  isActive('/quienes-somos')
                    ? 'text-gold bg-gold/10 font-semibold'
                    : 'text-stone-200 hover:text-gold hover:bg-white/5'
                )}
              >
                Quiénes Somos
              </Link>
            </li>

            {/* Tecnologías Dropdown con Hover Bridge + Click Toggle + Debounce */}
            {TECNOLOGIAS_VISIBLES && (
            <li
              className="relative"
              onMouseEnter={() => handleMouseEnter('tecnologias')}
              onMouseLeave={handleMouseLeave}
            >
              <button
                type="button"
                onClick={() => handleToggle('tecnologias')}
                aria-expanded={openDropdown === 'tecnologias'}
                className={cn(
                  'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-all cursor-pointer',
                  isActive('/tecnologias') || openDropdown === 'tecnologias'
                    ? 'text-gold bg-gold/10 font-semibold'
                    : 'text-stone-200 hover:text-gold hover:bg-white/5'
                )}
              >
                <span>Tecnologías</span>
                <ChevronDown
                  className={cn(
                    'h-3.5 w-3.5 transition-transform duration-200',
                    openDropdown === 'tecnologias' && 'rotate-180 text-gold'
                  )}
                />
              </button>

              {openDropdown === 'tecnologias' && (
                <div
                  className="absolute top-full left-0 pt-2 z-50 w-64"
                  onMouseEnter={() => handleMouseEnter('tecnologias')}
                  onMouseLeave={handleMouseLeave}
                >
                  <div className="rounded-2xl border border-gold/30 bg-[#0A2540] p-2 text-white shadow-2xl backdrop-blur-xl animate-in fade-in slide-in-from-top-1 duration-150">
                    <Link
                      href="/tecnologias/cableado-estructurado"
                      onClick={() => setOpenDropdown(null)}
                      className="block rounded-xl px-3.5 py-2 text-sm text-stone-200 hover:text-gold hover:bg-white/10 transition-colors"
                    >
                      Cableado Estructurado
                    </Link>
                    <Link
                      href="/tecnologias/camaras-seguridad"
                      onClick={() => setOpenDropdown(null)}
                      className="block rounded-xl px-3.5 py-2 text-sm text-stone-200 hover:text-gold hover:bg-white/10 transition-colors"
                    >
                      Cámaras de Seguridad
                    </Link>
                    <Link
                      href="/tecnologias/soporte-mantenimiento"
                      onClick={() => setOpenDropdown(null)}
                      className="block rounded-xl px-3.5 py-2 text-sm text-stone-200 hover:text-gold hover:bg-white/10 transition-colors"
                    >
                      Soporte y Mantenimiento
                    </Link>
                  </div>
                </div>
              )}
            </li>
            )}

            {/* Educación Dropdown con Hover Bridge + Click Toggle + Debounce */}
            <li
              className="relative"
              onMouseEnter={() => handleMouseEnter('educacion')}
              onMouseLeave={handleMouseLeave}
            >
              <button
                type="button"
                onClick={() => handleToggle('educacion')}
                aria-expanded={openDropdown === 'educacion'}
                className={cn(
                  'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-all cursor-pointer',
                  isActive('/educacion') || openDropdown === 'educacion'
                    ? 'text-gold bg-gold/10 font-semibold'
                    : 'text-stone-200 hover:text-gold hover:bg-white/5'
                )}
              >
                <span>Educación</span>
                <ChevronDown
                  className={cn(
                    'h-3.5 w-3.5 transition-transform duration-200',
                    openDropdown === 'educacion' && 'rotate-180 text-gold'
                  )}
                />
              </button>

              {openDropdown === 'educacion' && (
                <div
                  className="absolute top-full left-0 pt-2 z-50 w-60"
                  onMouseEnter={() => handleMouseEnter('educacion')}
                  onMouseLeave={handleMouseLeave}
                >
                  <div className="rounded-2xl border border-gold/30 bg-[#0A2540] p-2 text-white shadow-2xl backdrop-blur-xl animate-in fade-in slide-in-from-top-1 duration-150">
                    <Link
                      href="/educacion/asesoramiento"
                      onClick={() => setOpenDropdown(null)}
                      className="block rounded-xl px-3.5 py-2 text-sm text-stone-200 hover:text-gold hover:bg-white/10 transition-colors"
                    >
                      Asesoramiento
                    </Link>
                    <Link
                      href="/educacion/capacitaciones"
                      onClick={() => setOpenDropdown(null)}
                      className="block rounded-xl px-3.5 py-2 text-sm text-stone-200 hover:text-gold hover:bg-white/10 transition-colors"
                    >
                      Capacitaciones
                    </Link>
                    <Link
                      href="/educacion/cursos"
                      onClick={() => setOpenDropdown(null)}
                      className="block rounded-xl px-3.5 py-2 text-sm text-stone-200 hover:text-gold hover:bg-white/10 transition-colors"
                    >
                      Cursos
                    </Link>
                  </div>
                </div>
              )}
            </li>

            <li>
              <Link
                href="/galeria"
                className={cn(
                  'px-3 py-1.5 rounded-full text-sm font-medium transition-all',
                  isActive('/galeria')
                    ? 'text-gold bg-gold/10 font-semibold'
                    : 'text-stone-200 hover:text-gold hover:bg-white/5'
                )}
              >
                Galería
              </Link>
            </li>

            <li>
              <Link
                href="/contactos"
                className={cn(
                  'px-3 py-1.5 rounded-full text-sm font-medium transition-all',
                  isActive('/contactos')
                    ? 'text-gold bg-gold/10 font-semibold'
                    : 'text-stone-200 hover:text-gold hover:bg-white/5'
                )}
              >
                Contáctanos
              </Link>
            </li>
          </ul>

          {/* Mobile Hamburger Toggle */}
          <button
            type="button"
            onClick={() => setMobileOpen(!mobileOpen)}
            className="flex h-10 w-10 items-center justify-center rounded-full border border-white/20 bg-white/10 text-white lg:hidden hover:bg-white/20 transition-colors cursor-pointer"
            aria-label={mobileOpen ? 'Cerrar menú' : 'Abrir menú'}
            aria-expanded={mobileOpen}
          >
            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </nav>

        {/* Mobile Dropdown Panel */}
        {mobileOpen && (
          <div className="mt-2 rounded-3xl border border-white/10 bg-[#0A1929]/95 backdrop-blur-2xl p-5 shadow-2xl lg:hidden animate-in fade-in slide-in-from-top-3 duration-200">
            <ul className="space-y-1.5">
              <li>
                <Link
                  href="/"
                  className={cn(
                    'block rounded-xl px-4 py-2 text-sm font-medium transition-colors',
                    isActive('/') ? 'text-gold bg-gold/10 font-semibold' : 'text-stone-200 hover:bg-white/5'
                  )}
                >
                  Inicio
                </Link>
              </li>
              <li>
                <Link
                  href="/quienes-somos"
                  className={cn(
                    'block rounded-xl px-4 py-2 text-sm font-medium transition-colors',
                    isActive('/quienes-somos') ? 'text-gold bg-gold/10 font-semibold' : 'text-stone-200 hover:bg-white/5'
                  )}
                >
                  Quiénes Somos
                </Link>
              </li>

              {TECNOLOGIAS_VISIBLES && (
              <li className="pt-2 pb-1 border-t border-white/5">
                <span className="px-4 text-[11px] font-semibold uppercase tracking-wider text-gold">Tecnologías</span>
                <div className="mt-1 space-y-1 pl-2">
                  <Link
                    href="/tecnologias/cableado-estructurado"
                    className="block rounded-lg px-3 py-1.5 text-xs text-stone-300 hover:text-gold hover:bg-white/5"
                  >
                    Cableado Estructurado
                  </Link>
                  <Link
                    href="/tecnologias/camaras-seguridad"
                    className="block rounded-lg px-3 py-1.5 text-xs text-stone-300 hover:text-gold hover:bg-white/5"
                  >
                    Cámaras de Seguridad
                  </Link>
                  <Link
                    href="/tecnologias/soporte-mantenimiento"
                    className="block rounded-lg px-3 py-1.5 text-xs text-stone-300 hover:text-gold hover:bg-white/5"
                  >
                    Soporte y Mantenimiento
                  </Link>
                </div>
              </li>
              )}

              <li className="pt-2 pb-1 border-t border-white/5">
                <span className="px-4 text-[11px] font-semibold uppercase tracking-wider text-gold">Educación</span>
                <div className="mt-1 space-y-1 pl-2">
                  <Link
                    href="/educacion/asesoramiento"
                    className="block rounded-lg px-3 py-1.5 text-xs text-stone-300 hover:text-gold hover:bg-white/5"
                  >
                    Asesoramiento
                  </Link>
                  <Link
                    href="/educacion/capacitaciones"
                    className="block rounded-lg px-3 py-1.5 text-xs text-stone-300 hover:text-gold hover:bg-white/5"
                  >
                    Capacitaciones
                  </Link>
                  <Link
                    href="/educacion/cursos"
                    className="block rounded-lg px-3 py-1.5 text-xs text-stone-300 hover:text-gold hover:bg-white/5"
                  >
                    Cursos
                  </Link>
                </div>
              </li>

              <li className="pt-2 border-t border-white/5">
                <Link
                  href="/galeria"
                  className={cn(
                    'block rounded-xl px-4 py-2 text-sm font-medium transition-colors',
                    isActive('/galeria') ? 'text-gold bg-gold/10 font-semibold' : 'text-stone-200 hover:bg-white/5'
                  )}
                >
                  Galería
                </Link>
              </li>
              <li>
                <Link
                  href="/contactos"
                  className={cn(
                    'block rounded-xl px-4 py-2 text-sm font-medium transition-colors',
                    isActive('/contactos') ? 'text-gold bg-gold/10 font-semibold' : 'text-stone-200 hover:bg-white/5'
                  )}
                >
                  Contáctanos
                </Link>
              </li>

              <li className="pt-3">
                <a
                  href={CONTACT_INFO.socials.whatsapp}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-gold to-gold-dark py-2.5 text-center text-sm font-semibold text-ink-dark shadow-gold"
                >
                  <PhoneCall className="h-4 w-4" />
                  <span>Escribir por WhatsApp</span>
                </a>
              </li>
            </ul>
          </div>
        )}
      </div>
    </header>
  );
}
