import { NextResponse, type NextRequest } from 'next/server';
import { connection } from 'next/server';
import { crearLimitador, errorJson, leerJsonAcotado, mismoOrigen, visitanteDe } from '@/lib/bff-peticion';
import { enviarReclamacion, filtrarCampos, MAX_CUERPO_BYTES, obtenerFormulario } from '@/lib/horus-reclamaciones';

/**
 * BFF del Libro de Reclamaciones. El navegador solo habla con este endpoint del mismo origen; aquí se comprueban
 * origen, tamaño y forma, se reduce el cuerpo a una lista cerrada de campos y se llama a Laravel con el token de
 * servicio y un identificador opaco del visitante (HMAC calculado en el servidor, nunca enviado por el navegador).
 * Laravel valida, numera, registra, audita y aplica los límites de envío. Es solo de envío: no hay lectura de
 * reclamaciones. Los errores internos no se transmiten.
 */

const limitarFormulario = crearLimitador(600_000, 30);
const limitarEnvio = crearLimitador(600_000, 20);

const NO_DISPONIBLE = ['libro_no_disponible', 'El libro de reclamaciones no está disponible en este momento.'] as const;

/** Credenciales de un solo uso del formulario (marca firmada + token de envío) y estado del libro. */
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
  if (espera !== null) return errorJson(429, 'demasiadas_solicitudes', 'Se alcanzó el límite de envíos. Inténtalo más tarde.', undefined, { 'Retry-After': String(espera) });

  const resultado = await enviarReclamacion(campos, visitante);
  if (resultado.ok) return NextResponse.json(resultado.data, { status: resultado.data.repetida ? 200 : 201, headers: { 'Cache-Control': 'no-store' } });

  switch (resultado.estado) {
    case 422:
      return errorJson(422, 'validacion', 'Revisa los campos marcados.', { errors: resultado.errores });
    case 429:
      return errorJson(429, 'demasiadas_solicitudes', 'Se alcanzó el límite de envíos. Inténtalo más tarde.', undefined, { 'Retry-After': String(resultado.reintentarEn ?? 600) });
    default:
      // 404 = libro apagado en Laravel; cualquier otro fallo: mismo mensaje genérico.
      return errorJson(503, ...NO_DISPONIBLE);
  }
}
