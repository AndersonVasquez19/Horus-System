import type { Metadata } from 'next';
import { EncabezadoAcceso } from '../primitivas-acceso';
import { FormularioRecuperar } from './formulario-recuperar';

export const metadata: Metadata = { title: 'Recuperar contraseña' };

export default function RecuperarContrasenaPage() {
  return (
    <>
      <EncabezadoAcceso
        titulo="Restablece tu contraseña"
        descripcion="Escribe tu correo y te enviaremos un enlace válido por 60 minutos."
      />
      <FormularioRecuperar />
    </>
  );
}
