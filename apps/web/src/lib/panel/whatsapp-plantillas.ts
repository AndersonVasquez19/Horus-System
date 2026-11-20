
/**
 * Plantillas de mensajes para WhatsApp manual (`wa.me` y portapapeles) en el panel académico.
 * No usa WhatsApp Cloud API ni envía mensajes automáticamente; solo prepara el texto para que secretaría lo revise y envíe.
 */

export function normalizarTelefonoWa(telefono: string | null | undefined): string | null {
  if (!telefono) return null;
  const digitos = telefono.replace(/\D+/g, '');
  if (digitos.length === 9 && digitos.startsWith('9')) {
    return `51${digitos}`;
  }
  if (digitos.length >= 10 && digitos.length <= 15) {
    return digitos;
  }
  return null;
}

export function enlaceWaMe(telefono: string | null | undefined, texto?: string): string | null {
  const normalizado = normalizarTelefonoWa(telefono);
  if (!normalizado) return null;
  const base = `https://wa.me/${normalizado}`;
  if (!texto) return base;
  return `${base}?text=${encodeURIComponent(texto)}`;
}

export async function copiarAlPortapapeles(texto: string): Promise<boolean> {
  if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(texto);
      return true;
    } catch {
      // Fallback abajo si el navegador bloquea el portapapeles.
    }
  }
  if (typeof document === 'undefined') return false;
  try {
    const area = document.createElement('textarea');
    area.value = texto;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(area);
    return ok;
  } catch {
    return false;
  }
}

export function mensajeBienvenidaMatricula(datos: {
  estudiante: string;
  curso: string;
  edicion?: string | null;
  horario?: string | null;
  enlaceGrupo?: string | null;
}): string {
  const lineas = [
    `¡Hola ${datos.estudiante}! 👋`,
    `Tu matrícula en *${datos.curso}*${datos.edicion ? ` (${datos.edicion})` : ''} ha sido registrada con éxito en *Horus Group*.`,
  ];
  if (datos.horario) {
    lineas.push(`🕒 Horario: ${datos.horario}`);
  }
  if (datos.enlaceGrupo) {
    lineas.push(`💬 Únete al grupo de WhatsApp del curso aquí:\n${datos.enlaceGrupo}`);
  }
  lineas.push('Cualquier consulta, estamos atentos por este medio.');
  return lineas.join('\n\n');
}

export function mensajeAvisoSesion(datos: {
  curso: string;
  edicion?: string | null;
  numero?: number;
  fecha: string;
  horaInicio: string;
  horaFin: string;
  tema?: string | null;
  modalidad?: string | null;
}): string {
  const partes = [
    `📢 *Recordatorio de clase — ${datos.curso}*${datos.edicion ? ` (${datos.edicion})` : ''}`,
    `📅 Fecha: *${datos.fecha}*`,
    `⏰ Hora: *${datos.horaInicio} a ${datos.horaFin}*`,
  ];
  if (datos.numero) {
    partes.push(`📘 Sesión #${datos.numero}${datos.tema ? `: ${datos.tema}` : ''}`);
  } else if (datos.tema) {
    partes.push(`📘 Tema: ${datos.tema}`);
  }
  if (datos.modalidad) {
    partes.push(`📍 Modalidad: ${datos.modalidad}`);
  }
  partes.push('¡Los esperamos puntuales!');
  return partes.join('\n');
}

export function mensajeRecordatorioCuota(datos: {
  estudiante: string;
  curso: string;
  saldo: string;
  vence?: string | null;
}): string {
  const lineas = [
    `Hola ${datos.estudiante}, te saludamos de *Horus Group* 👋.`,
    `Te recordamos que tienes un saldo pendiente de *S/ ${datos.saldo}* correspondiente al curso *${datos.curso}*${datos.vence ? ` (vencimiento: ${datos.vence})` : ''}.`,
    'Si ya realizaste el pago, por favor envíanos tu captura por este medio para validarlo. ¡Gracias!',
  ];
  return lineas.join('\n\n');
}

export function mensajeFichaCurso(datos: {
  nombre: string;
  modalidad?: string | null;
  duracionHoras?: number | null;
  precio?: string | null;
  descripcion?: string | null;
  urlPublica?: string | null;
}): string {
  const lineas = [`🎓 *${datos.nombre}*`];
  if (datos.modalidad) lineas.push(`📍 Modalidad: ${datos.modalidad}`);
  if (datos.duracionHoras) lineas.push(`⏱️ Duración: ${datos.duracionHoras} horas`);
  if (datos.precio) lineas.push(`💳 Inversión: S/ ${datos.precio}`);
  if (datos.descripcion) lineas.push(`\n${datos.descripcion}`);
  if (datos.urlPublica) lineas.push(`\n🔗 Más información: ${datos.urlPublica}`);
  return lineas.join('\n');
}
