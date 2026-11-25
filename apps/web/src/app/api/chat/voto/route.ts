import { connection, NextResponse, type NextRequest } from 'next/server';
import { leerPeticionMetrica, responderMetrica } from '@/lib/chat-metrica';
import { enviarMetricaChat } from '@/lib/horus-chat';

/** BFF del voto 👍/👎 de una respuesta. */
export async function POST(request: NextRequest) {
  await connection();

  const peticion = await leerPeticionMetrica(request);
  if (peticion instanceof NextResponse) return peticion;
  const { voto } = peticion.cuerpo;
  if (voto !== 'up' && voto !== 'down') return responderMetrica(422);

  const guardado = await enviarMetricaChat('voto', { session_id: peticion.sesion, respuesta_id: peticion.referencia, voto }, peticion.visitante);
  return responderMetrica(guardado ? 204 : 503);
}
