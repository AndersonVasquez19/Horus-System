import { NextResponse, type NextRequest } from 'next/server';
import { mismoOrigen } from '@/lib/chat-origen';
import { identificadorVisitante, REFERENCIA_RESPUESTA } from '@/lib/horus-chat';

/**
 * Entrada común de las métricas del chat (voto y clic en WhatsApp). Solo se reenvían la sesión, la referencia firmada
 * que Laravel entregó con la respuesta y valores cerrados: ningún texto. El límite de uso lo aplica Laravel.
 */
const SESION = /^[A-Za-z0-9_-]{8,64}$/;
const MAX_BYTES = 512;

export function responderMetrica(estado: number) {
  return new NextResponse(null, { status: estado, headers: { 'Cache-Control': 'no-store' } });
}

export interface PeticionMetrica {
  sesion: string;
  referencia: string;
  visitante: string;
  cuerpo: Record<string, unknown>;
}

/** Valida origen, formato y referencia. Devuelve la petición lista o la respuesta de rechazo. */
export async function leerPeticionMetrica(request: NextRequest): Promise<PeticionMetrica | NextResponse> {
  if (!mismoOrigen(request)) return responderMetrica(403);
  if (!(request.headers.get('content-type') ?? '').toLowerCase().startsWith('application/json')) return responderMetrica(415);

  let cuerpo: Record<string, unknown>;
  try {
    const bruto = await request.text();
    if (Buffer.byteLength(bruto) > MAX_BYTES) return responderMetrica(413);
    const datos: unknown = JSON.parse(bruto);
    cuerpo = typeof datos === 'object' && datos !== null ? (datos as Record<string, unknown>) : {};
  } catch {
    return responderMetrica(400);
  }
  const { session_id: sesion, respuesta_id: referencia } = cuerpo;
  if (typeof sesion !== 'string' || !SESION.test(sesion)) return responderMetrica(422);
  if (typeof referencia !== 'string' || !REFERENCIA_RESPUESTA.test(referencia)) return responderMetrica(422);

  const ip = (request.headers.get('x-forwarded-for')?.split(',')[0] ?? request.headers.get('x-real-ip') ?? 'desconocida').trim().slice(0, 64);
  const visitante = identificadorVisitante(ip);
  if (visitante === null) return responderMetrica(503);

  return { sesion, referencia, visitante, cuerpo };
}
