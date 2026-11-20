export interface HeroSlide {
  image: string;
  position: string;
  icon: string;
  eyebrow: string;
  title: string;
  highlight: string;
  text: string;
  cta: { label: string; url: string };
  cta2: { label: string; url: string };
}

export interface HeroStat {
  value: string;
  label: string;
}

export const HERO_SLIDES: HeroSlide[] = [
  {
    image: '/images/index/hero/hero-tecnologia.jpg',
    position: 'center 38%',
    icon: 'Radio',
    eyebrow: 'Telecomunicaciones y tecnología',
    title: 'Soluciones tecnológicas',
    highlight: 'para tu hogar y empresa',
    text: 'Instalación de cámaras de videovigilancia, cableado estructurado y soporte técnico de equipos informáticos en Cajamarca.',
    cta: { label: 'Conoce nuestros servicios', url: '/tecnologias/camaras-seguridad' },
    cta2: { label: 'Contáctanos', url: '/contactos' },
  },
  {
    image: '/images/index/hero/hero-capacitaciones.jpg',
    position: 'center 68%',
    icon: 'GraduationCap',
    eyebrow: 'Educación y capacitación',
    title: 'Capacítate y certifica',
    highlight: 'tus habilidades profesionales',
    text: 'Cursos, talleres y capacitaciones para estudiantes y profesionales, con certificados válidos para postulaciones a entidades públicas y privadas.',
    cta: { label: 'Ver cursos', url: '/educacion/cursos' },
    cta2: { label: 'Capacitaciones', url: '/educacion/capacitaciones' },
  },
  {
    image: '/images/index/hero/hero-convenios.jpg',
    position: 'center 55%',
    icon: 'Handshake',
    eyebrow: 'Convenios estratégicos',
    title: 'Respaldados por',
    highlight: 'colegios profesionales',
    text: 'Trabajamos con los Colegios de Enfermeros de Cajamarca y La Libertad, Economistas y Licenciados en Administración, además del instituto ISAM.',
    cta: { label: 'Ver convenios', url: '#convenios' },
    cta2: { label: 'Quiénes somos', url: '/quienes-somos' },
  },
  {
    image: '/images/index/hero/hero-asesoramiento.jpg',
    position: 'center 62%',
    icon: 'Briefcase',
    eyebrow: 'Asesoría profesional',
    title: 'Asesoramiento',
    highlight: 'educativo, empresarial y legal',
    text: 'Profesionales con amplia experiencia que te orientan en el desarrollo de tus proyectos personales y profesionales.',
    cta: { label: 'Solicitar asesoramiento', url: '/educacion/asesoramiento' },
    cta2: { label: 'Contáctanos', url: '/contactos' },
  },
];

export const HERO_STATS: HeroStat[] = [
  { value: '+400', label: 'Personas capacitadas' },
  { value: '4.8/5', label: 'Calificación promedio' },
  { value: '+200', label: 'Trabajos realizados' },
  { value: '98%', label: 'Participantes que fortalecieron sus habilidades' },
];
