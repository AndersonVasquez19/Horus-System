import { NextResponse, type NextRequest } from 'next/server';
import { connection } from 'next/server';
import { crearLimitador, errorJson, leerJsonAcotado, mismoOrigen, visitanteDe } from '@/lib/bff-peticion';
import { enviarSolicitud, filtrarCampos, MAX_CUERPO_BYTES, obtenerFormulario } from '@/lib/horus-cotizaciones';

/**
 * BFF de la solicitud de cotización. El navegador solo habla con este endpoint del mismo origen; aquí se comprueban
 * origen, tamaño y forma, se reduce el cuerpo a una lista cerrada de campos y se llama a Laravel con el token de
 * servicio y un identificador opaco del visitante (HMAC calculado en el servidor). Laravel valida, vuelve a resolver
 * los servicios, registra en el módulo comercial y aplica los límites. Es una solicitud, no una cotización emitida.
 */

const limitarFormulario = crearLimitador(600_000, 30);
const limitarEnvio = crearLimitador(600_000, 20);

const NO_DISPONIBLE = ['solicitud_no_disponible', 'La solicitud de cotización no está disponible en este momento.'] as const;

/** Servicios que se pueden solicitar y la marca de un solo uso del formulario. */
export async function GET(request: NextRequest) {
  await connection();
  const visitante = visitanteDe(request);
  if (visitante === null) return errorJson(503, ...NO_DISPONIBLE);

  const espera = limitarFormulario(visitante);
  if (espera !== null) return errorJson(429, 'demasiadas_solicitudes', 'Demasiadas solicitudes seguidas.', undefined, { 'Retry-After': String(espera) });

  const resultado = await obtenerFormulario(visitante);
  if (!resultado.ok) return errorJson(503, ...NO_DISPONIBLE);
  return NextResponse.json(resultado.data, { headers: { 'Cache-Control': 'no-store' } });
}

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
  if (espera !== null) return errorJson(429, 'demasiadas_solicitudes', 'Se alcanzó el límite de solicitudes. Inténtalo más tarde.', undefined, { 'Retry-After': String(espera) });

  const resultado = await enviarSolicitud(campos, visitante);
  if (resultado.ok) return NextResponse.json(resultado.data, { status: resultado.data.repetida ? 200 : 201, headers: { 'Cache-Control': 'no-store' } });

  switch (resultado.estado) {
    case 422:
      return errorJson(422, 'validacion', 'Revisa los campos marcados.', { errors: resultado.errores });
    case 429:
      return errorJson(429, 'demasiadas_solicitudes', 'Se alcanzó el límite de solicitudes. Inténtalo más tarde.', undefined, { 'Retry-After': String(resultado.reintentarEn ?? 600) });
    default:
      return errorJson(503, ...NO_DISPONIBLE);
  }
}
