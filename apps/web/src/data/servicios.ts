export interface ServicioItem {
  id: string;
  titulo: string;
  descripcion: string;
  imagen: string;
  especificaciones?: string[];
  servicioCotizar: string;
}

export const CABLEADO_CATEGORIAS: ServicioItem[] = [
  {
    id: 'cat-1',
    titulo: 'Categoría 1',
    descripcion: 'Diseñada para comunicaciones telefónicas analógicas. No es apta para transmisión de datos modernos debido a sus limitadas capacidades de frecuencia.',
    imagen: '/images/tecnologias/categoria1.jpg',
    especificaciones: ['Velocidad máx: 512 kbit/s'],
    servicioCotizar: 'Categoría 1',
  },
  {
    id: 'cat-2',
    titulo: 'Categoría 2',
    descripcion: 'Utilizada en redes Token Ring de primera generación. Soporta transmisión de voz y datos básicos en entornos de baja demanda.',
    imagen: '/images/tecnologias/categoria2.webp',
    especificaciones: ['Velocidad máx: 4 Mbit/s'],
    servicioCotizar: 'Categoría 2',
  },
  {
    id: 'cat-3',
    titulo: 'Categoría 3',
    descripcion: 'Estándar para redes Ethernet 10BaseT. Ampliamente utilizada en instalaciones de voz y datos en edificios de oficinas durante los años 90.',
    imagen: '/images/tecnologias/categoria3.webp',
    especificaciones: ['Velocidad máx: 10 Mbit/s'],
    servicioCotizar: 'Categoría 3',
  },
  {
    id: 'cat-4',
    titulo: 'Categoría 4',
    descripcion: 'Optimizada para redes Token Ring de alta velocidad. Ofrece mayor ancho de banda que su predecesora, con mejor rendimiento en entornos corporativos.',
    imagen: '/images/tecnologias/categoria4.jpg',
    especificaciones: ['Velocidad máx: 16 Mbit/s'],
    servicioCotizar: 'Categoría 4',
  },
  {
    id: 'cat-5',
    titulo: 'Categoría 5',
    descripcion: 'Estándar para redes Fast Ethernet y Gigabit. Ideal para infraestructuras de red modernas que requieren alta velocidad y confiabilidad en la transmisión.',
    imagen: '/images/tecnologias/categoria5.jpeg',
    especificaciones: ['Velocidad máx: 100 Mbit/s'],
    servicioCotizar: 'Categoría 5',
  },
  {
    id: 'cat-6',
    titulo: 'Categoría 6',
    descripcion: 'Solución de alto rendimiento para centros de datos y redes empresariales. Soporta aplicaciones de video en alta definición y comunicaciones críticas.',
    imagen: '/images/tecnologias/categoria6.jpg',
    especificaciones: ['Velocidad máx: 10 Gbit/s'],
    servicioCotizar: 'Categoría 6',
  },
];

export const CAMARAS_ITEMS: ServicioItem[] = [
  {
    id: 'cam-ip',
    titulo: 'CÁMARAS IP',
    descripcion: 'Garantizamos la seguridad de sus espacios con tecnología de última generación.',
    imagen: '/images/videovigilancia/camara-ip.jpg',
    servicioCotizar: 'CÁMARAS IP',
  },
  {
    id: 'cam-analogicas',
    titulo: 'CÁMARAS ANALÓGICAS',
    descripcion: 'Es la solución ideal para quienes buscan un sistema de vigilancia estable, económico y de larga duración.',
    imagen: '/images/videovigilancia/camara-analogica.jpg',
    servicioCotizar: 'CÁMARAS ANALÓGICAS',
  },
  {
    id: 'cam-full-hd',
    titulo: 'CÁMARAS FULL HD',
    descripcion: 'Si lo que buscas es que la imagen no se "pixele" al hacer zoom, la tecnología Full HD es la opción indispensable.',
    imagen: '/images/videovigilancia/camara-full-hd.jpg',
    servicioCotizar: 'CÁMARAS FULL HD',
  },
  {
    id: 'cam-domo',
    titulo: 'CÁMARAS DOMO',
    descripcion: 'Si necesitas vigilar interiores (oficinas, tiendas o casas) de forma elegante y resistente, la Cámara Domo es la elección profesional.',
    imagen: '/images/videovigilancia/camara-domo.jpg',
    servicioCotizar: 'CÁMARAS DOMO',
  },
  {
    id: 'cam-360',
    titulo: 'CÁMARAS DE 360°',
    descripcion: 'Si quieres ver todo el lugar al mismo tiempo con un diseño discreto en el techo, la Cámara de 360° es la opción más eficiente.',
    imagen: '/images/videovigilancia/camara-360.jpg',
    servicioCotizar: 'CÁMARAS DE 360°',
  },
  {
    id: 'cam-bala',
    titulo: 'CÁMARAS FIJAS (TUBO O BALA)',
    descripcion: 'Si necesitas que la gente sepa que la estás vigilando y quieres proteger exteriores o pasillos largos, la Cámara de Bala es la mejor herramienta.',
    imagen: '/images/videovigilancia/camara-tubo-bala.jpg',
    servicioCotizar: 'CÁMARAS FIJAS (TUBO O BALA)',
  },
  {
    id: 'sensor-movimiento',
    titulo: 'Sensores de Movimiento',
    descripcion: 'Añaden una capa de inteligencia a su sistema de seguridad, permitiendo que la tecnología actúe por usted.',
    imagen: '/images/videovigilancia/sensor-movimiento.jpg',
    servicioCotizar: 'Sensores de Movimiento',
  },
  {
    id: 'alarma-email',
    titulo: 'Alarma vía Email',
    descripcion: 'Transforme su sistema de vigilancia en un centro de respuesta rápida.',
    imagen: '/images/videovigilancia/alarma-email.jpg',
    servicioCotizar: 'Alarma vía Email',
  },
  {
    id: 'vision-nocturna',
    titulo: 'Visión Nocturna',
    descripcion: 'Garantiza que la oscuridad no sea un obstáculo para la seguridad de sus espacios.',
    imagen: '/images/videovigilancia/vision-nocturna.jpg',
    servicioCotizar: 'Visión Nocturna',
  },
  {
    id: 'visualizacion-dispositivos',
    titulo: 'Visualización desde tu Computadora o Celular',
    descripcion: 'Manténgase conectado con lo que más le importa a través de sus dispositivos personales.',
    imagen: '/images/videovigilancia/visualizacion-dispositivos.jpg',
    servicioCotizar: 'Visualización desde tu Computadora o Celular',
  },
];

export interface SoporteServicio {
  id: string;
  titulo: string;
  descripcion: string;
  items: string[];
  servicioCotizar: string;
}

export const SOPORTE_SERVICIOS: SoporteServicio[] = [
  {
    id: 'sop-configuracion',
    titulo: 'CONFIGURACIÓN E INSTALACIÓN',
    descripcion: 'Nos encargamos de dejar todos sus equipos y sistemas correctamente configurados y listos para su uso inmediato, garantizando un funcionamiento óptimo desde el primer día.',
    items: [
      'Configuración y sincronización de correos electrónicos corporativos.',
      'Instalación y configuración de impresoras y optimización de la red Wi-Fi en oficinas.',
      'Preparación e instalación de equipos para nuevos colaboradores.',
    ],
    servicioCotizar: 'CONFIGURACIÓN E INSTALACIÓN',
  },
  {
    id: 'sop-preventivo',
    titulo: 'MANTENIMIENTO PREVENTIVO',
    descripcion: 'Implementamos acciones preventivas para evitar fallas técnicas y prolongar la vida útil de sus equipos informáticos.',
    items: [
      'Limpieza interna y externa de equipos para prevenir daños por polvo y sobrecalentamiento.',
      'Actualización de sistema operativo y software antivirus.',
      'Verificación y supervisión de copias de seguridad (backups).',
    ],
    servicioCotizar: 'MANTENIMIENTO PREVENTIVO',
  },
  {
    id: 'sop-asesoria',
    titulo: 'ASESORÍA Y CAPACITACIÓN',
    descripcion: 'Brindamos orientación especializada para que su equipo aproveche al máximo las herramientas tecnológicas disponibles.',
    items: [
      'Capacitación en el uso de programas y sistemas informáticos.',
      'Asesoramiento en la compra de equipos según necesidades y presupuesto.',
      'Formación personalizada para optimizar procesos tecnológicos.',
    ],
    servicioCotizar: 'ASESORÍA Y CAPACITACIÓN',
  },
];
