export interface ChatCard {
  id: string;
  tipo: 'servicio' | 'curso';
  titulo: string;
  descripcion: string;
  url: string;
  imagen: string;
  accion: string;
}

export interface ChatLink {
  url: string;
  label: string;
  whatsapp: boolean;
}

export interface ChatResponse {
  text: string;
  cards?: ChatCard[];
  links?: ChatLink[];
}

const WHATSAPP_COTIZACION: ChatLink = {
  url: 'https://wa.me/51972021374?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n',
  label: 'Solicitar cotización por WhatsApp',
  whatsapp: true,
};

const WHATSAPP_MATRICULA: ChatLink = {
  url: 'https://wa.me/51972021374?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula',
  label: 'Consultar matrícula por WhatsApp',
  whatsapp: true,
};

const WHATSAPP_ASESOR: ChatLink = {
  url: 'https://wa.me/51972021374',
  label: 'Contactar por WhatsApp',
  whatsapp: true,
};

const CARD_CAMARAS: ChatCard = {
  id: 'servicio-camaras',
  tipo: 'servicio',
  titulo: 'Cámaras de seguridad y videovigilancia',
  descripcion:
    'Proyectos de seguridad para hogares, empresas e instituciones con cámaras IP, analógicas, Full HD, domo, 360° y fijas tipo bala; visión nocturna y acceso desde celular.',
  url: '/tecnologias/camaras-seguridad',
  imagen: '/images/tecnologias/categoria1.jpg',
  accion: 'Ver información',
};

const CARD_CABLEADO: ChatCard = {
  id: 'servicio-cableado',
  tipo: 'servicio',
  titulo: 'Cableado estructurado y redes LAN',
  descripcion:
    'Diseño e implementación de sistemas de cableado estructurado (UTP/STP) y redes LAN bajo normas técnicas para interconectar con alta velocidad las áreas de una empresa.',
  url: '/tecnologias/cableado-estructurado',
  imagen: '/images/tecnologias/cableado.jpg',
  accion: 'Ver información',
};

const CARD_SOPORTE: ChatCard = {
  id: 'servicio-soporte',
  tipo: 'servicio',
  titulo: 'Soporte y mantenimiento de equipos',
  descripcion:
    'Mantenimiento preventivo y correctivo de computadoras y laptops, limpieza interna y externa, optimización de redes Wi-Fi, antivirus y formateo.',
  url: '/tecnologias/soporte-mantenimiento',
  imagen: '/images/tecnologias/mantenimiento.png',
  accion: 'Ver información',
};

const CARD_CAPACITACIONES: ChatCard = {
  id: 'capacitaciones-empresariales',
  tipo: 'curso',
  titulo: 'Capacitaciones empresariales y profesionales',
  descripcion:
    'Programas profesionales diseñados para colaboradores de empresas e instituciones: modalidad presencial, semipresencial u online con certificación incluida.',
  url: '/educacion/capacitaciones',
  imagen: '/images/capacitaciones/capacitaciones-empresariales.jpg',
  accion: 'Ver capacitaciones',
};

const CARD_CURSOS: ChatCard = {
  id: 'cursos-tecnologia',
  tipo: 'curso',
  titulo: 'Cursos y programas de formación',
  descripcion:
    'Formación teórico-práctica con certificación digital y física en convenio con instituciones educativas. Modalidad presencial y virtual.',
  url: '/educacion/cursos',
  imagen: '/images/tecnologias/software.jpg',
  accion: 'Ver cursos',
};

function normalize(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim();
}

export function findAnswer(query: string): ChatResponse {
  const q = normalize(query);

  // 1. Cotizaciones
  if (
    q.includes('cotiz') ||
    q.includes('presupuesto') ||
    q.includes('costo') ||
    q.includes('precios') ||
    q.includes('cuanto cuesta') ||
    q.includes('tarifa')
  ) {
    return {
      text: 'Para solicitar una cotización formal y personalizada según tus requerimientos, puedes comunicarte directamente con uno de nuestros asesores por WhatsApp. Te brindaremos atención inmediata y el presupuesto adecuado.',
      links: [
        WHATSAPP_COTIZACION,
        { url: '/contactos', label: 'Formulario de contacto', whatsapp: false },
      ],
    };
  }

  // 2. Capacitaciones y formación empresarial
  if (
    q.includes('capacita') ||
    q.includes('taller') ||
    q.includes('diplomad') ||
    q.includes('empresar')
  ) {
    return {
      text: 'En Horus Group ofrecemos capacitaciones empresariales y programas especializados diseñados a la medida de instituciones y empresas. Contamos con modalidades presencial, semipresencial y virtual, con certificación avalada por convenios institucionales.',
      cards: [CARD_CAPACITACIONES, CARD_CURSOS],
      links: [
        WHATSAPP_MATRICULA,
        { url: '/educacion/capacitaciones', label: 'Ver capacitaciones', whatsapp: false },
      ],
    };
  }

  // 3. Cursos, temarios y modalidades
  if (
    q.includes('curso') ||
    q.includes('estudiar') ||
    q.includes('aprender') ||
    q.includes('modalidad') ||
    q.includes('virtual') ||
    q.includes('presencial') ||
    q.includes('temario') ||
    q.includes('malla')
  ) {
    return {
      text: 'Ofrecemos programas de formación en modalidades presencial, semipresencial (teoría en vivo grabada y práctica 100% presencial) y virtual. Entregamos certificados físicos y digitales con valor para tu currículum. Puedes consultar la disponibilidad de grupos vigentes con un asesor.',
      cards: [CARD_CURSOS, CARD_CAPACITACIONES],
      links: [
        WHATSAPP_MATRICULA,
        { url: '/educacion/cursos', label: 'Ver cursos', whatsapp: false },
      ],
    };
  }

  // 4. Cámaras de seguridad y videovigilancia
  if (
    q.includes('camara') ||
    q.includes('vigilancia') ||
    q.includes('seguridad') ||
    q.includes('cctv') ||
    q.includes('domo') ||
    q.includes('robo')
  ) {
    return {
      text: 'Desarrollamos proyectos de videovigilancia integral para hogares, negocios y empresas. Instalamos cámaras IP, analógicas, domo, bala y 360° con resolución Full HD, visión nocturna, detección de movimiento y monitoreo en tiempo real desde tu celular o computadora.',
      cards: [CARD_CAMARAS],
      links: [
        WHATSAPP_COTIZACION,
        { url: '/tecnologias/camaras-seguridad', label: 'Detalles de cámaras', whatsapp: false },
      ],
    };
  }

  // 5. Cableado estructurado y redes
  if (
    q.includes('cableado') ||
    q.includes('red') ||
    q.includes('lan') ||
    q.includes('utp') ||
    q.includes('punto de red') ||
    q.includes('servidor') ||
    q.includes('rack') ||
    q.includes('fibra') ||
    q.includes('velocidad')
  ) {
    return {
      text: 'Realizamos diseño, instalación y certificación de cableado estructurado (UTP/STP categorías 6 y 6A) y redes LAN bajo rigurosas normas técnicas (ANSI/TIA), garantizando alta velocidad, orden y estabilidad para empresas y oficinas.',
      cards: [CARD_CABLEADO],
      links: [
        WHATSAPP_COTIZACION,
        { url: '/tecnologias/cableado-estructurado', label: 'Ver cableado y redes', whatsapp: false },
      ],
    };
  }

  // 6. Soporte técnico y mantenimiento
  if (
    q.includes('soporte') ||
    q.includes('mantenimiento') ||
    q.includes('computador') ||
    q.includes('pc') ||
    q.includes('laptop') ||
    q.includes('formate') ||
    q.includes('lenta') ||
    q.includes('antivirus') ||
    q.includes('reparaci') ||
    q.includes('limpieza') ||
    q.includes('impresora')
  ) {
    return {
      text: 'Brindamos servicio técnico preventivo y correctivo para computadoras, laptops y equipos de oficina. Solucionamos problemas de lentitud, sobrecalentamiento, virus, configuración de correos corporativos, impresoras y optimización de redes Wi-Fi.',
      cards: [CARD_SOPORTE],
      links: [
        WHATSAPP_COTIZACION,
        { url: '/tecnologias/soporte-mantenimiento', label: 'Ver soporte técnico', whatsapp: false },
      ],
    };
  }

  // 7. Servicios tecnológicos en general
  if (
    q.includes('servicio') ||
    q.includes('tecnologia') ||
    q.includes('solucion') ||
    q.includes('que ofrecen') ||
    q.includes('que hacen')
  ) {
    return {
      text: 'Nuestros servicios tecnológicos principales son: 1) Cámaras de seguridad y videovigilancia, 2) Cableado estructurado y redes LAN, y 3) Soporte y mantenimiento integral de equipos informáticos. Además, brindamos capacitaciones y asesoría técnica profesional.',
      cards: [CARD_CAMARAS, CARD_CABLEADO, CARD_SOPORTE],
      links: [
        WHATSAPP_COTIZACION,
        { url: '/contactos', label: 'Contactar con Horus', whatsapp: false },
      ],
    };
  }

  // 8. Matrícula e inscripción
  if (
    q.includes('matricula') ||
    q.includes('inscribir') ||
    q.includes('inscripcion') ||
    q.includes('registro') ||
    q.includes('pagar') ||
    q.includes('requisito')
  ) {
    return {
      text: 'Para iniciar tu proceso de matrícula, consultar vacantes disponibles y métodos de pago, comunícate con nuestra área de admisiones por WhatsApp. Te enviaremos el formulario y los pasos de confirmación de inmediato.',
      links: [
        WHATSAPP_MATRICULA,
        { url: '/contactos', label: 'Formulario de contacto', whatsapp: false },
      ],
    };
  }

  // 9. Convenios y certificaciones
  if (
    q.includes('convenio') ||
    q.includes('certificado') ||
    q.includes('diploma') ||
    q.includes('colegio de profesores') ||
    q.includes('unprg') ||
    q.includes('validez')
  ) {
    return {
      text: 'Nuestros programas de capacitación cuentan con el respaldo de importantes convenios institucionales, entre ellos el Colegio de Profesores del Perú y la Universidad Nacional Pedro Ruiz Gallo (UNPRG). Los certificados se emiten tanto en formato físico como digital con código de verificación.',
      cards: [CARD_CAPACITACIONES],
      links: [
        WHATSAPP_MATRICULA,
        { url: '/educacion/capacitaciones', label: 'Ver convenios y capacitaciones', whatsapp: false },
      ],
    };
  }

  // 10. Ubicación, dirección y sede
  if (
    q.includes('ubicacion') ||
    q.includes('direccion') ||
    q.includes('donde estan') ||
    q.includes('donde quedan') ||
    q.includes('sede') ||
    q.includes('oficina') ||
    q.includes('local')
  ) {
    return {
      text: 'Nuestra sede principal se encuentra ubicada en Jr. José Gálvez N.° 322, en la ciudad de Cajamarca, Perú. En la sección de Contactos podrás ver el mapa interactivo y cómo llegar fácilmente.',
      links: [
        { url: '/contactos', label: 'Ver mapa y ubicación', whatsapp: false },
        WHATSAPP_ASESOR,
      ],
    };
  }

  // 11. Horario de atención
  if (
    q.includes('horario') ||
    q.includes('hora') ||
    q.includes('atienden') ||
    q.includes('abren') ||
    q.includes('sabado') ||
    q.includes('domingo')
  ) {
    return {
      text: 'Atendemos de lunes a viernes en horario de oficina (9:00 a 13:00 y de 15:00 a 18:00) y los sábados de 9:00 a 13:00. Para confirmar disponibilidad antes de acudir presencialmente, puedes escribirnos por WhatsApp al +51 972 021 374.',
      links: [
        WHATSAPP_ASESOR,
        { url: '/contactos', label: 'Ver contactos', whatsapp: false },
      ],
    };
  }

  // 12. Contacto, teléfono, WhatsApp, correo
  if (
    q.includes('contacto') ||
    q.includes('telefono') ||
    q.includes('celular') ||
    q.includes('whatsapp') ||
    q.includes('wsp') ||
    q.includes('numero') ||
    q.includes('correo') ||
    q.includes('email') ||
    q.includes('asesor')
  ) {
    return {
      text: 'Puedes comunicarte con nosotros por los siguientes canales directos:\n• Teléfono / WhatsApp: +51 972 021 374\n• Correo electrónico: horusgroupcajamarca@gmail.com\n• Sede: Jr. José Gálvez N.° 322, Cajamarca\n• O a través del formulario web en /contactos.',
      links: [
        WHATSAPP_ASESOR,
        { url: '/contactos', label: 'Ir a Contactos', whatsapp: false },
      ],
    };
  }

  // 13. Quiénes somos, empresa, RUC, misión y visión
  if (
    q.includes('quienes son') ||
    q.includes('empresa') ||
    q.includes('horus') ||
    q.includes('mision') ||
    q.includes('vision') ||
    q.includes('valores') ||
    q.includes('ruc')
  ) {
    return {
      text: 'Horus Group SRL (RUC 20611010977) es una empresa de Cajamarca especializada en brindar soluciones tecnológicas avanzadas, capacitación profesional y soporte informático integral para hogares, empresas e instituciones.',
      links: [
        { url: '/quienes-somos', label: 'Conocer más sobre nosotros', whatsapp: false },
        WHATSAPP_ASESOR,
      ],
    };
  }

  // 14. Redes sociales
  if (
    q.includes('redes') ||
    q.includes('facebook') ||
    q.includes('instagram') ||
    q.includes('tiktok') ||
    q.includes('youtube')
  ) {
    return {
      text: 'Encuéntranos en redes sociales como @horusgroupsrl (Facebook, Instagram) y @horus.group.srl (TikTok). En YouTube nos encuentras como @HORUSGROUPSRL. ¡Síguenos para enterarte de nuevos cursos, proyectos y novedades tecnológicas!',
      links: [
        { url: 'https://wa.me/51972021374', label: 'Contactar por WhatsApp', whatsapp: true },
      ],
    };
  }

  // 15. Saludo genérico
  if (
    q === 'hola' ||
    q.startsWith('hola ') ||
    q === 'buenas' ||
    q.startsWith('buenas ') ||
    q === 'buenos dias' ||
    q === 'buenas tardes' ||
    q === 'buenas noches'
  ) {
    return {
      text: '¡Hola! Es un gusto saludarte. ¿En qué puedo orientarte hoy? Puedes consultarme sobre capacitaciones, cursos, cámaras de videovigilancia, cableado estructurado, soporte de computadoras o solicitar una cotización.',
      links: [
        { url: '/educacion/capacitaciones', label: 'Ver capacitaciones', whatsapp: false },
        { url: '/tecnologias/camaras-seguridad', label: 'Ver cámaras de seguridad', whatsapp: false },
      ],
    };
  }

  // Fallback
  return {
    text: 'Entiendo tu consulta. Puedes explorar nuestras secciones de capacitación y servicios tecnológicos, o si prefieres una orientación personalizada e inmediata, escribirnos directamente por WhatsApp al +51 972 021 374 con un asesor.',
    cards: [CARD_CAPACITACIONES, CARD_CAMARAS, CARD_SOPORTE],
    links: [
      WHATSAPP_ASESOR,
      { url: '/contactos', label: 'Contactar con Horus', whatsapp: false },
    ],
  };
}
