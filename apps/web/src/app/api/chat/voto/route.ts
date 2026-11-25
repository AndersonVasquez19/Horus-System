import { NextResponse, type NextRequest } from 'next/server';
import { connection } from 'next/server';
import { mismoOrigen } from '@/lib/chat-origen';
import { enviarVotoChat, identificadorVisitante, REFERENCIA_RESPUESTA } from '@/lib/horus-chat';

/**
 * BFF del voto 👍/👎. Solo reenvía la referencia firmada que Laravel entregó con la respuesta, la sesión y el voto:
 * ningún texto. El límite de uso lo aplica Laravel (`chat-voto-bff`).
 */
const SESION = /^[A-Za-z0-9_-]{8,64}$/;
const MAX_BYTES = 512;

function responder(estado: number) {
  return new NextResponse(null, { status: estado, headers: { 'Cache-Control': 'no-store' } });
}

export async function POST(request: NextRequest) {
  await connection();

  if (!mismoOrigen(request)) return responder(403);
  if (!(request.headers.get('content-type') ?? '').toLowerCase().startsWith('application/json')) return responder(415);

  let cuerpo: Record<string, unknown>;
  try {
    const bruto = await request.text();
    if (Buffer.byteLength(bruto) > MAX_BYTES) return responder(413);
    const datos: unknown = JSON.parse(bruto);
    cuerpo = typeof datos === 'object' && datos !== null ? (datos as Record<string, unknown>) : {};
  } catch {
    return responder(400);
  }
  const { session_id: sesion, respuesta_id: referencia, voto } = cuerpo;
  if (typeof sesion !== 'string' || !SESION.test(sesion)) return responder(422);
  if (typeof referencia !== 'string' || !REFERENCIA_RESPUESTA.test(referencia)) return responder(422);
  if (voto !== 'up' && voto !== 'down') return responder(422);

  const ip = (request.headers.get('x-forwarded-for')?.split(',')[0] ?? request.headers.get('x-real-ip') ?? 'desconocida').trim().slice(0, 64);
  const visitante = identificadorVisitante(ip);
  if (visitante === null) return responder(503);

  return responder((await enviarVotoChat(sesion, referencia, voto, visitante)) ? 204 : 503);
}
