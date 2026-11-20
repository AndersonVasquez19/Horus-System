import type { Metadata } from 'next';
import Link from 'next/link';
import { CLASE_BOTON_PRIMARIO, CLASE_ENLACE, EstadoError } from '@/components/error/estado-error';

export const metadata: Metadata = { title: 'Página no encontrada | Horus Group', robots: { index: false, follow: false } };

export default function NotFound() {
  return (
    <EstadoError codigo="404" titulo="No encontramos esta página" texto="La dirección no existe o el contenido ya no está disponible.">
      <Link href="/" className={CLASE_BOTON_PRIMARIO}>
        Ir al inicio
      </Link>
      <Link href="/contactos" className={CLASE_ENLACE}>
        Contactar a Horus
      </Link>
    </EstadoError>
  );
}
