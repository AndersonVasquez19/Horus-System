/** Nombres en español de los temas del asistente (lista cerrada en `App\Support\IntencionesAsistente` de Laravel). */
const ETIQUETAS: Record<string, string> = {
  training_info: 'Cursos y capacitaciones',
  followup_attribute: 'Horario, duración y modalidad',
  course_recommendation: 'Recomendación de cursos',
  enrollment_request: 'Quiere matricularse',
  advisory_info: 'Asesoramiento',
  agreement_info: 'Convenios',
  company_info: 'Sobre Horus Group',
  contact_info: 'Contacto y ubicación',
  human_handoff: 'Pidió un asesor',
  faq: 'Preguntas frecuentes',
  policy: 'Políticas y reclamos',
  quote_request: 'Cotización',
  service_info: 'Servicios',
  service_recommendation: 'Recomendación de servicios',
  unavailable_info: 'Dato no publicado',
  unverified_info: 'Dato sin confirmar',
  out_of_scope: 'Fuera de tema',
  clarification: 'Pidió precisar la consulta',
  capabilities: 'Sobre el asistente',
  cortesia: 'Saludos y cortesía',
  greeting: 'Saludo',
  thanks: 'Agradecimiento',
  farewell: 'Despedida',
  acknowledgement: 'Cortesía',
  sensitive_request: 'Pidió datos reservados',
  prompt_injection: 'Intento de manipulación',
  sin_dato: 'Sin dato publicado (todas)',
  otro: 'Otros',
};

export function etiquetaTema(nombre: string): string {
  return ETIQUETAS[nombre] ?? nombre.replaceAll('_', ' ');
}
