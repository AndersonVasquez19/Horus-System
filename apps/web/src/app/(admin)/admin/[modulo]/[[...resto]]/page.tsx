import { notFound, redirect } from 'next/navigation';
import { Suspense } from 'react';
import { urlLogin } from '@/lib/panel/redireccion';
import { obtenerSesion } from '@/lib/panel/sesion';

export const metadata = { title: 'Redirigiendo…' };

// Ver la nota de cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

// Módulos compartidos por los dos paneles. Laravel (el Blade retirado y las notificaciones guardadas) enlaza a
// `/admin/<módulo>` sin saber qué panel usa la persona: esta entrada la lleva al suyo conservando el resto de la ruta.
const MODULOS_COMPARTIDOS = new Set(['auditoria', 'reclamaciones', 'usuarios', 'roles', 'notificaciones', 'perfil']);
const SEGMENTO = /^[A-Za-z0-9-]{1,64}$/;

export default function ModuloCompartidoPage({ params }: { params: Promise<{ modulo: string; resto?: string[] }> }) {
  return (
    <Suspense fallback={null}>
      <Redireccion params={params} />
    </Suspense>
  );
}

async function Redireccion({ params }: { params: Promise<{ modulo: string; resto?: string[] }> }) {
  const { modulo, resto = [] } = await params;
  if (!MODULOS_COMPARTIDOS.has(modulo) || !resto.every((segmento) => SEGMENTO.test(segmento))) notFound();

  const destinoRelativo = [modulo, ...resto].join('/');
  const resultado = await obtenerSesion();
  if (resultado.estado === 'anonimo') redirect(urlLogin(`/admin/${destinoRelativo}`));
  if (resultado.estado !== 'autenticado') notFound();

  const { academico, servicios } = resultado.sesion.paneles;
  if (academico) redirect(`/admin/academico/${destinoRelativo}`);
  if (servicios) redirect(`/admin/servicios/${destinoRelativo}`);
  return notFound();
}
