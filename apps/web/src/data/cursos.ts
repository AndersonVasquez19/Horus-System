export interface Curso {
  id: string;
  titulo: string;
  resumen: string;
  modalidad: 'presencial' | 'semipresencial' | 'virtual' | 'hibrido';
  horario: string;
  duracion: string;
  certificacion: string;
  destacado?: boolean;
}

export const MODALIDADES_INFO = [
  {
    id: 'presencial',
    titulo: 'PRESENCIAL',
    badge: 'Tradicional',
    descripcion:
      'Nuestras sesiones teóricas y prácticas se desarrollan íntegramente de forma presencial. El objetivo es potenciar tus habilidades y destrezas mediante un aprendizaje directo y personalizado.',
    features: ['Interacción directa con docentes', 'Práctica inmediata en laboratorios', 'Networking profesional presencial'],
  },
  {
    id: 'semipresencial',
    titulo: 'SEMIPRESENCIAL',
    badge: 'Más Popular',
    destacada: true,
    descripcion:
      'Combina la flexibilidad digital con la experiencia práctica. Clases teóricas online en vivo con acceso a grabaciones y sesiones prácticas 100% presenciales adaptadas a tus horarios.',
    features: ['Lo mejor de ambos mundos', 'Clases grabadas 24/7', 'Talleres prácticos intensivos'],
  },
  {
    id: 'virtual',
    titulo: 'VIRTUAL',
    badge: '100% Online',
    descripcion:
      'Lleva tus clases desde la comodidad de tu hogar, donde puedas seguir aprendiendo a tu propio ritmo con acceso permanente a nuestras plataformas y materiales descargables.',
    features: ['Desde cualquier lugar del país', 'Acceso ilimitado a grabaciones', 'Evaluaciones flexibles'],
  },
] as const;

export const CURSOS_CATALOGO: Curso[] = [
  {
    id: 'curso-camaras-cctv',
    titulo: 'Instalación y Configuración de Cámaras de Seguridad (CCTV e IP)',
    resumen:
      'Aprende a diseñar, cablear, montar y configurar sistemas de videovigilancia analógicos e IP, visualización remota en móviles y configuración de NVR/DVR.',
    modalidad: 'semipresencial',
    horario: 'Sábados de 3:00 PM a 7:00 PM',
    duracion: '40 Horas pedagógicas',
    certificacion: 'Certificado físico y digital con valor curricular',
    destacado: true,
  },
  {
    id: 'curso-cableado-estructurado',
    titulo: 'Cableado Estructurado y Redes de Datos (UTP/Fibra Óptica)',
    resumen:
      'Fundamentos normativos ANSI/TIA/EIA, conectorización, armado de racks, patch panels y certificación de puntos de red categoría 6/6A.',
    modalidad: 'presencial',
    horario: 'Domingos de 9:00 AM a 1:00 PM',
    duracion: '36 Horas pedagógicas',
    certificacion: 'Certificado oficial con respaldo institucional',
    destacado: true,
  },
  {
    id: 'curso-soporte-reparacion',
    titulo: 'Soporte Técnico y Reparación de Computadoras y Laptops',
    resumen:
      'Diagnóstico de hardware, reparación de placas, reemplazo de componentes, optimización de sistemas operativos y rescate de información.',
    modalidad: 'semipresencial',
    horario: 'Lunes, Miércoles y Viernes de 7:00 PM a 9:00 PM',
    duracion: '48 Horas pedagógicas',
    certificacion: 'Certificado con horas lectivas para convocatorias',
  },
  {
    id: 'curso-ofimatica-empresarial',
    titulo: 'Ofimática Profesional y Herramientas Digitales (Excel Avanzado)',
    resumen:
      'Domina tablas dinámicas, funciones financieras, macros, automatización de reportes y herramientas en la nube para el entorno corporativo.',
    modalidad: 'virtual',
    horario: 'Horario flexible (asincrónico con tutorías)',
    duracion: '60 Horas pedagógicas',
    certificacion: 'Certificación digital con código QR de verificación',
  },
  {
    id: 'curso-primeros-auxilios',
    titulo: 'Primeros Auxilios Básicos y Atención Prehospitalaria',
    resumen:
      'Técnicas de RCP, inmovilización, vendajes, manejo de atragantamientos y primeros auxilios en entornos laborales y comunitarios.',
    modalidad: 'presencial',
    horario: 'Sábados intensivos de 9:00 AM a 1:00 PM',
    duracion: '24 Horas pedagógicas',
    certificacion: 'Certificado con respaldo de Colegio Profesional aliado',
  },
];
