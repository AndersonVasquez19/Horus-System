import { Bell, BookOpen, ClipboardList, FileText, LayoutDashboard, ServerCog, Settings, ShieldCheck, Users } from 'lucide-react';

/**
 * Registro de iconos permitidos en la navegación de los paneles.
 *
 * La navegación se declara en layouts de servidor y cruza a un componente de cliente, así que
 * el modelo lleva una clave serializable (`NombreIconoPanel`) y no el componente. Se amplía este
 * registro cuando un módulo real necesite un icono nuevo; las claves son genéricas a propósito.
 */
export const ICONOS_PANEL = {
  inicio: LayoutDashboard,
  personas: Users,
  documentos: FileText,
  avisos: Bell,
  ajustes: Settings,
  cursos: BookOpen,
  registros: ClipboardList,
  servicios: ServerCog,
  seguridad: ShieldCheck,
} as const;

export type NombreIconoPanel = keyof typeof ICONOS_PANEL;
