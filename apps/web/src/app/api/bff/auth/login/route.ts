import type { NextRequest } from 'next/server';
import { campos, leerMutacion } from '@/lib/panel/bff';
import { operacionConCsrf } from '@/lib/panel/operaciones-auth';

/** Inicio de sesión del panel. Laravel valida credenciales, límites y permiso de entrada. */
export async function POST(request: NextRequest) {
  const cuerpo = await leerMutacion(request);
  if (cuerpo instanceof Response) return cuerpo;

  return operacionConCsrf(request, 'auth/login', campos(cuerpo, ['email', 'password']));
}
