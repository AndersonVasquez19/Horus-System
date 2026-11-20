'use client';

import { useRouter } from 'next/navigation';
import { LogOut } from 'lucide-react';
import { useState } from 'react';
import { llamarBff } from '@/lib/panel/cliente';

interface BotonCerrarSesionProps {
  /** Clases del botón (el contenedor decide el contraste). */
  className?: string;
}

export function BotonCerrarSesion({ className = '' }: BotonCerrarSesionProps) {
  const router = useRouter();
  const [saliendo, setSaliendo] = useState(false);

  async function salir() {
    if (saliendo) return;
    setSaliendo(true);
    // El BFF borra la cookie aunque Laravel no responda.
    await llamarBff('/api/bff/auth/logout', { cuerpo: {} });
    router.replace('/login');
    router.refresh();
  }

  return (
    <button
      type="button"
      onClick={salir}
      disabled={saliendo}
      aria-busy={saliendo || undefined}
      className={`inline-flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium outline-offset-2 focus-visible:outline-2 disabled:opacity-60 ${className}`}
    >
      <LogOut className="h-4 w-4" aria-hidden="true" />
      {/* Con muy poco ancho solo se ve el icono; el texto sigue disponible para lectores de pantalla. */}
      <span className="sr-only min-[400px]:not-sr-only">{saliendo ? 'Cerrando…' : 'Cerrar sesión'}</span>
    </button>
  );
}
