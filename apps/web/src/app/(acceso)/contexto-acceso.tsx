'use client';

import { KeyRound, LockKeyhole, MailCheck, ShieldCheck, UserCheck } from 'lucide-react';
import { useSelectedLayoutSegment } from 'next/navigation';
import type { ReactNode } from 'react';
import { LogoHorus } from '@/components/shared/marca-horus';

interface ContextoRuta {
  titulo: string;
  descripcion: string;
  nota: string;
  icono: ReactNode;
}

const CONTEXTO_POR_RUTA: Record<string, ContextoRuta> = {
  login: {
    titulo: 'Gestión académica y administrativa',
    descripcion: 'Acceso interno para coordinar cursos, ediciones, matrículas y comprobantes de Horus Group SRL.',
    nota: 'Sesión protegida con verificación en dos pasos (2FA) compatible.',
    icono: <ShieldCheck className="h-4 w-4 shrink-0 text-amber-300" aria-hidden="true" />,
  },
  registro: {
    titulo: 'Alta de cuenta en el sistema',
    descripcion: 'Registra tus datos para solicitar acceso. Los permisos del panel son asignados por un administrador.',
    nota: 'Las cuentas nuevas inician sin acceso hasta su habilitación.',
    icono: <UserCheck className="h-4 w-4 shrink-0 text-amber-300" aria-hidden="true" />,
  },
  'recuperar-contrasena': {
    titulo: 'Recuperación de acceso',
    descripcion: 'Solicita un enlace temporal de restablecimiento enviado directamente a tu correo registrado.',
    nota: 'El enlace de recuperación es de un solo uso y vence en 60 minutos.',
    icono: <MailCheck className="h-4 w-4 shrink-0 text-amber-300" aria-hidden="true" />,
  },
  'restablecer-contrasena': {
    titulo: 'Define una nueva clave',
    descripcion: 'Usa al menos 10 caracteres combinando mayúsculas, minúsculas y números para proteger tu cuenta.',
    nota: 'Al guardar, se cerrarán todas tus sesiones abiertas por seguridad.',
    icono: <KeyRound className="h-4 w-4 shrink-0 text-amber-300" aria-hidden="true" />,
  },
};

const CONTEXTO_PREDETERMINADO: ContextoRuta = CONTEXTO_POR_RUTA.login;

/**
 * Columna de marca institucional (escritorio) con copy contextual según la ruta activa de `(acceso)`.
 * Paleta Azul + Ámbar: fondo azul profundo (`academico-950` / `tinta`) con acentos ámbar luminoso (`amber-300` / `amber-400`).
 */
export function PanelMarcaAcceso() {
  const segmento = useSelectedLayoutSegment();
  const contexto = (segmento ? CONTEXTO_POR_RUTA[segmento] : undefined) ?? CONTEXTO_PREDETERMINADO;

  return (
    <aside className="relative hidden flex-col justify-between overflow-hidden border-r border-academico-800/80 bg-gradient-to-br from-academico-950 via-tinta to-academico-900 p-10 text-white lg:flex xl:p-14">
      {/* Textura arquitectónica sutil y destellos contenidos (sin marca de agua detrás del texto). */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_0%_0%,rgba(251,191,36,0.09),transparent_48%),radial-gradient(ellipse_at_100%_100%,rgba(52,106,158,0.22),transparent_56%)]"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:48px_48px]"
      />

      {/* Cabecera de marca */}
      <div className="relative flex w-fit items-center gap-3.5">
        <LogoHorus className="h-11 w-auto shrink-0" prioridad />
        <div className="leading-tight">
          <p className="font-heading text-sm font-bold tracking-[0.18em] text-white">HORUS GROUP</p>
          <p className="mt-1 text-xs font-medium text-academico-200">Horus Academic</p>
        </div>
      </div>

      {/* Mensaje institucional contextual */}
      <div className="relative my-auto max-w-md py-12">
        <span aria-hidden="true" className="mb-7 block h-0.5 w-11 rounded-full bg-amber-400" />
        <h2 className="font-heading text-3xl font-bold leading-[1.12] tracking-tight text-white text-balance xl:text-4xl">
          {contexto.titulo}
        </h2>
        <p className="mt-5 max-w-sm text-sm leading-6 text-academico-100">
          {contexto.descripcion}
        </p>

        <div className="mt-8 flex items-start gap-3 border-t border-white/10 pt-5 text-xs leading-5 text-academico-100">
          <span className="mt-0.5">{contexto.icono}</span>
          <p>{contexto.nota}</p>
        </div>
      </div>

      {/* Pie institucional */}
      <div className="relative flex items-center justify-between gap-4 border-t border-white/10 pt-5 text-xs text-academico-200">
        <p className="flex items-center gap-2 font-medium">
          <span aria-hidden="true" className="size-2 rounded-full bg-amber-400" />
          Cajamarca, Perú
        </p>
        <p className="flex items-center gap-1.5 text-academico-200">
          <LockKeyhole className="h-3.5 w-3.5 text-amber-300" aria-hidden="true" />
          Uso interno · Horus Group SRL
        </p>
      </div>
    </aside>
  );
}

/**
 * Banda superior compacta para pantallas menores a `lg`, manteniendo la identidad Azul + Ámbar en móvil.
 */
export function CabeceraMovilAcceso() {
  return (
    <div className="border-b border-amber-400/40 bg-gradient-to-r from-academico-950 via-tinta to-academico-900 px-5 py-4 text-white lg:hidden">
      <div className="mx-auto flex w-full max-w-md items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <LogoHorus className="h-9 w-auto shrink-0" prioridad />
          <div className="leading-tight">
            <p className="font-heading text-sm font-bold tracking-[0.16em] text-white">HORUS GROUP</p>
            <p className="mt-0.5 text-xs text-academico-200">Horus Academic</p>
          </div>
        </div>
        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-300">
          <span aria-hidden="true" className="size-1.5 rounded-full bg-amber-400" />
          Acceso
        </span>
      </div>
    </div>
  );
}
