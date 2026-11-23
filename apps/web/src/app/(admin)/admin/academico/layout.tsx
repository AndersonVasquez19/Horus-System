import type { ReactNode } from 'react';
import { PanelShell } from '@/components/shared/panel-shell';
import type { ModeloNavegacion } from '@/components/shared/panel-tipos';

// Navegación limpia y sin duplicados de la barra superior (Buscar -> Ctrl+K, Notificaciones -> campana, Perfil -> menú usuario).
const NAVEGACION: ModeloNavegacion = [
  {
    id: 'general',
    elementos: [
      { id: 'inicio', etiqueta: 'Inicio', href: '/admin/academico', icono: 'inicio', coincidencia: 'exacta' },
    ],
  },
  {
    id: 'operacion',
    etiqueta: 'Operación diaria',
    elementos: [
      { id: 'matriculas', etiqueta: 'Matrículas', href: '/admin/academico/matriculas', icono: 'matriculas', permisosRequeridos: ['matriculas.ver'] },
      { id: 'pagos', etiqueta: 'Pagos y deudores', href: '/admin/academico/pagos', icono: 'pagos', permisosRequeridos: ['pagos.ver'] },
      { id: 'certificados', etiqueta: 'Certificados', href: '/admin/academico/certificados', icono: 'certificados', permisosRequeridos: ['certificados.ver'] },
    ],
  },
  {
    id: 'gestion',
    etiqueta: 'Gestión académica',
    elementos: [
      { id: 'estudiantes', etiqueta: 'Estudiantes', href: '/admin/academico/estudiantes', icono: 'personas', permisosRequeridos: ['estudiantes.ver'] },
      { id: 'cursos', etiqueta: 'Cursos', href: '/admin/academico/cursos', icono: 'cursos', permisosRequeridos: ['cursos.ver'] },
      { id: 'convocatorias', etiqueta: 'Ediciones', href: '/admin/academico/convocatorias', icono: 'ediciones', permisosRequeridos: ['convocatorias.ver'] },
      { id: 'reportes', etiqueta: 'Reportes', href: '/admin/academico/reportes', icono: 'reportes', permisosRequeridos: ['reportes.ver'] },
    ],
  },
  {
    id: 'atencion',
    etiqueta: 'Atención y control',
    elementos: [
      { id: 'reclamaciones', etiqueta: 'Reclamaciones', href: '/admin/academico/reclamaciones', icono: 'reclamaciones', permisosRequeridos: ['reclamaciones.ver'] },
      { id: 'aprobaciones', etiqueta: 'Aprobaciones', href: '/admin/academico/aprobaciones', icono: 'aprobaciones', permisosRequeridos: ['aprobaciones.ver'] },
      { id: 'asistente', etiqueta: 'Asistente virtual', href: '/admin/academico/asistente', icono: 'asistente', permisosRequeridos: ['asistente.ver'] },
    ],
  },
  {
    id: 'administracion',
    etiqueta: 'Administración',
    elementos: [
      { id: 'usuarios', etiqueta: 'Usuarios', href: '/admin/academico/usuarios', icono: 'usuarios', permisosRequeridos: ['usuarios.ver'] },
      { id: 'roles', etiqueta: 'Roles y permisos', href: '/admin/academico/roles', icono: 'seguridad', permisosRequeridos: ['roles.ver'] },
      { id: 'auditoria', etiqueta: 'Historial de actividad', href: '/admin/academico/auditoria', icono: 'auditoria', permisosRequeridos: ['auditoria.ver'] },
      { id: 'respaldo', etiqueta: 'Exportar datos', href: '/admin/academico/respaldo', icono: 'respaldo', permisosRequeridos: ['respaldo.ver'] },
    ],
  },
];

export default function AcademicoLayout({ children }: { children: ReactNode }) {
  return (
    <PanelShell tono="academico" titulo="Gestión académica" navegacion={NAVEGACION}>
      {children}
    </PanelShell>
  );
}
