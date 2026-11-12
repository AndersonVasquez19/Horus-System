export interface GaleriaFoto {
  id: string;
  cat: 'capacitaciones' | 'convenios' | 'servicio-tecnico' | 'primeros-auxilios';
  label: string;
  alt: string;
  src: string;
  w: number;
  h: number;
  pos?: string;
}

export const GALERIA_CATEGORIAS = [
  { id: 'all', label: 'Todas' },
  { id: 'capacitaciones', label: 'Capacitaciones' },
  { id: 'convenios', label: 'Convenios' },
  { id: 'servicio-tecnico', label: 'Servicio técnico' },
  { id: 'primeros-auxilios', label: 'Primeros auxilios' },
] as const;

export const GALERIA_FOTOS: GaleriaFoto[] = [
  {
    id: 'cap-1',
    cat: 'capacitaciones',
    label: 'Capacitaciones',
    alt: 'Capacitación de Horus Group con participantes',
    src: '/images/galeria/capacitaciones/imagen 1.jpg',
    w: 1600,
    h: 721,
  },
  {
    id: 'conv-1',
    cat: 'convenios',
    label: 'Colegio de Abogados',
    alt: 'Actividad con el Colegio de Abogados',
    src: '/images/galeria/colegio de abogados/imagen 4-web.jpg',
    w: 1600,
    h: 715,
  },
  {
    id: 'conv-2',
    cat: 'convenios',
    label: 'Colegio de Abogados',
    alt: 'Firma de convenio y entrega de diplomas',
    src: '/images/galeria/colegio de abogados/imagen 10.jpg',
    w: 1156,
    h: 521,
  },
  {
    id: 'conv-3',
    cat: 'convenios',
    label: 'Colegio de Enfermeros',
    alt: 'Actividad con el Colegio de Enfermeros',
    src: '/images/galeria/colegio de enfermeros/imagen 3.jpg',
    w: 1600,
    h: 900,
  },
  {
    id: 'conv-4',
    cat: 'convenios',
    label: 'Colegio de Enfermeros',
    alt: 'Taller práctico con profesionales de la salud',
    src: '/images/galeria/colegio de enfermeros/imagen 11-web.jpg',
    w: 1600,
    h: 1600,
  },
  {
    id: 'isam-1',
    cat: 'convenios',
    label: 'ISAM',
    alt: 'Actividad en el ISAM',
    src: '/images/galeria/ISAM/imagen 1.jpg',
    w: 1280,
    h: 960,
  },
  {
    id: 'isam-2',
    cat: 'convenios',
    label: 'ISAM',
    alt: 'Capacitación técnica en aulas de ISAM',
    src: '/images/galeria/ISAM/imagen 4.jpg',
    w: 960,
    h: 1280,
    pos: 'center 30%',
  },
  {
    id: 'tec-1',
    cat: 'servicio-tecnico',
    label: 'Servicio técnico',
    alt: 'Servicio técnico de equipos informáticos en taller',
    src: '/images/galeria/nuestro servicio tecnico/imagen 5.jpg',
    w: 1156,
    h: 521,
  },
  {
    id: 'tec-2',
    cat: 'servicio-tecnico',
    label: 'Servicio técnico',
    alt: 'Mantenimiento preventivo de computadoras',
    src: '/images/galeria/nuestro servicio tecnico/imagen 7.jpg',
    w: 1280,
    h: 575,
  },
  {
    id: 'aux-1',
    cat: 'primeros-auxilios',
    label: 'Práctica de primeros auxilios',
    alt: 'Práctica de reanimación y primeros auxilios con simulador',
    src: '/images/galeria/practicas de primeros auxilios/imagen 1.jpg',
    w: 1280,
    h: 575,
  },
  {
    id: 'aux-2',
    cat: 'primeros-auxilios',
    label: 'Práctica de primeros auxilios',
    alt: 'Taller de vendajes y atención de emergencias',
    src: '/images/galeria/practicas de primeros auxilios/imagen 7.jpg',
    w: 1156,
    h: 521,
  },
];
