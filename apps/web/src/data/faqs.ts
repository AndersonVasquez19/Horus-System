export interface FaqItem {
  id: string;
  category: 'certificados' | 'cursos' | 'compras' | 'atencion';
  categoryLabel: string;
  number: string;
  question: string;
  answer: string;
  keywords: string;
}

export const FAQS: FaqItem[] = [
  {
    id: 'faq-1',
    category: 'certificados',
    categoryLabel: 'Certificados y Diplomas',
    number: '01',
    question: '¿Qué pasa si perdí mi certificado y/o diploma y quiero solicitar uno nuevo?',
    answer:
      'Puedes solicitar una reimpresión de tu certificado o diploma contactándonos directamente por correo electrónico o WhatsApp. Es posible que apliquen costos administrativos según el tipo de documento.',
    keywords: 'perdi certificado diploma solicitar duplicado reimpresion perdida',
  },
  {
    id: 'faq-2',
    category: 'cursos',
    categoryLabel: 'Cursos y Evaluaciones',
    number: '02',
    question: '¿Cuáles son las fechas para rendir las evaluaciones de cursos asincrónicos?',
    answer:
      'Las evaluaciones de cursos grabados se rinden en fechas fijas que comunicamos mensualmente por correo y redes sociales. Contáctanos para conocer el próximo calendario disponible.',
    keywords: 'evaluacion examen fecha asincronico grabado prueba',
  },
  {
    id: 'faq-3',
    category: 'cursos',
    categoryLabel: 'Cursos y Evaluaciones',
    number: '03',
    question: 'Si el curso es presencial o semipresencial y no fui a la parte práctica, ¿qué hago?',
    answer:
      'Debes comunicarte con nosotros antes de la siguiente sesión práctica para coordinar un proceso de recuperación o reprogramación, sujeto a disponibilidad de talleres.',
    keywords: 'practica inasistencia falta presencial semipresencial recuperacion reprogramar',
  },
  {
    id: 'faq-4',
    category: 'compras',
    categoryLabel: 'Compras y Pagos',
    number: '04',
    question: 'Compré un producto en la web y todavía no me llega, ¿qué debo hacer?',
    answer:
      'Escríbenos con tu número de pedido o comprobante al correo o WhatsApp. Verificaremos el estado de tu envío con logística y te daremos una solución en menos de 24 horas hábiles.',
    keywords: 'producto compra envio demora demora pedido seguimiento',
  },
  {
    id: 'faq-5',
    category: 'compras',
    categoryLabel: 'Compras y Pagos',
    number: '05',
    question: '¿Emiten comprobantes de pago (boletas y facturas)?',
    answer:
      'Sí, emitimos boletas y facturas electrónicas con valor tributario según normativa SUNAT. Al momento de tu inscripción o compra puedes indicar si necesitas factura con el RUC y razón social de tu empresa.',
    keywords: 'factura boleta comprobante sunat ruc impuestos',
  },
  {
    id: 'faq-6',
    category: 'compras',
    categoryLabel: 'Compras y Pagos',
    number: '06',
    question: '¿Qué medios de pago aceptan?',
    answer:
      'Aceptamos transferencias bancarias (BCP, BBVA, Banco de la Nación), billeteras digitales (Yape y Plin), tarjetas de crédito/débito y pago en efectivo en nuestra sede central de Cajamarca.',
    keywords: 'medio de pago yape plin transferencia tarjeta efectivo banco',
  },
  {
    id: 'faq-7',
    category: 'atencion',
    categoryLabel: 'Atención Personalizada',
    number: '07',
    question: '¿Deseas una atención más personalizada o asesoría técnica para tu empresa?',
    answer:
      'Contáctanos directamente por WhatsApp o agenda una reunión virtual o presencial con uno de nuestros especialistas técnicos y coordinadores académicos. Estamos disponibles de lunes a viernes de 8:00 AM a 7:00 PM y sábados hasta la 1:00 PM.',
    keywords: 'atencion personalizada asesor reunion virtual soporte empresa',
  },
];
