import { NextResponse, type NextRequest } from 'next/server';
import { connection } from 'next/server';
import { crearLimitador, errorJson, leerJsonAcotado, mismoOrigen, visitanteDe } from '@/lib/bff-peticion';
import { enviarMensaje, filtrarCampos, MAX_CUERPO_BYTES } from '@/lib/horus-contacto';

/**
 * BFF del formulario «Contáctanos». El navegador solo habla con este endpoint del mismo origen; aquí se comprueban origen,
 * tamaño y forma, se reduce el cuerpo a una lista cerrada de campos y se llama a Laravel con el token de servicio y un
 * identificador opaco del visitante. Laravel valida, guarda el mensaje y aplica sus propios límites.
 */

const limitarEnvio = crearLimitador(600_000, 10);

const NO_DISPONIBLE = ['contacto_no_disponible', 'No pudimos enviar tu mensaje en este momento. Inténtalo más tarde o escríbenos por WhatsApp.'] as const;

export async function POST(request: NextRequest) {
  await connection();

  if (!mismoOrigen(request)) return errorJson(403, 'origen_no_permitido', 'Solicitud no permitida.');
  const cuerpo = await leerJsonAcotado(request, MAX_CUERPO_BYTES);
  if (cuerpo instanceof NextResponse) return cuerpo;

  const campos = filtrarCampos(cuerpo);
  if (campos === null) return errorJson(422, 'solicitud_invalida', 'Revisa los datos del formulario e inténtalo de nuevo.');

  const visitante = visitanteDe(request);
  if (visitante === null) return errorJson(503, ...NO_DISPONIBLE);
  const espera = limitarEnvio(visitante);
  if (espera !== null) return errorJson(429, 'demasiadas_solicitudes', 'Enviaste varios mensajes seguidos. Inténtalo más tarde.', undefined, { 'Retry-After': String(espera) });

  const resultado = await enviarMensaje(campos, visitante);
  if (resultado.ok) return NextResponse.json({ recibido: true }, { status: 201, headers: { 'Cache-Control': 'no-store' } });

  switch (resultado.estado) {
    case 422:
      return errorJson(422, 'validacion', 'Revisa los campos marcados.', { errors: resultado.errores });
    case 429:
      return errorJson(429, 'demasiadas_solicitudes', 'Enviaste varios mensajes seguidos. Inténtalo más tarde.', undefined, { 'Retry-After': String(resultado.reintentarEn ?? 600) });
    default:
      return errorJson(503, ...NO_DISPONIBLE);
  }
}
