import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ComprobarSesionActiva } from '../comprobar-sesion-activa';
import { EncabezadoAcceso } from '../primitivas-acceso';
import { FormularioRegistro } from './formulario-registro';

export const metadata: Metadata = { title: 'Crear cuenta' };

export default function RegistroPage() {
  return (
    <>
      <Suspense fallback={null}>
        <ComprobarSesionActiva />
      </Suspense>
      <EncabezadoAcceso
        titulo="Crea tu cuenta"
        descripcion="Tu cuenta inicia sin acceso al panel hasta que un administrador la habilite."
      />
      <FormularioRegistro />
    </>
  );
}
