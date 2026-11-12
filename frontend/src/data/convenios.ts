export interface Convenio {
  id: string;
  nombre: string;
  siglas: string;
  vigencia: string;
  descripcion: string;
  imagen?: string | null;
  posicion?: string | null;
  logo: string;
}

export const CONVENIOS: Convenio[] = [
  {
    id: 'cep-cajamarca',
    nombre: 'Colegio de Enfermeros de Cajamarca - CR XIII',
    siglas: 'CEP / CR XIII',
    vigencia: '20 de noviembre del 2026',
    descripcion:
      'Convenio Interinstitucional con el Colegio de Enfermeros de Cajamarca - CR XIII para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Enfermería. Certificados emitidos con firma del Decano, válidos para postulaciones a entidades públicas y privadas.',
    imagen: '/images/index/convenios-fotos/convenio-cep-cr13.jpg',
    posicion: 'center 40%',
    logo: '/images/index/logos/logo-convenio-enfermeros.png',
  },
  {
    id: 'corlad',
    nombre: 'Colegio Regional de Licenciados en Administración de Cajamarca',
    siglas: 'CORLAD',
    vigencia: '10 de diciembre del 2026',
    descripcion:
      'Convenio Interinstitucional con el Colegio Regional de Licenciados en Administración de Cajamarca (CORLAD) para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de las Ciencias Administrativas. Certificados con firma del Decano.',
    imagen: '/images/index/convenios-fotos/convenio-corlad.jpg',
    posicion: 'center 30%',
    logo: '/images/index/logos/logo-convenio-administracion.jpg',
  },
  {
    id: 'economistas',
    nombre: 'Colegio de Economistas de Cajamarca',
    siglas: 'Colegio de Economistas',
    vigencia: '6 de febrero del 2027',
    descripcion:
      'Convenio Interinstitucional con el Colegio de Economistas de Cajamarca para la realización de eventos académicos, conferencias y programas de especialización en ciencias económicas y financieras.',
    imagen: '/images/index/convenios-fotos/convenio-economistas.jpg',
    posicion: 'center 20%',
    logo: '/images/index/logos/logo-convenio-economistas.jpg',
  },
  {
    id: 'isam',
    nombre: 'Instituto de Educación Superior ISAM',
    siglas: 'ISAM',
    vigencia: '6 de enero del 2027',
    descripcion:
      'Convenio Interinstitucional con el Instituto de Educación Superior ISAM para el desarrollo conjunto de programas formativos, talleres y certificaciones técnicas.',
    imagen: '/images/index/convenios-fotos/convenio-isam.jpg',
    posicion: 'center',
    logo: '/images/index/logos/logo-convenio-isam.png',
  },
  {
    id: 'cep-cr2',
    nombre: 'Colegio de Enfermeros del Perú - CR II La Libertad',
    siglas: 'CEP / CR II',
    vigencia: '4 de junio del 2029',
    descripcion:
      'Convenio Interinstitucional con el Colegio de Enfermeros del Perú - Consejo Regional II La Libertad para la realización de talleres, charlas, cursos y diplomados en la región norte.',
    imagen: null,
    posicion: null,
    logo: '/images/index/logos/logo-convenio-enfermeros.png',
  },
];
