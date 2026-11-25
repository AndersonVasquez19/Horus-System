import { connection, NextResponse, type NextRequest } from 'next/server';
import { leerPeticionMetrica, responderMetrica } from '@/lib/chat-metrica';
import { enviarMetricaChat } from '@/lib/horus-chat';

/** BFF del clic en un botón de WhatsApp del chat: solo anota que se pulsó, sin número ni mensaje. */
export async function POST(request: NextRequest) {
  await connection();

  const peticion = await leerPeticionMetrica(request);
  if (peticion instanceof NextResponse) return peticion;

  const guardado = await enviarMetricaChat('contacto', { session_id: peticion.sesion, respuesta_id: peticion.referencia }, peticion.visitante);
  return responderMetrica(guardado ? 204 : 503);
}
