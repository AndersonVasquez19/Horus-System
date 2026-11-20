import type { NextRequest } from 'next/server';
import { campos, leerMutacion } from '@/lib/panel/bff';
import { operacionConCsrf } from '@/lib/panel/operaciones-auth';

/** Restablecimiento con el token del correo. Laravel valida token, política de contraseña y revoca sesiones. */
export async function POST(request: NextRequest) {
  const cuerpo = await leerMutacion(request);
  if (cuerpo instanceof Response) return cuerpo;

  return operacionConCsrf(
    request,
    'auth/contrasena/restablecer',
    campos(cuerpo, ['token', 'email', 'password', 'password_confirmation']),
  );
}
