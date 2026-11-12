import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { MarcaPanel } from '@/components/shared/marca-panel';

/*
 * Layout común de /admin (solo estructura).
 *
 * No hay páginas bajo este segmento, por lo que ninguna ruta de panel está expuesta todavía.
 * La autenticación y la protección de rutas llegan en una etapa posterior.
 *
 * `data-panel-root` (vía MarcaPanel) activa el aislamiento del tema público (globals.css):
 * mientras este contenedor esté en pantalla, <html> y <body> adoptan el tema de panel y se
 * ocultan la barra, el pie y el chat públicos. Cualquier portal, menú o diálogo que se pinte en
 * document.body lo hereda. No sustituir MarcaPanel por un atributo fijo: con `cacheComponents`
 * Next conserva el panel oculto en el DOM al salir y el tema se filtraría al sitio público.
 */

// Los metadatos se combinan de forma superficial con los del sitio público: cada clave pública
// que no deba heredarse (descripción, palabras clave, Open Graph) se sustituye aquí.
export const metadata: Metadata = {
  title: {
    default: 'Panel administrativo',
    template: '%s | Panel Horus',
  },
  description: 'Panel administrativo interno de Horus Group SRL.',
  keywords: [],
  openGraph: {
    type: 'website',
    siteName: 'Horus Group SRL',
    locale: 'es_PE',
    title: 'Panel administrativo',
  },
  robots: {
    index: false,
    follow: false,
    nocache: true,
    googleBot: { index: false, follow: false, noimageindex: true },
  },
};

export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <MarcaPanel atributo="data-panel-root" className="min-h-screen bg-panel-bg text-panel-text antialiased">
      {children}
    </MarcaPanel>
  );
}
