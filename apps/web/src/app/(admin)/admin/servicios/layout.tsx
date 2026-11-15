import type { ReactNode } from 'react';
import { PanelShell } from '@/components/shared/panel-shell';
import type { ModeloNavegacion } from '@/components/shared/panel-tipos';

// Solo módulos implementados. Cada entrada declara el permiso de Laravel que necesita (ayuda visual).
// Cotizaciones exige además el rol admin en Laravel; quien no lo tenga verá el aviso de permiso.
const NAVEGACION: ModeloNavegacion = [
  {
    id: 'general',
    elementos: [{ id: 'inicio', etiqueta: 'Inicio', href: '/admin/servicios', icono: 'inicio', coincidencia: 'exacta' }],
  },
  {
    id: 'comercial',
    etiqueta: 'Gestión comercial',
    elementos: [
      { id: 'servicios', etiqueta: 'Servicios', href: '/admin/servicios/catalogo', icono: 'servicios', permisosRequeridos: ['servicios.ver'] },
      { id: 'cotizaciones', etiqueta: 'Cotizaciones', href: '/admin/servicios/cotizaciones', icono: 'documentos', permisosRequeridos: ['cotizaciones.ver'] },
      { id: 'prospectos', etiqueta: 'Prospectos', href: '/admin/servicios/prospectos', icono: 'registros', permisosRequeridos: ['prospectos.ver'] },
      { id: 'oportunidades', etiqueta: 'Pipeline', href: '/admin/servicios/oportunidades', icono: 'documentos', permisosRequeridos: ['pipeline.ver'] },
      { id: 'proyectos', etiqueta: 'Proyectos', href: '/admin/servicios/proyectos', icono: 'cursos', permisosRequeridos: ['proyectos.ver'] },
      { id: 'soporte', etiqueta: 'Soporte técnico', href: '/admin/servicios/soporte', icono: 'avisos', permisosRequeridos: ['soporte.ver'] },
      { id: 'clientes', etiqueta: 'Clientes', href: '/admin/servicios/clientes', icono: 'personas', permisosRequeridos: ['clientes.ver'] },
    ],
  },
  {
    id: 'sistema',
    etiqueta: 'Sistema',
    elementos: [
      { id: 'reclamaciones', etiqueta: 'Reclamaciones', href: '/admin/servicios/reclamaciones', icono: 'registros', permisosRequeridos: ['reclamaciones.ver'] },
      { id: 'usuarios', etiqueta: 'Usuarios', href: '/admin/servicios/usuarios', icono: 'personas', permisosRequeridos: ['usuarios.ver'] },
      { id: 'roles', etiqueta: 'Roles y permisos', href: '/admin/servicios/roles', icono: 'seguridad', permisosRequeridos: ['roles.ver'] },
      { id: 'auditoria', etiqueta: 'Auditoría', href: '/admin/servicios/auditoria', icono: 'documentos', permisosRequeridos: ['auditoria.ver'] },
    ],
  },
  {
    id: 'cuenta',
    etiqueta: 'Mi cuenta',
    elementos: [
      { id: 'notificaciones', etiqueta: 'Notificaciones', href: '/admin/servicios/notificaciones', icono: 'avisos' },
      { id: 'perfil', etiqueta: 'Mi perfil', href: '/admin/servicios/perfil', icono: 'personas' },
    ],
  },
];

export default function ServiciosLayout({ children }: { children: ReactNode }) {
  return (
    <PanelShell tono="servicios" titulo="Servicios tecnológicos" navegacion={NAVEGACION}>
      {children}
    </PanelShell>
  );
}
