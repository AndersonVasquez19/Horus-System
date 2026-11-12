import type { ReactNode } from 'react';
import { PanelShell } from '@/components/shared/panel-shell';
import type { ModeloNavegacion } from '@/components/shared/panel-tipos';

// Solo módulos implementados. Cada entrada declara el permiso de Laravel que necesita (ayuda visual).
const NAVEGACION: ModeloNavegacion = [
  {
    id: 'general',
    elementos: [{ id: 'inicio', etiqueta: 'Inicio', href: '/admin/academico', icono: 'inicio', coincidencia: 'exacta' }],
  },
  {
    id: 'gestion',
    etiqueta: 'Gestión académica',
    elementos: [
      { id: 'estudiantes', etiqueta: 'Estudiantes', href: '/admin/academico/estudiantes', icono: 'personas', permisosRequeridos: ['estudiantes.ver'] },
      { id: 'cursos', etiqueta: 'Cursos', href: '/admin/academico/cursos', icono: 'cursos', permisosRequeridos: ['cursos.ver'] },
      { id: 'matriculas', etiqueta: 'Matrículas', href: '/admin/academico/matriculas', icono: 'registros', permisosRequeridos: ['matriculas.ver'] },
      { id: 'reportes', etiqueta: 'Reportes', href: '/admin/academico/reportes', icono: 'documentos', permisosRequeridos: ['reportes.ver'] },
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
