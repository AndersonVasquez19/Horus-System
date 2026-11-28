import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ComprobarSesionActiva } from '../comprobar-sesion-activa';
import { EncabezadoAcceso, EnlaceAcceso } from '../primitivas-acceso';
import { FormularioLogin } from './formulario-login';

export const metadata: Metadata = { title: 'Iniciar sesión' };

export default function LoginPage() {
  return (
    <>
      <Suspense fallback={null}>
        <ComprobarSesionActiva />
      </Suspense>
      <EncabezadoAcceso
        titulo="Inicia sesión"
        descripcion="Ingresa con tu correo registrado y tu contraseña."
      />
      <FormularioLogin />
      <p className="mt-6 text-center text-sm text-panel-muted">
        ¿Aún no tienes cuenta? <EnlaceAcceso href="/registro">Crea una</EnlaceAcceso>
      </p>
    </>
  );
}
