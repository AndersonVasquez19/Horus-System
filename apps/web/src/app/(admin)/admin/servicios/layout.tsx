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
