import type { NextRequest } from 'next/server';
import { borrarCookieSesion, jsonSinCache, leerMutacion } from '@/lib/panel/bff';
import { operacionConCsrf } from '@/lib/panel/operaciones-auth';

/**
 * Cierre de sesión. La cookie del navegador se borra siempre, aunque Laravel no esté disponible: el usuario
 * nunca queda con una sesión que cree cerrada. Si Laravel no respondió, la sesión expira por inactividad.
 */
export async function POST(request: NextRequest) {
  const cuerpo = await leerMutacion(request);
  if (cuerpo instanceof Response) return cuerpo;

  const resultado = await operacionConCsrf(request, 'auth/logout', {});
  const estado = resultado.status === 401 ? 200 : resultado.status;

  return borrarCookieSesion(jsonSinCache({ message: 'Sesión cerrada.' }, estado < 500 ? 200 : estado));
}
