import type { Metadata } from 'next';
import { Poppins, Montserrat, Bricolage_Grotesque, Figtree } from 'next/font/google';
import './globals.css';
import { Navbar } from '@/components/layout/navbar';
import { Footer } from '@/components/layout/footer';
import { ChatWidget } from '@/components/chat/chat-widget';

const poppins = Poppins({
  weight: ['300', '400', '500', '600', '700'],
  subsets: ['latin'],
  variable: '--font-poppins',
  display: 'swap',
});

// Tipografía del panel administrativo (solo se aplica bajo [data-panel-root]; ver globals.css). Sin precarga: el sitio público no la usa.
const panelDisplay = Bricolage_Grotesque({ subsets: ['latin'], variable: '--font-panel-display', display: 'swap', preload: false });
const panelTexto = Figtree({ subsets: ['latin'], variable: '--font-panel-texto', display: 'swap', preload: false });

const montserrat = Montserrat({
  weight: ['500', '600', '700', '800'],
  subsets: ['latin'],
  variable: '--font-montserrat',
  display: 'swap',
});

export const metadata: Metadata = {
  metadataBase: new URL('https://horusgroupsrl.com'),
  title: {
    default: 'Horus Group SRL | Soluciones en tecnología y capacitación en Cajamarca',
    template: '%s | Horus Group SRL',
  },
  description:
    'Horus Group SRL: especialistas en cableado estructurado, cámaras de seguridad, soporte técnico, capacitaciones y asesoramiento profesional en Cajamarca, Perú.',
  keywords: [
    'Horus Group',
    'Cajamarca',
    'cámaras de seguridad',
    'cableado estructurado',
    'soporte técnico',
    'cursos de tecnología',
    'capacitaciones profesionales',
    'convenios institucionales',
    'diplomados',
  ],
  authors: [{ name: 'Horus Group SRL' }],
  creator: 'Horus Group SRL',
  openGraph: {
    type: 'website',
    locale: 'es_PE',
    url: 'https://horusgroupsrl.com',
    siteName: 'Horus Group SRL',
    title: 'Horus Group SRL | Tecnología, Capacitaciones y Soporte en Cajamarca',
    description:
      'Soluciones tecnológicas, capacitaciones y soporte para hogares, empresas y profesionales en Cajamarca, Perú.',
    images: [
      {
        url: '/images/index/hero/hero-tecnologia.jpg',
        width: 1200,
        height: 630,
        alt: 'Horus Group SRL',
      },
    ],
  },
  icons: {
    icon: '/images/index/logos/logo-horus.png',
    apple: '/images/index/logos/logo-horus.png',
  },
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="es" className={`${poppins.variable} ${montserrat.variable} ${panelDisplay.variable} ${panelTexto.variable} scroll-smooth`}>
      <body className="min-h-screen flex flex-col bg-[#0A2540] text-slate-100 antialiased selection:bg-gold selection:text-ink-dark">
        {/*
          Los contenedores `contents` no alteran el diseño público. Los paneles (/admin) los ocultan
          con CSS (globals.css, bloque «Aislamiento del tema público»): sin lógica de ruta en el
          cliente, sin desajuste de hidratación en el 404 prerenderizado y sin parpadeo.
        */}
        <div data-chrome-publico className="contents">
          <Navbar />
        </div>
        <main className="flex-1">{children}</main>
        <div data-chrome-publico className="contents">
          <Footer />
          <ChatWidget />
        </div>
      </body>
    </html>
  );
}
