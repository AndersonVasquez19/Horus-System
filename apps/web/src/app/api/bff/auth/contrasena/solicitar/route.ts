import type { NextRequest } from 'next/server';
import { campos, leerMutacion } from '@/lib/panel/bff';
import { operacionConCsrf } from '@/lib/panel/operaciones-auth';

/** Solicitud de enlace de recuperación. Laravel responde igual exista o no la cuenta. */
export async function POST(request: NextRequest) {
  const cuerpo = await leerMutacion(request);
  if (cuerpo instanceof Response) return cuerpo;

  return operacionConCsrf(request, 'auth/contrasena/solicitar', campos(cuerpo, ['email']));
}
