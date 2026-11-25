import { NextResponse, type NextRequest } from 'next/server';
import { connection } from 'next/server';
import { mismoOrigen } from '@/lib/chat-origen';
import { enviarMensajeChat, identificadorVisitante, MAX_CUERPO_BYTES, MAX_MENSAJE } from '@/lib/horus-chat';

/**
 * BFF del chat: el navegador solo habla con este endpoint del mismo origen. Aquí se validan origen, tamaño y forma
 * de la petición y se aplica un límite por visitante; después se llama a Laravel con el token de servicio (que nunca
 * sale del servidor). Los errores internos no se transmiten: el navegador solo recibe códigos genéricos.
 */

const SESION = /^[A-Za-z0-9_-]{8,64}$/;
const VENTANA_MS = 60_000;
const MAX_POR_VENTANA = 20;
const MAX_VISITANTES = 5000;

/** Límite en memoria por proceso (mejor esfuerzo); el autoritativo es el de Laravel (`chat-bff`). */
const visitas = new Map<string, number[]>();

function limitado(clave: string): number | null {
  const ahora = Date.now();
  if (visitas.size > MAX_VISITANTES) {
    for (const [k, marcas] of visitas) if (marcas.every((m) => ahora - m > VENTANA_MS)) visitas.delete(k);
    if (visitas.size > MAX_VISITANTES) visitas.clear();
  }
  const recientes = (visitas.get(clave) ?? []).filter((m) => ahora - m < VENTANA_MS);
  if (recientes.length >= MAX_POR_VENTANA) {
    visitas.set(clave, recientes);
    return Math.max(1, Math.ceil((VENTANA_MS - (ahora - recientes[0])) / 1000));
  }
  recientes.push(ahora);
  visitas.set(clave, recientes);
  return null;
}

function error(estado: number, code: string, message: string, extra?: Record<string, string>) {
  return NextResponse.json({ error: { code, message } }, { status: estado, headers: { 'Cache-Control': 'no-store', ...extra } });
}

export async function POST(request: NextRequest) {
  await connection();

  if (!mismoOrigen(request)) return error(403, 'origen_no_permitido', 'Solicitud no permitida.');
  if (!(request.headers.get('content-type') ?? '').toLowerCase().startsWith('application/json')) {
    return error(415, 'formato_no_soportado', 'Formato no soportado.');
  }
  const declarado = Number(request.headers.get('content-length') ?? '0');
  if (Number.isFinite(declarado) && declarado > MAX_CUERPO_BYTES) return error(413, 'cuerpo_demasiado_grande', 'La solicitud es demasiado grande.');

  let bruto: string;
  try {
    bruto = await request.text();
  } catch {
    return error(400, 'solicitud_invalida', 'Solicitud no válida.');
  }
  if (Buffer.byteLength(bruto) > MAX_CUERPO_BYTES) return error(413, 'cuerpo_demasiado_grande', 'La solicitud es demasiado grande.');

  let datos: unknown;
  try {
    datos = JSON.parse(bruto);
  } catch {
    return error(400, 'solicitud_invalida', 'Solicitud no válida.');
  }
  const cuerpo = typeof datos === 'object' && datos !== null && !Array.isArray(datos) ? (datos as Record<string, unknown>) : {};
  const mensaje = typeof cuerpo.mensaje === 'string' ? cuerpo.mensaje.trim() : '';
  const sessionId = cuerpo.session_id;
  if (mensaje === '') return error(422, 'mensaje_invalido', 'Escribe un mensaje para el asistente.');
  if (mensaje.length > MAX_MENSAJE) return error(422, 'mensaje_invalido', `El mensaje no debe superar los ${MAX_MENSAJE} caracteres.`);
  if (typeof sessionId !== 'string' || !SESION.test(sessionId)) return error(422, 'sesion_invalida', 'La sesión del chat no es válida.');

  const ip = (request.headers.get('x-forwarded-for')?.split(',')[0] ?? request.headers.get('x-real-ip') ?? 'desconocida').trim().slice(0, 64);
  const visitante = identificadorVisitante(ip);
  if (visitante === null) return error(503, 'chat_no_disponible', 'El asistente no está disponible.');

  const espera = limitado(visitante);
  if (espera !== null) return error(429, 'demasiadas_solicitudes', 'Demasiadas consultas seguidas.', { 'Retry-After': String(espera) });

  const resultado = await enviarMensajeChat(mensaje, sessionId, visitante);
  if (!resultado.ok) {
    return resultado.estado === 429
      ? error(429, 'demasiadas_solicitudes', 'Demasiadas consultas seguidas.', { 'Retry-After': String(resultado.reintentarEn ?? 30) })
      : error(503, 'chat_no_disponible', 'El asistente no está disponible.');
  }
  return NextResponse.json(resultado.data, { headers: { 'Cache-Control': 'no-store' } });
}
