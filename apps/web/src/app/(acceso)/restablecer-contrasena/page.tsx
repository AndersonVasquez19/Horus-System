import type { Metadata } from 'next';
import { EncabezadoAcceso } from '../primitivas-acceso';
import { FormularioRestablecer } from './formulario-restablecer';

export const metadata: Metadata = {
  title: 'Crear contraseña nueva',
  // El enlace del correo lleva el token en el fragmento; además, no enviar Referer desde esta página.
  referrer: 'no-referrer',
};

export default function RestablecerContrasenaPage() {
  return (
    <>
      <EncabezadoAcceso
        titulo="Crea tu contraseña nueva"
        descripcion="Al guardarla se cerrarán todas tus sesiones abiertas por seguridad."
      />
      <FormularioRestablecer />
    </>
  );
}
