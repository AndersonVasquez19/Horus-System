import type { Metadata } from 'next';
import { Lock, Shield, Eye, FileText } from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

export const metadata: Metadata = {
  title: 'Políticas de Privacidad',
  description: 'Política de protección de datos personales de Horus Group SRL conforme a la Ley N° 29733.',
};

export default function PoliticasPrivacidadPage() {
  return (
    <div className="pt-28 pb-20">
      <section className="relative overflow-hidden py-16 sm:py-20 bg-gradient-to-b from-[#0A1929] to-[#0A2540]">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto">
          <span className="text-xs font-bold tracking-widest text-gold uppercase">Protección de Datos</span>
          <h1 className="mt-2 text-3xl sm:text-5xl font-extrabold text-white tracking-tight leading-tight">
            Políticas de <span className="text-gold">Privacidad</span>
          </h1>
          <p className="mt-4 text-sm sm:text-base text-stone-300 leading-relaxed">
            En cumplimiento de la Ley de Protección de Datos Personales del Perú (Ley N° 29733) y su Reglamento.
          </p>
        </div>
      </section>

      <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-8">
        <div className="rounded-3xl border border-white/10 bg-[#0A1929]/70 backdrop-blur-xl p-8 sm:p-10 shadow-xl space-y-6 text-sm leading-relaxed text-stone-300">
          <div>
            <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
              <Shield className="h-5 w-5 text-gold" />
              <span>1. Identidad del Titular del Banco de Datos</span>
            </h2>
            <p>
              El responsable del tratamiento de los datos personales recopilados a través de este portal web es <strong>Horus Group SRL</strong>, con RUC N° {CONTACT_INFO.ruc}, con domicilio legal en {CONTACT_INFO.address}.
            </p>
          </div>

          <div>
            <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
              <Eye className="h-5 w-5 text-gold" />
              <span>2. Finalidad del Tratamiento de Datos</span>
            </h2>
            <p>
              Los datos personales solicitados en nuestros formularios (solicitud de matrícula, libro de reclamaciones, contactos y cotizaciones) son tratados con la exclusiva finalidad de:
            </p>
            <ul className="list-disc list-inside space-y-1.5 pl-2 mt-2">
              <li>Gestionar la matrícula y emisión de certificados con valor curricular.</li>
              <li>Atender consultas, pedidos de cotización y requerimientos técnicos.</li>
              <li>Dar cumplimiento a obligaciones legales y normativas (como la tramitación de quejas en el Libro de Reclamaciones ante INDECOPI).</li>
              <li>Enviar información relevante sobre nuevos cursos y actualizaciones formativas previo consentimiento.</li>
            </ul>
          </div>

          <div>
            <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
              <Lock className="h-5 w-5 text-gold" />
              <span>3. Medidas de Seguridad y Confidencialidad</span>
            </h2>
            <p>
              Horus Group SRL adopta las medidas técnicas, organizativas y legales necesarias para garantizar la seguridad de los datos personales, evitando su alteración, pérdida, tratamiento o acceso no autorizado. No comercializamos tus datos personales ni los cedemos a terceros para sus propios fines.
            </p>
            <p className="mt-2">
              Para enviarte correos, como la copia de tu hoja de reclamación y su respuesta, usamos un proveedor de envío de correo que trata esos mensajes solo para entregarlos por encargo nuestro y que puede hacerlo desde servidores ubicados fuera del Perú. Las hojas de reclamación se reciben además en el buzón de correo de la empresa.
            </p>
          </div>

          <div>
            <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
              <FileText className="h-5 w-5 text-gold" />
              <span>4. Ejercicio de Derechos ARCO</span>
            </h2>
            <p>
              Como titular de tus datos, puedes ejercer tus derechos de Acceso, Rectificación, Cancelación y Oposición (ARCO) dirigiendo una solicitud formal al correo <a href={`mailto:${CONTACT_INFO.email}`} className="text-gold underline">{CONTACT_INFO.email}</a> adjuntando copia de tu DNI.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
